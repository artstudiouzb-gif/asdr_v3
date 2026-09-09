(function () {
    'use strict';

    if (!window.matchMedia
        || !window.matchMedia('(hover: hover) and (pointer: fine)').matches
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var pending = false;
    var lastEvent = null;
    var selector = '.block-newsfeat--mosaic .newsfeat-lead, '
        + '.block-newsfeat--mosaic .newsfeat-mini, '
        + '.block-newsfeat--mosaic .newsfeat-text, '
        + '.newslist-lead, .newslist-grid .relnews-card, .news-column';

    document.addEventListener('mousemove', function (event) {
        lastEvent = event;
        if (pending) { return; }
        pending = true;

        window.requestAnimationFrame(function () {
            pending = false;
            var current = lastEvent;
            if (!current || !current.target || !current.target.closest) { return; }

            var card = current.target.closest(selector);
            if (!card) { return; }

            var rect = card.getBoundingClientRect();
            card.style.setProperty('--mouse-x', (current.clientX - rect.left) + 'px');
            card.style.setProperty('--mouse-y', (current.clientY - rect.top) + 'px');
        });
    }, { passive: true });
})();
