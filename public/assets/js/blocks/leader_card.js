(function () {
    'use strict';

    /**
     * Карточка руководителя: превращает якорные ссылки в настоящие вкладки.
     *
     * Разметка приходит с сервера уже рабочей — все разделы видны, ссылки
     * прокручивают к ним. Скрипт лишь надстраивает поведение: расставляет
     * роли ARIA (в статике их объявлять нельзя — обещали бы переключение,
     * которого без JS не будет), скрывает неактивные панели и включает
     * управление стрелками, как того ждёт паттерн вкладок.
     */
    var init = function (root) {
        var tablist = root.querySelector('[data-leader-tablist]');
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-leader-tab]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-leader-panel]'));
        if (!tablist || tabs.length < 2 || panels.length < 2) { return; }

        root.classList.add('leader-card--tabbed');
        tablist.setAttribute('role', 'tablist');

        var select = function (key, focusTab) {
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-leader-tab') === key;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                // Из последовательности табуляции выпадают неактивные вкладки:
                // внутри группы переключение идёт стрелками.
                tab.setAttribute('tabindex', on ? '0' : '-1');
                if (on && focusTab) { tab.focus(); }
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-leader-panel') !== key;
            });
        };

        tabs.forEach(function (tab, index) {
            var key = tab.getAttribute('data-leader-tab');
            var panel = root.querySelector('[data-leader-panel="' + key + '"]');
            if (!panel) { return; }

            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            if (!tab.id) { tab.id = panel.id + '-tab'; }
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);

            tab.addEventListener('click', function (event) {
                event.preventDefault();
                select(key, false);
            });

            tab.addEventListener('keydown', function (event) {
                var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);
                if (step === 0) { return; }
                event.preventDefault();
                var next = tabs[(index + step + tabs.length) % tabs.length];
                select(next.getAttribute('data-leader-tab'), true);
            });
        });

        select(tabs[0].getAttribute('data-leader-tab'), false);

        // Ссылка вида /страница#leader-12-bio должна открыть нужную вкладку,
        // иначе поделиться разделом карточки невозможно.
        var fromHash = function () {
            var hash = window.location.hash.replace('#', '');
            if (!hash) { return; }
            var panel = root.querySelector('[data-leader-panel]#' + (window.CSS && CSS.escape ? CSS.escape(hash) : hash));
            if (panel) { select(panel.getAttribute('data-leader-panel'), false); }
        };
        fromHash();
        window.addEventListener('hashchange', fromHash);
    };

    var boot = function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-leader-card]'), init);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
