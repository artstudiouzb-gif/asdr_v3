/**
 * Якорная навигация: подсветка раздела, который сейчас на экране, и высота
 * шапки для закреплённой полосы. Ссылки работают и без скрипта — он только
 * показывает, где посетитель находится.
 */
(function () {
    'use strict';

    var navs = document.querySelectorAll('[data-anchor-nav]');
    if (!navs.length) { return; }

    // Закреплённая полоса должна стоять ровно под шапкой: у макетов разная
    // высота, поэтому меряем её, а не подбираем константу.
    function syncOffset() {
        var header = document.querySelector('header.site-header');
        var sticky = header && getComputedStyle(header).position === 'sticky';
        var top = sticky ? Math.round(header.getBoundingClientRect().height) : 0;
        document.documentElement.style.setProperty('--anchornav-top', top + 'px');
    }
    syncOffset();
    window.addEventListener('resize', syncOffset, { passive: true });

    navs.forEach(function (nav) {
        var links = [].slice.call(nav.querySelectorAll('.block-anchornav__link'));
        var targets = [];
        links.forEach(function (link) {
            var href = link.getAttribute('href') || '';
            if (href.charAt(0) !== '#' || href.length < 2) { return; }
            var section = document.getElementById(href.slice(1));
            if (section) { targets.push({ link: link, section: section }); }
        });
        if (!targets.length || !('IntersectionObserver' in window)) { return; }

        var visible = new Set();
        var mark = function () {
            var current = null;
            targets.forEach(function (item) {
                if (visible.has(item.section) && current === null) { current = item; }
            });
            targets.forEach(function (item) {
                var active = item === current;
                item.link.classList.toggle('is-active', active);
                if (active) {
                    item.link.setAttribute('aria-current', 'true');
                } else {
                    item.link.removeAttribute('aria-current');
                }
            });
        };

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { visible.add(entry.target); } else { visible.delete(entry.target); }
            });
            mark();
        }, { rootMargin: '-25% 0px -60% 0px', threshold: 0 });

        targets.forEach(function (item) { observer.observe(item.section); });
    });
})();
