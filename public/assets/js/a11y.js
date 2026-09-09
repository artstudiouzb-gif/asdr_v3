/**
 * Панель настроек отображения («для слабовидящих»).
 *
 * Разметку панели отдаёт сервер (перевод, CSP без inline-скриптов), скрипт
 * только меняет состояние: пишет cookie «a11y» в формате query-строки
 * («size=140&contrast=mono») и выставляет data-атрибуты на <html>. Тот же
 * cookie читает PHP, поэтому следующая страница приходит уже настроенной.
 */
(function () {
    'use strict';

    var COOKIE = 'a11y';
    var SIZE_MIN = 100;
    var SIZE_MAX = 200;
    var SIZE_STEP = 10;

    var DEFAULTS = {
        size: '100',
        contrast: 'normal',
        font: 'default',
        spacing: 'normal',
        images: 'on',
        motion: 'on',
        links: 'normal',
        reading: 'off',
        script: 'latn'
    };

    var root = document.documentElement;

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function writeCookie(name, value) {
        // Год: настройка отображения не должна теряться между визитами.
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; samesite=lax';
    }

    function clampSize(value) {
        var size = Math.round(Number(value) / SIZE_STEP) * SIZE_STEP;
        if (!isFinite(size)) { return Number(DEFAULTS.size); }
        return Math.min(SIZE_MAX, Math.max(SIZE_MIN, size));
    }

    function defaultState() {
        var state = {};
        Object.keys(DEFAULTS).forEach(function (key) { state[key] = DEFAULTS[key]; });
        return state;
    }

    function readState() {
        var state = defaultState();
        var raw = readCookie(COOKIE);
        if (raw.indexOf('=') === -1) { return state; }

        new URLSearchParams(raw).forEach(function (value, name) {
            if (Object.prototype.hasOwnProperty.call(DEFAULTS, name)) { state[name] = value; }
        });
        state.size = String(clampSize(state.size));
        state.reading = 'off';

        return state;
    }

    function saveState(state) {
        var params = new URLSearchParams();
        Object.keys(DEFAULTS).forEach(function (key) { params.set(key, state[key]); });
        writeCookie(COOKIE, params.toString());
    }

    function isChanged(state) {
        return Object.keys(DEFAULTS).some(function (key) { return state[key] !== DEFAULTS[key]; });
    }

    /** Системная настройка «уменьшить движение» действует как выключенный
     *  тумблер, пока посетитель не выбрал иное сам. Тот же учёт есть в
     *  theme-init.js — там он срабатывает до первой отрисовки. */
    function effectiveState(state) {
        var copy = {};
        Object.keys(state).forEach(function (key) { copy[key] = state[key]; });
        try {
            if (copy.motion === DEFAULTS.motion && !hasExplicit('motion')
                && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                copy.motion = 'off';
            }
        } catch (error) {}
        return copy;
    }

    function hasExplicit(name) {
        var raw = readCookie(COOKIE);
        return raw.indexOf('=') !== -1 && new URLSearchParams(raw).get(name) !== null;
    }

    /** Прежнее значение «остановки анимаций»: событие шлём только на смену. */
    var previousMotion = root.getAttribute('data-a11y-motion') === 'off' ? 'off' : 'on';

    /** Значения по умолчанию в разметку не пишем: обычная страница — обычная. */
    function applyState(rawState) {
        var state = effectiveState(rawState);
        Object.keys(DEFAULTS).forEach(function (key) {
            if (state[key] === DEFAULTS[key]) {
                root.removeAttribute('data-a11y-' + key);
            } else {
                root.setAttribute('data-a11y-' + key, state[key]);
            }
        });

        var changed = isChanged(state);
        if (changed) {
            root.setAttribute('data-a11y', '1');
        } else {
            root.removeAttribute('data-a11y');
        }

        var toggle = document.querySelector('.a11y-toggle');
        if (toggle) { toggle.classList.toggle('is-active', changed); }

        // Движение останавливают не только CSS-правила: фоновое видео обложки
        // и автопрокрутка каруселей живут в скриптах и узнают о выборе
        // посетителя по этому событию.
        if (previousMotion !== state.motion) {
            previousMotion = state.motion;
            document.dispatchEvent(new CustomEvent('asdr:motion-change', {
                detail: { motion: state.motion },
            }));
        }

        syncControls(state);
    }

    function syncControls(state) {
        document.querySelectorAll('[data-a11y-set]').forEach(function (button) {
            var parts = String(button.getAttribute('data-a11y-set')).split(':');
            button.setAttribute('aria-pressed', state[parts[0]] === parts[1] ? 'true' : 'false');
        });

        document.querySelectorAll('[data-a11y-range="size"]').forEach(function (input) {
            input.value = state.size;
        });
        document.querySelectorAll('[data-a11y-size-value]').forEach(function (output) {
            output.textContent = state.size + '%';
        });

        var themeButton = document.querySelector('[data-a11y-theme]');
        if (themeButton) {
            themeButton.setAttribute('aria-pressed', root.getAttribute('data-theme') === 'dark' ? 'true' : 'false');
        }
    }

    function update(patch) {
        var state = readState();
        Object.keys(patch).forEach(function (key) { state[key] = patch[key]; });
        saveState(state);
        applyState(state);

        // Транслитерацию делает сервер: она меняет текст страницы, а не её вид.
        if (Object.prototype.hasOwnProperty.call(patch, 'script')) {
            window.location.reload();
        }
    }

    // --- Открытие и закрытие панели ---
    function panelParts() {
        return {
            drawer: document.getElementById('a11y-panel'),
            backdrop: document.querySelector('.a11y-backdrop'),
            toggle: document.querySelector('.a11y-toggle')
        };
    }

    function openPanel() {
        var parts = panelParts();
        if (!parts.drawer) { return; }
        parts.drawer.hidden = false;
        if (parts.backdrop) { parts.backdrop.hidden = false; }
        if (parts.toggle) { parts.toggle.setAttribute('aria-expanded', 'true'); }
        var focusable = parts.drawer.querySelector('button, input, select');
        if (focusable) { focusable.focus(); }
    }

    function closePanel() {
        var parts = panelParts();
        if (!parts.drawer) { return; }
        parts.drawer.hidden = true;
        if (parts.backdrop) { parts.backdrop.hidden = true; }
        if (parts.toggle) {
            parts.toggle.setAttribute('aria-expanded', 'false');
            parts.toggle.focus();
        }
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-a11y-open]')) {
            event.preventDefault();
            var drawer = document.getElementById('a11y-panel');
            if (drawer && drawer.hidden) { openPanel(); } else { closePanel(); }
            return;
        }

        if (event.target.closest('[data-a11y-close]')) {
            event.preventDefault();
            closePanel();
            return;
        }

        var setter = event.target.closest('[data-a11y-set]');
        if (setter) {
            event.preventDefault();
            var pair = String(setter.getAttribute('data-a11y-set')).split(':');
            var patch = {};
            // Повторное нажатие на включённое значение выключает его.
            patch[pair[0]] = readState()[pair[0]] === pair[1] ? DEFAULTS[pair[0]] : pair[1];
            update(patch);
            return;
        }

        var stepper = event.target.closest('[data-a11y-step]');
        if (stepper) {
            event.preventDefault();
            var delta = Number(stepper.getAttribute('data-a11y-step')) * SIZE_STEP;
            update({ size: String(clampSize(Number(readState().size) + delta)) });
            return;
        }

        if (event.target.closest('[data-a11y-theme]')) {
            event.preventDefault();
            // Тема — общий переключатель сайта: дублировать её в своих
            // настройках нельзя, иначе получится два источника правды.
            if (typeof window.asdrSetTheme === 'function') {
                window.asdrSetTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            }
            syncControls(readState());
            return;
        }

        if (event.target.closest('[data-a11y-reset]')) {
            event.preventDefault();
            var hadScript = readState().script !== DEFAULTS.script;
            var reset = defaultState();
            saveState(reset);
            applyState(reset);
            if (hadScript) { window.location.reload(); }
        }
    });

    document.addEventListener('input', function (event) {
        var range = event.target.closest('[data-a11y-range="size"]');
        if (!range) { return; }
        update({ size: String(clampSize(range.value)) });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }
        var drawer = document.getElementById('a11y-panel');
        if (drawer && !drawer.hidden) { closePanel(); }
    });

    // Состояние уже применено сервером; синхронизируем контролы панели и
    // страховкой выставляем атрибуты, если страница пришла из кэша браузера.
    applyState(readState());
})();
