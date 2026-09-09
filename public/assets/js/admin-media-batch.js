/**
 * Долгие проходы по медиатеке из админки: достройка миниатюр и починка прав.
 *
 * Работа идёт пакетами — сервер обрабатывает столько, сколько успевает за
 * отведённое время, и возвращает курсор. Один длинный запрос на весь каталог
 * хостинг обрывает по таймауту шлюза, и обработка выглядела бы сломанной ровно
 * там, где она нужнее всего: на большой медиатеке.
 *
 * Оба прохода ведёт один бегунок. Второй файл почти той же формы разошёлся бы
 * с первым при первой правке — курсор чинили бы в одном, кнопку в другом.
 */
(function () {
    'use strict';

    function token() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function setup(root) {
        var endpoint = root.getAttribute('data-endpoint') || '';
        var scope = root.getAttribute('data-batch-task') || '';
        var buttons = {
            dry: root.querySelector('[data-batch="dry"]'),
            run: root.querySelector('[data-batch="run"]'),
            stop: root.querySelector('[data-batch="stop"]')
        };
        var progress = document.querySelector('[data-batch-progress="' + scope + '"]');
        var bar = progress ? progress.querySelector('[data-batch-bar]') : null;
        var status = document.querySelector('[data-batch-status="' + scope + '"]');

        var running = false;
        var cancelled = false;

        function say(text) {
            if (!status) return;
            status.hidden = false;
            status.textContent = text;
        }

        function draw(cursor, total) {
            if (!progress || !bar) return;
            progress.hidden = false;
            var percent = total > 0 ? Math.round((cursor / total) * 100) : 0;
            bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }

        function lock(on) {
            running = on;
            if (buttons.dry) buttons.dry.disabled = on;
            if (buttons.run) buttons.run.disabled = on;
            if (buttons.stop) buttons.stop.hidden = !on;
        }

        async function batch(offset, dry) {
            var data = new FormData();
            data.append('csrf_token', token());
            data.append('offset', String(offset));
            data.append('dry', dry ? '1' : '0');

            var response = await fetch(endpoint, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var text = await response.text();
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                throw new Error('Сервер ответил не JSON (HTTP ' + response.status + ').');
            }
            if (!payload || payload.ok !== true) {
                throw new Error((payload && payload.error) || 'Не удалось обработать пакет.');
            }
            return payload;
        }

        function line(scopeName, seen, totals, dry) {
            if (scopeName === 'permissions') {
                return dry
                    ? seen + ' · нужно исправить: ' + totals.planned
                    : seen + ' · исправлено: ' + totals.fixed
                        + (totals.empty ? ' · пустых файлов: ' + totals.empty : '')
                        + (totals.failed ? ' · не удалось: ' + totals.failed : '');
            }
            return dry
                ? seen + ' · без миниатюр: ' + totals.planned + ' · уже готовы: ' + totals.skipped
                : seen + ' · достроено: ' + totals.optimized + ' · уже были: ' + totals.skipped
                    + (totals.failed ? ' · не удалось: ' + totals.failed : '');
        }

        async function loop(dry) {
            if (running) return;
            cancelled = false;
            lock(true);

            var offset = 0;
            var totals = { optimized: 0, planned: 0, skipped: 0, failed: 0, fixed: 0, empty: 0, total: 0 };
            say(dry ? 'Считаю объём работы…' : 'Обрабатываю…');

            try {
                for (;;) {
                    var result = await batch(offset, dry);
                    ['optimized', 'planned', 'skipped', 'failed', 'fixed', 'empty'].forEach(function (key) {
                        totals[key] += result[key] || 0;
                    });
                    totals.total = result.total;
                    offset = result.cursor;
                    draw(offset, result.total);

                    var seen = 'Просмотрено ' + offset + ' из ' + result.total;
                    say(line(scope, seen, totals, dry));

                    if (result.done) break;
                    if (cancelled) {
                        say('Остановлено. ' + seen + '. Повторный запуск продолжит с этого места.');
                        lock(false);
                        return;
                    }
                }
            } catch (error) {
                say('Ошибка: ' + error.message + ' Обработанное сохранено — можно запустить снова.');
                lock(false);
                return;
            }

            draw(1, 1);
            if (scope === 'permissions') {
                say(dry
                    ? (totals.planned > 0
                        ? 'Права надо исправить у ' + totals.planned + ' записей. Нажмите «Починить права».'
                        : 'Права в порядке у всех ' + totals.total + ' записей — делать нечего.')
                    : 'Готово. Исправлено: ' + totals.fixed
                        + (totals.empty ? ' · пустых файлов (права не помогут, нужна повторная загрузка): ' + totals.empty : '')
                        + (totals.failed ? ' · не удалось: ' + totals.failed : '') + '.');
            } else {
                say(dry
                    ? (totals.planned > 0
                        ? 'Без миниатюр: ' + totals.planned + ' из ' + totals.total
                            + '. Нажмите «Достроить миниатюры», чтобы их создать.'
                        : 'Миниатюры есть у всех ' + totals.total + ' фотографий — делать нечего.')
                    : 'Готово. Достроено: ' + totals.optimized + ' · уже были: ' + totals.skipped
                        + (totals.failed ? ' · не удалось: ' + totals.failed : '') + '.');
            }
            lock(false);
        }

        if (buttons.dry) buttons.dry.addEventListener('click', function () { loop(true); });
        if (buttons.run) buttons.run.addEventListener('click', function () { loop(false); });
        if (buttons.stop) buttons.stop.addEventListener('click', function () { cancelled = true; });
    }

    document.querySelectorAll('[data-batch-task]').forEach(setup);
})();
