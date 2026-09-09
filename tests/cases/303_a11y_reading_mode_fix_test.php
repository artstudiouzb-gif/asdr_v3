<?php

declare(strict_types=1);

/**
 * Тест устранения поломки верстки при режиме чтения из панели для слабовидящих:
 * 1. Из панели (_a11y_panel.php) удален пункт «Режим чтения», который ломал страницу.
 * 2. В стилях a11y.css режим чтения больше не ломает 2-колоночную сетку .newsdetail.
 * 3. В детальной новости news_show.php есть нативный режим чтения (кнопка data-reader-mode-toggle).
 * 4. В a11y.js состояние reading нейтрализуется в 'off'.
 */

test('Панель доступности: тумблер reading исключён из разметки', function () {
    $root = dirname(__DIR__, 2);
    $panel = (string) file_get_contents($root . '/app/Views/site/_a11y_panel.php');

    assert_not_contains("\$toggle('reading'", $panel, 'В панели не должно быть тумблера reading');
    assert_contains("\$toggle('images'", $panel, 'В панели должен остаться тумблер images');
    assert_contains("\$toggle('motion'", $panel, 'В панели должен остаться тумблер motion');
    assert_contains("\$toggle('links'", $panel, 'В панели должен остаться тумблер links');
});

test('Стили a11y: режим чтения не ломает сетку .newsdetail', function () {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/a11y.css');

    assert_contains('html[data-a11y-reading="on"] .newsdetail:not(.newsdetail--premium)', $css);
    assert_contains('display: flex;', $css);
    assert_contains('flex-direction: column;', $css);
    assert_contains('html[data-a11y-reading="on"] .newsdetail-article', $css);
});

test('Новость: присутствует нативный режим чтения data-reader-mode-toggle', function () {
    $root = dirname(__DIR__, 2);
    $view = (string) file_get_contents($root . '/app/Views/site/news_show.php');

    assert_contains('data-reader-mode-toggle', $view);
    assert_contains('id="reader-mode-overlay"', $view);
    assert_contains('data-reader-close', $view);
    assert_contains('data-reader-theme', $view);
    assert_contains('data-reader-font', $view);
});

test('Скрипт a11y: reading нейтрализуется в off', function () {
    $root = dirname(__DIR__, 2);
    $js = (string) file_get_contents($root . '/public/assets/js/a11y.js');

    assert_contains("state.reading = 'off';", $js);
});
