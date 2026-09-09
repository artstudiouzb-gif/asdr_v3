(function () {
    'use strict';

    var STEP = 15;
    var states = new WeakMap();

    function cardsFor(grid) {
        return Array.prototype.slice.call(grid.querySelectorAll('.media-modal__item'));
    }

    function ensureBar(state) {
        if (state.bar && state.bar.isConnected) { return state.bar; }

        var existing = state.grid.parentElement
            ? state.grid.parentElement.querySelector(':scope > [data-media-loadmore-bar]')
            : null;
        if (existing) {
            state.bar = existing;
            state.status = existing.querySelector('[data-media-loadmore-status]');
            state.button = existing.querySelector('[data-media-load-more]');
            return existing;
        }

        var bar = document.createElement('div');
        bar.className = 'media-loadmore-bar';
        bar.setAttribute('data-media-loadmore-bar', '');

        var status = document.createElement('span');
        status.className = 'media-loadmore-bar__status';
        status.setAttribute('data-media-loadmore-status', '');
        status.setAttribute('aria-live', 'polite');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--secondary media-loadmore-bar__button';
        button.setAttribute('data-media-load-more', '');
        button.textContent = 'Показать ещё';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            state.limit += STEP;
            render(state, false);
        });

        bar.appendChild(status);
        bar.appendChild(button);
        state.grid.insertAdjacentElement('afterend', bar);

        state.bar = bar;
        state.status = status;
        state.button = button;
        return bar;
    }

    function render(state, reset) {
        var grid = state.grid;
        if (!grid || !grid.isConnected) { return; }
        if (reset) { state.limit = STEP; }

        var cards = cardsFor(grid);
        var total = cards.length;
        var shown = Math.min(state.limit, total);

        cards.forEach(function (card, index) {
            var visible = index < shown;
            card.hidden = !visible;
            card.setAttribute('aria-hidden', visible ? 'false' : 'true');

            var image = card.querySelector('img');
            if (image) {
                image.loading = 'lazy';
                image.decoding = 'async';
                if (!visible) {
                    try { image.fetchPriority = 'low'; } catch (error) {}
                }
            }
        });

        var bar = ensureBar(state);
        if (state.status) {
            state.status.textContent = total
                ? 'Показано ' + shown + ' из ' + total
                : 'Нет файлов';
        }
        if (state.button) {
            state.button.hidden = shown >= total;
        }
        bar.hidden = total === 0;
    }

    function schedule(state, reset) {
        if (reset) { state.resetPending = true; }
        if (state.scheduled) { return; }
        state.scheduled = true;
        window.requestAnimationFrame(function () {
            state.scheduled = false;
            var shouldReset = state.resetPending;
            state.resetPending = false;
            render(state, shouldReset);
        });
    }

    function setup(grid) {
        if (!grid || states.has(grid)) { return; }

        var root = grid.closest('.media-modal') || document;
        var state = {
            grid: grid,
            search: root.querySelector('[data-media-search]'),
            limit: STEP,
            bar: null,
            status: null,
            button: null,
            scheduled: false,
            resetPending: false
        };
        states.set(grid, state);

        if (state.search) {
            state.search.addEventListener('input', function () {
                // admin.js перерисовывает найденные карточки. После его рендера
                // всегда начинаем новую выдачу с первых 15 результатов.
                window.setTimeout(function () { schedule(state, true); }, 0);
            });
        }

        new MutationObserver(function (mutations) {
            var meaningful = mutations.some(function (mutation) {
                if (mutation.type !== 'childList') { return false; }
                return mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0;
            });
            if (meaningful) { schedule(state, true); }
        }).observe(grid, { childList: true });

        schedule(state, true);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.media-modal__grid').forEach(setup);
    }

    scan(document);
    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) { return; }
                    if (node.matches && node.matches('.media-modal__grid')) {
                        setup(node);
                    }
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
