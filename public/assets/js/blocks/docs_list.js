/**
 * «Документы»: поиск, фильтр форматов и полоса страниц — без сетевого
 * запроса. Страницы листает браузер, а не сервер: поиск обязан находить
 * документ на любой странице, а сервер отдал бы только текущую. Без скрипта
 * видны все документы, а полоса страниц не показывается вовсе.
 */
(function () {
    'use strict';

    var AROUND = 1;

    // Номера страниц с многоточиями на разрывах — то же окно, что у
    // серверной полосы (App\Core\Pager::items): края и соседи текущей.
    var pagerItems = function (page, pages) {
        var items = [];
        var last = 0;
        for (var i = 1; i <= pages; i++) {
            if (i !== 1 && i !== pages && Math.abs(i - page) > AROUND) { continue; }
            // Разрыв в одну страницу многоточием не прячем: «1 … 3» не
            // короче «1 2 3», а клик требует лишний.
            if (last && i - last === 2) { items.push(last + 1); }
            else if (last && i - last > 2) { items.push(null); }
            items.push(i);
            last = i;
        }
        return items;
    };

    document.querySelectorAll('[data-document-list]').forEach(function (list) {
        var query = list.querySelector('[data-document-query]');
        var kind = list.querySelector('[data-document-kind-filter]');
        var cards = Array.prototype.slice.call(list.querySelectorAll('[data-document-card]'));
        var empty = list.querySelector('[data-document-empty]');
        var count = list.querySelector('[data-document-count]');
        var pager = list.querySelector('[data-document-pager]');
        var perPage = parseInt(list.getAttribute('data-document-per-page'), 10) || 0;
        if (!cards.length || (!query && !kind && !(pager && perPage))) { return; }

        var page = 1;

        var button = function (label, target, extra) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'listing-pager__item' + (extra || '');
            el.textContent = label;
            el.addEventListener('click', function () {
                page = target;
                apply();
                // Новая страница начинается у верха блока, а не там, где
                // осталась полоса: короткая последняя страница иначе
                // открывалась бы пустым экраном под шапкой.
                var top = list.getBoundingClientRect().top;
                if (top < 0) { list.scrollIntoView({ block: 'start' }); }
                var current = pager.querySelector('[aria-current]');
                if (current) { current.focus({ preventScroll: true }); }
            });
            return el;
        };

        var renderPager = function (pages) {
            pager.textContent = '';
            pager.hidden = pages < 2;
            if (pages < 2) { return; }
            if (page > 1) {
                var prev = button('←', page - 1, ' listing-pager__item--nav');
                prev.setAttribute('aria-label', pager.getAttribute('data-label-prev') || '');
                pager.appendChild(prev);
            }
            pagerItems(page, pages).forEach(function (item) {
                if (item === null) {
                    var gap = document.createElement('span');
                    gap.className = 'listing-pager__gap';
                    gap.setAttribute('aria-hidden', 'true');
                    gap.textContent = '…';
                    pager.appendChild(gap);
                    return;
                }
                var el = button(String(item), item, item === page ? ' is-active' : '');
                if (item === page) { el.setAttribute('aria-current', 'page'); }
                pager.appendChild(el);
            });
            if (page < pages) {
                var next = button('→', page + 1, ' listing-pager__item--nav');
                next.setAttribute('aria-label', pager.getAttribute('data-label-next') || '');
                pager.appendChild(next);
            }
        };

        var apply = function () {
            var needle = query ? query.value.trim().toLocaleLowerCase() : '';
            var selectedKind = kind ? kind.value : '';
            var matched = cards.filter(function (card) {
                var matchesText = needle === '' || (card.getAttribute('data-document-search') || '').indexOf(needle) !== -1;
                var matchesKind = selectedKind === '' || card.getAttribute('data-document-kind') === selectedKind;
                return matchesText && matchesKind;
            });
            var pages = pager && perPage ? Math.max(1, Math.ceil(matched.length / perPage)) : 1;
            page = Math.min(page, pages);
            cards.forEach(function (card) { card.hidden = true; });
            matched.forEach(function (card, index) {
                card.hidden = pages > 1 && Math.floor(index / perPage) + 1 !== page;
            });
            if (pager && perPage) { renderPager(pages); }
            if (empty) { empty.hidden = matched.length !== 0; }
            if (count) { count.textContent = matched.length + ' / ' + cards.length; }
        };
        // Новый запрос показывает свои первые результаты, а не ту страницу,
        // на которой остановился прежний.
        var restart = function () { page = 1; apply(); };
        if (query) { query.addEventListener('input', restart); }
        if (kind) { kind.addEventListener('change', restart); }
        apply();
    });
})();
