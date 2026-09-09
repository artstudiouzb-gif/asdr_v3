/**
 * Проверка «идёт ли статика через CDN».
 *
 * Работает в браузере владельца, а не на сервере, и это главное решение здесь:
 * запрос, сделанный самим сайтом к собственному адресу, обычно разрешается в
 * себя же и до края сети не доходит вовсе — такая проверка отвечала бы «CDN
 * нет» при работающем CDN. Браузер идёт тем же путём, что и посетитель.
 *
 * Ответ не толкуется в одно слово: печатаются найденные заголовки как есть.
 * Имена у каждого CDN свои, список поставщиков закрытым быть не может, а
 * пересказ на этом месте уже подводил.
 */
(function () {
    'use strict';

    // Заголовок → что он означает. Тип решает вердикт: 'cdn' — ответ пришёл
    // с края сети, 'server' — отвечал сам хостинг (кэш LiteSpeed на CDN не
    // похож, но его постоянно принимают за него), 'proxy' — между нами кто-то
    // есть, но кто именно, заголовок не говорит.
    var KNOWN = {
        'x-hcdn-cache-status': ['cdn', 'Hostinger CDN'],
        'x-hcdn-request-id': ['cdn', 'Hostinger CDN'],
        'cf-cache-status': ['cdn', 'Cloudflare'],
        'cf-ray': ['cdn', 'Cloudflare'],
        'x-bunny-cache-status': ['cdn', 'BunnyCDN'],
        'x-cache': ['cdn', 'кэш на краю сети'],
        'x-cache-status': ['cdn', 'кэш на краю сети'],
        'x-served-by': ['cdn', 'узел раздачи'],
        'x-litespeed-cache': ['server', 'кэш LiteSpeed на самом хостинге — это не CDN'],
        'age': ['proxy', 'сколько секунд ответ пролежал в чужом кэше'],
        'via': ['proxy', 'посредник на пути'],
        'server': ['proxy', 'кто ответил'],
        'cache-control': ['proxy', 'что мы сами разрешили кэшировать']
    };

    function readHeaders(response) {
        var found = [];
        response.headers.forEach(function (value, name) {
            var key = name.toLowerCase();
            if (Object.prototype.hasOwnProperty.call(KNOWN, key)) {
                found.push({ name: key, value: value, kind: KNOWN[key][0], note: KNOWN[key][1] });
            }
        });
        found.sort(function (a, b) { return a.name < b.name ? -1 : 1; });
        return found;
    }

    function verdict(found, second) {
        var vendors = [];
        found.forEach(function (h) {
            if (h.kind === 'cdn' && vendors.indexOf(h.note) === -1) {
                vendors.push(h.note);
            }
        });
        if (vendors.length === 0) {
            var server = found.filter(function (h) { return h.kind === 'server'; });
            return server.length > 0
                ? 'Признаков CDN нет: отвечает сам хостинг (' + server[0].note + ').'
                : 'Признаков CDN в ответе нет. Либо статика идёт напрямую с сервера, либо CDN не '
                    + 'сообщает о себе заголовками — тогда сверьтесь с панелью хостинга.';
        }
        var hit = second.filter(function (h) { return h.name.indexOf('cache-status') !== -1 || h.name === 'x-cache'; });
        var state = hit.length > 0 ? ' Повторный запрос: ' + hit[0].value + '.' : '';
        return 'Статика идёт через ' + vendors.join(', ') + '.' + state;
    }

    function row(header) {
        var line = document.createElement('div');
        line.className = 'cdn-check__row';
        var name = document.createElement('span');
        name.className = 'cdn-check__name';
        name.textContent = header.name;
        var value = document.createElement('span');
        value.className = 'cdn-check__value';
        value.textContent = header.value;
        var note = document.createElement('span');
        note.className = 'cdn-check__note';
        note.textContent = header.note;
        line.appendChild(name);
        line.appendChild(value);
        line.appendChild(note);
        return line;
    }

    function render(box, text, headers) {
        box.textContent = '';
        box.hidden = false;
        var head = document.createElement('p');
        head.className = 'cdn-check__verdict';
        head.textContent = text;
        box.appendChild(head);
        (headers || []).forEach(function (header) {
            box.appendChild(row(header));
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cdn-check]');
        if (!button) {
            return;
        }
        event.preventDefault();

        var url = button.getAttribute('data-cdn-asset') || '';
        var box = document.querySelector('[data-cdn-check-result]');
        if (!url || !box) {
            return;
        }

        button.disabled = true;
        render(box, 'Спрашиваем…', []);

        // Два запроса подряд: первый может прийти мимо кэша (MISS), и по
        // одному ответу нельзя сказать, кэширует край сети или только
        // пропускает через себя. `cache: 'reload'` обходит кэш браузера —
        // иначе второй запрос до сети не дошёл бы вовсе.
        fetch(url, { cache: 'reload', credentials: 'omit' })
            .then(function () { return fetch(url, { cache: 'reload', credentials: 'omit' }); })
            .then(function (response) {
                var found = readHeaders(response);
                render(box, verdict(found, found), found);
            })
            .catch(function (error) {
                render(box, 'Не удалось запросить файл: ' + error.message, []);
            })
            .then(function () { button.disabled = false; });
    });
}());
