<?php

declare(strict_types=1);

test('Медиабиблиотека: в панели массовых действий есть кнопка Выбрать все', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/files/index.php');

    assert_contains('id="btn_bulk_select_all"', $view, 'Кнопка Выбрать все присутствует в HTML');
    assert_contains('Выбрать все', $view, 'Текст кнопки Выбрать все присутствует');
    assert_contains('class="media-bulk-bar__actions"', $view, 'Контейнер действий тулбара присутствует');
});

test('Медиабиблиотека: табличное отображение поддерживает выбор всех элементов', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/files/index.php');

    assert_contains('id="table_bulk_check_all"', $view, 'Чекбокс выбора всех элементов в шапке таблицы');
    assert_contains('class="file-row-check"', $view, 'Чекбоксы в строках таблицы');
    assert_contains('class="files-table__check"', $view, 'Ячейки чекбоксов в таблице');
});

test('Медиабиблиотека: стили панели и таблицы поддерживают множественный выбор', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');

    assert_contains('.media-bulk-bar__actions', $css, 'Стиль для действий панели массовых операций');
    assert_contains('.files-table .files-table__check', $css, 'Стиль скрытия чекбоксов в обычном режиме');
    assert_contains('.files-table.is-bulk-active .files-table__check', $css, 'Стиль показа чекбоксов в режиме массового выбора');
});

test('Медиабиблиотека: скрипт переключает Выбрать все / Снять выбор и синхронизирует состояние', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/files/index.php');

    assert_contains('btnBulkSelectAll', $view, 'Обработчик кнопки Выбрать все в JS');
    assert_contains('Снять выбор', $view, 'Инверсия текста кнопки на Снять выбор');
    assert_contains('tableBulkAll', $view, 'Синхронизация чекбокса шапки таблицы');
    assert_contains('is-bulk-active', $view, 'Переключение класса режима в таблице');
});
