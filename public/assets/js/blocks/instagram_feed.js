/**
 * «Лента Instagram», случайный порядок. Сервер отдаёт всю сохранённую копию и
 * прячет кадры сверх лимита; здесь они перемешиваются, и показываются первые.
 * Выбирает браузер, а не сервер: страница кэшируется общим ключом, и порядок,
 * выбранный при сборке, застыл бы для всех посетителей. Без скрипта видны
 * последние публикации.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-ig-shuffle]').forEach(function (grid) {
        var limit = parseInt(grid.getAttribute('data-ig-shuffle'), 10) || 1;
        var cells = Array.prototype.slice.call(grid.children);
        for (var i = cells.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var swap = cells[i];
            cells[i] = cells[j];
            cells[j] = swap;
        }
        cells.forEach(function (cell, index) {
            cell.hidden = index >= limit;
            grid.appendChild(cell);
        });
    });
})();
