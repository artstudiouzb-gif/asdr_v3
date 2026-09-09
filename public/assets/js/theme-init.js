(function () {
    'use strict';

    var root = document.documentElement;
    // Серверная настройка «Тема по умолчанию для посетителей» (light|dark|auto).
    var base = root.getAttribute('data-theme') || 'light';
    root.setAttribute('data-theme-base', base);

    try {
        var theme = localStorage.getItem('theme');
        var savedBase = localStorage.getItem('theme-base');

        // Выбор посетителя действует только для той серверной настройки, при
        // которой он был сделан. Иначе смена темы в админке не доходила бы до
        // тех, кто когда-то нажимал переключатель: у них навсегда оставалась
        // прежняя тема.
        if (theme && savedBase === base) {
            root.setAttribute('data-theme', theme);
        } else if (theme) {
            localStorage.removeItem('theme');
            localStorage.removeItem('theme-base');
        }
    } catch (error) {
        // Storage can be unavailable in privacy modes; the server default remains valid.
    }

    // Настройки отображения из cookie «a11y». Сервер печатает те же атрибуты
    // при рендере, но общий ответ может прийти из CDN или кэша браузера — без
    // атрибутов либо с чужими. Этот скрипт синхронный и стоит в <head>, так
    // что поправка происходит до первой отрисовки, без мигания «обычная →
    // крупная». Формат cookie — query-строка, тот же, что читают PHP
    // (A11ySettings) и панель (a11y.js).
    //
    // Письменность (script=cyrl) здесь НЕ обрабатывается: узбекская кириллица
    // получается транслитерацией готовой страницы на сервере, поэтому такие
    // ответы помечаются как зависящие от cookie (PublicResponseCache).
    var A11Y_DEFAULTS = {
        size: '100', contrast: 'normal', font: 'default', spacing: 'normal',
        images: 'on', motion: 'on', links: 'normal', reading: 'off'
    };

    try {
        var match = document.cookie.match(/(?:^|; )a11y=([^;]*)/);
        var state = {};
        if (match) {
            decodeURIComponent(match[1]).split('&').forEach(function (pair) {
                var eq = pair.indexOf('=');
                if (eq < 1) { return; }
                var name = decodeURIComponent(pair.slice(0, eq));
                if (Object.prototype.hasOwnProperty.call(A11Y_DEFAULTS, name)) {
                    state[name] = decodeURIComponent(pair.slice(eq + 1));
                }
            });
        }

        // Системная просьба «уменьшить движение» включает ту же настройку, что и
        // тумблер в панели: у неё в CSS уже есть полный сброс анимаций
        // (html[data-a11y-motion="off"] *). Отдельный @media-блок такой
        // специфичности не даёт и проигрывает правилам с !important.
        // Явный выбор посетителя важнее системного: его не перебиваем.
        try {
            if (state.motion === undefined
                && window.matchMedia
                && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                state.motion = 'off';
            }
        } catch (error) { /* matchMedia может быть недоступен */ }

        var changed = false;
        Object.keys(A11Y_DEFAULTS).forEach(function (key) {
            var value = state[key];
            if (value === undefined || value === A11Y_DEFAULTS[key]) {
                root.removeAttribute('data-a11y-' + key);
            } else {
                root.setAttribute('data-a11y-' + key, value);
                changed = true;
            }
        });
        // data-a11y ставим, только если что-то отличается от умолчаний, —
        // ровно как A11ySettings::htmlAttributes() на сервере.
        if (changed) {
            root.setAttribute('data-a11y', '1');
        } else if (!root.hasAttribute('data-a11y-script')) {
            root.removeAttribute('data-a11y');
        }
    } catch (error) {
        // Cookie недоступны — остаётся то, что напечатал сервер.
    }
})();

/**
 * Признак «посетитель просил меньше движения».
 *
 * Источников два: системная настройка браузера и тумблер «остановка анимаций»
 * в панели настроек отображения (атрибут data-a11y-motion="off"). CSS гасит
 * только animation и transition — видео и таймеры автопрокрутки о нём не
 * знают, поэтому скрипты спрашивают этот помощник, а не медиазапрос.
 *
 * Проверяется при каждом вызове: настройку меняют на лету, и закэшированное
 * значение оставило бы фон крутиться после нажатия тумблера.
 */
window.asdrReduceMotion = function () {
    try {
        if (document.documentElement.getAttribute('data-a11y-motion') === 'off') {
            return true;
        }

        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (error) {
        return false;
    }
};
