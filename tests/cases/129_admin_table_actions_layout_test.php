<?php

declare(strict_types=1);

test('Колонка действий остаётся частью таблицы в списках медиа и новостей', function () {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/admin.css');
    $files = (string) file_get_contents($root . '/app/Views/admin/files/index.php');
    $news = (string) file_get_contents($root . '/app/Views/admin/news/index.php');

    assert_contains('.data-table__action-cell', $css);
    assert_contains('.data-table td.data-table__actions { display: table-cell;', $css);

    foreach ([$files, $news] as $view) {
        assert_contains('<td class="data-table__action-cell">', $view);
        assert_contains('<div class="data-table__actions">', $view);
        assert_contains('<th class="data-table__action-cell">Действия</th>', $view);
    }
});

test('Текстовые действия языков не схлопываются до кнопки-иконки', function () {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/admin.css');
    $view = (string) file_get_contents($root . '/app/Views/admin/languages/index.php');

    assert_contains('.data-table__actions .btn:not(.btn--icon) { width: auto;', $css);
    assert_contains('.data-table td.language-settings-table__actions { width: auto;', $css);
    // Прежде здесь проверялся класс `language-settings-table-wrap`, у которого
    // в admin.css нет ни одного правила: тест закреплял пустышку и при этом
    // спорил с бюджетом мёртвых классов (тест 278). Работу делает
    // `.table-responsive` — его и проверяем.
    assert_contains('<div class="table-responsive">', $view);
    assert_not_contains('language-settings-table-wrap', $view, 'вернулся класс без правил');
    assert_contains('language-settings-table__actions', $view);
    assert_contains("AdminUi::icon('save')", $view);
    assert_contains('>Сохранить</button>', $view);
});
