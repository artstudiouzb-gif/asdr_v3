/**
 * Отправка Core Web Vitals с реальных посетителей.
 *
 * Зачем: лабораторные замеры (Lighthouse на стенде) не показывают INP вовсе,
 * а LCP на дев-сервере упирается в сам сервер. Поле — единственный источник
 * правды по отзывчивости.
 *
 * Что уходит: имя метрики, значение, оценка, крупный срез (тип страницы,
 * мобильный/десктоп, язык). Ни адреса, ни идентификатора посетителя, ни
 * User-Agent — на госсайте это были бы персональные данные, а ещё один
 * cookie сломал бы общий кеш.
 *
 * Библиотека webVitals подключается отдельным файлом из /assets/vendor.
 */
(function () {
    'use strict';

    var config = document.getElementById('web-vitals-config');
    if (!config || !window.webVitals || !navigator.sendBeacon) {
        return;
    }

    var settings;
    try {
        settings = JSON.parse(config.textContent || '{}');
    } catch (error) {
        return;
    }

    var endpoint = String(settings.endpoint || '');
    if (endpoint === '') {
        return;
    }

    // Доля посетителей, с которых собираем. На небольшом сайте нужна вся
    // выборка, на нагруженном — хватит части: 75-й перцентиль устойчив.
    var rate = Number(settings.rate);
    if (!isFinite(rate) || rate <= 0) { rate = 0; }
    if (rate < 1 && Math.random() >= rate) {
        return;
    }

    var context = {
        page: String(settings.page || 'other'),
        lang: String(document.documentElement.getAttribute('lang') || ''),
        // Граница та же, что у мобильной раскладки сайта (см. шкалу
        // брейкпоинтов в CLAUDE.md), чтобы срез совпадал с вёрсткой.
        device: window.innerWidth <= 720 ? 'mobile' : 'desktop'
    };

    var queue = [];

    function collect(metric) {
        queue.push({
            metric: metric.name,
            value: Math.round(metric.value * 1000) / 1000,
            rating: metric.rating || ''
        });
    }

    function flush() {
        if (queue.length === 0) {
            return;
        }
        var payload = JSON.stringify({
            page: context.page,
            lang: context.lang,
            device: context.device,
            metrics: queue.splice(0, queue.length)
        });
        try {
            // sendBeacon переживает закрытие вкладки; fetch с keepalive — нет
            // гарантии, а обычный fetch браузер отменит.
            navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
        } catch (error) {
            // Сбор метрик не должен ломать страницу ни при каких условиях.
        }
    }

    ['onLCP', 'onINP', 'onCLS', 'onTTFB', 'onFCP'].forEach(function (name) {
        if (typeof window.webVitals[name] === 'function') {
            window.webVitals[name](collect);
        }
    });

    // Отправляем, когда вкладку скрывают: к этому моменту INP и CLS уже
    // окончательные, а событие приходит и при закрытии, и при уходе в фон.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });
    // pagehide нужен для Safari, где visibilitychange при выгрузке не всегда
    // успевает сработать.
    window.addEventListener('pagehide', flush);
})();
