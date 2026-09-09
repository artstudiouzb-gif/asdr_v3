(function () {
    'use strict';

    /**
     * Блок «Вкладки»: превращает список ссылок в настоящие вкладки.
     *
     * С сервера разметка приходит рабочей — панели идут подряд со своими
     * заголовками, вкладки прокручивают к ним. Скрипт надстраивает поведение:
     * прячет неактивные панели, расставляет роли ARIA (объявлять их в статике
     * нельзя — обещали бы переключение, которого без скрипта не будет) и
     * включает управление стрелками, как того ждёт паттерн вкладок.
     */
    var init = function (root) {
        var list = root.querySelector('[data-tabs-list]');
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tabs-tab]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-tabs-panel]'));
        if (!list || tabs.length < 2 || panels.length < 2) { return; }

        root.classList.add('is-enhanced');
        list.setAttribute('role', 'tablist');

        var select = function (key, focusTab) {
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-tabs-tab') === key;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                // Неактивные вкладки выпадают из последовательности табуляции:
                // внутри группы переключение идёт стрелками.
                tab.setAttribute('tabindex', on ? '0' : '-1');
                if (on && focusTab) { tab.focus(); }
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-tabs-panel') !== key;
            });
        };

        tabs.forEach(function (tab, index) {
            var key = tab.getAttribute('data-tabs-tab');
            var panel = root.querySelector('[data-tabs-panel="' + key + '"]');
            if (!panel) { return; }

            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            if (!tab.id) { tab.id = panel.id + '-tab'; }
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
            // Панель со скрытым заголовком остаётся достижимой с клавиатуры:
            // иначе до содержимого вкладки не добраться без мыши.
            panel.setAttribute('tabindex', '0');

            tab.addEventListener('click', function (event) {
                event.preventDefault();
                select(key, false);
            });

            tab.addEventListener('keydown', function (event) {
                var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);
                if (step === 0) { return; }
                event.preventDefault();
                var next = tabs[(index + step + tabs.length) % tabs.length];
                select(next.getAttribute('data-tabs-tab'), true);
            });
        });

        select(tabs[0].getAttribute('data-tabs-tab'), false);

        autoplay(root, tabs, select);

        // Ссылка вида /страница#block-12-tab-2 обязана открыть нужную вкладку,
        // иначе поделиться разделом страницы невозможно.
        var fromHash = function () {
            var hash = window.location.hash.replace('#', '');
            if (!hash) { return; }
            var panel = root.querySelector('[data-tabs-panel]#' + (window.CSS && CSS.escape ? CSS.escape(hash) : hash));
            if (panel) { select(panel.getAttribute('data-tabs-panel'), false); }
        };
        fromHash();
        window.addEventListener('hashchange', fromHash);
    };

    /**
     * Автоматическое переключение вкладок с отсчётом на активной.
     *
     * Отсчёт считает скрипт и отдаёт долю в переменную --cms-tabs-progress —
     * так же, как полоса обложки. CSS-анимацией это не сделать: её пришлось бы
     * перезапускать на каждой смене вкладки, а длительность приходит
     * настройкой.
     *
     * Выбор посетителя главнее автоматики: как только вкладку переключили
     * руками, автопоказ прекращается насовсем — иначе страница уводила бы
     * читателя с того, что он открыл сам.
     */
    var autoplay = function (root, tabs, select) {
        var interval = parseInt(root.getAttribute('data-tabs-autoplay'), 10) || 0;
        if (!interval || tabs.length < 2) { return; }

        var reduceMotion = function () {
            return typeof window.asdrReduceMotion === 'function' && window.asdrReduceMotion();
        };
        var stoppedByUser = false;
        var paused = false;
        var startedAt = 0;
        var frame = null;

        var activeIndex = function () {
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].classList.contains('is-active')) { return i; }
            }
            return 0;
        };

        var setProgress = function (value) {
            tabs.forEach(function (tab, index) {
                var bar = tab.querySelector('[data-tabs-progress]');
                if (!bar) { return; }
                bar.style.setProperty('--cms-tabs-progress', index === activeIndex() ? String(value) : '0');
            });
        };

        var stop = function () {
            if (frame) { window.cancelAnimationFrame(frame); frame = null; }
            setProgress(0);
        };

        var tick = function () {
            if (stoppedByUser || paused || reduceMotion()) { stop(); return; }
            var elapsed = (Date.now() - startedAt) / (interval * 1000);
            if (elapsed >= 1) {
                var next = tabs[(activeIndex() + 1) % tabs.length];
                // Фокус не перехватываем: он мог бы увести читателя из текста
                // вкладки, которую он в этот момент читает.
                select(next.getAttribute('data-tabs-tab'), false);
                startedAt = Date.now();
                elapsed = 0;
            }
            setProgress(elapsed);
            frame = window.requestAnimationFrame(tick);
        };

        var start = function () {
            stop();
            if (stoppedByUser || reduceMotion()) { return; }
            startedAt = Date.now();
            frame = window.requestAnimationFrame(tick);
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () { stoppedByUser = true; stop(); });
            tab.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                    stoppedByUser = true;
                    stop();
                }
            });
        });

        root.addEventListener('mouseenter', function () { paused = true; });
        root.addEventListener('mouseleave', function () { paused = false; start(); });
        root.addEventListener('focusin', function () { paused = true; });
        root.addEventListener('focusout', function () {
            if (!root.contains(document.activeElement)) { paused = false; start(); }
        });
        // Настройку «меньше движения» переключают на лету — ответ не кэшируем.
        window.addEventListener('asdr:motion-change', function () {
            if (reduceMotion()) { stop(); } else { start(); }
        });

        start();
    };

    var boot = function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-tabs]'), init);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
