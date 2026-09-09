<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\WidgetRenderer;

/**
 * Колонка виджетов у страниц и новостей. Раньше шаблон новости выводил обе
 * колонки сразу — мимо настройки «Макет страницы с виджетами» и мимо nonce,
 * из-за чего скрипт в виджете «Произвольный HTML» блокировался CSP.
 */

test('Виджеты: макет решает, какая колонка выводится', function () {
    assert_same(null, WidgetRenderer::sidebarFor('no_sidebar', 'ru'));
    assert_same(null, WidgetRenderer::sidebarFor(null, 'ru'));
    assert_same(null, WidgetRenderer::sidebarFor('', 'ru'));
});

test('Виджеты: колонка отдаёт позицию и HTML со скриптом под nonce (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $pdo->exec(
        "INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
         VALUES ('right', 'custom_html', 'Баннер', '',
                 '{\"html\":\"<p>WIDGET-RIGHT</p><script>window.__w=1;<\\/script>\"}', 901, 1, NOW())"
    );
    $right = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
         VALUES ('left', 'custom_html', 'Левый', '', '{\"html\":\"<p>WIDGET-LEFT</p>\"}', 902, 1, NOW())"
    );
    $left = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
         VALUES ('right', 'custom_html', 'Выключенный', '', '{\"html\":\"<p>WIDGET-OFF</p>\"}', 903, 0, NOW())"
    );
    $off = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
         VALUES ('right', 'custom_html', 'Только UZ', 'uz', '{\"html\":\"<p>WIDGET-UZ</p>\"}', 904, 1, NOW())"
    );
    $uzOnly = (int) $pdo->lastInsertId();

    try {
        $sidebar = WidgetRenderer::sidebarFor('right_sidebar', 'ru');
        assert_true(is_array($sidebar), 'правая колонка должна собраться');
        assert_same('right', $sidebar['position']);
        assert_contains('WIDGET-RIGHT', $sidebar['html']);
        // Выключенный виджет и виджет другого языка в колонку не попадают.
        assert_not_contains('WIDGET-OFF', $sidebar['html']);
        assert_not_contains('WIDGET-UZ', $sidebar['html']);
        assert_not_contains('WIDGET-LEFT', $sidebar['html'], 'колонки не смешиваются');

        // Скрипт виджета получает nonce, иначе CSP его не выполнит.
        assert_not_contains('<script>', $sidebar['html']);
        assert_contains('<script nonce="', $sidebar['html']);

        $uz = WidgetRenderer::sidebarFor('right_sidebar', 'uz');
        assert_contains('WIDGET-UZ', $uz['html'], 'виджет языка виден на своём языке');
        assert_contains('WIDGET-RIGHT', $uz['html'], 'виджет без языка виден везде');

        $leftSide = WidgetRenderer::sidebarFor('left_sidebar', 'ru');
        assert_same('left', $leftSide['position']);
        assert_contains('WIDGET-LEFT', $leftSide['html']);
    } finally {
        $pdo->exec('DELETE FROM widgets WHERE id IN (' . implode(',', [$right, $left, $off, $uzOnly]) . ')');
    }
});

test('Новость: колонка виджетов берётся из настройки, а не собирается в шаблоне', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/news_show.php');

    // Шаблон больше не ходит в модель за обеими колонками сразу.
    assert_not_contains('Widget::renderSidebar', $view);
    assert_contains("\$sidebarWidgets = trim((string) (\$sidebar['html'] ?? ''))", $view);

    // Форма подписки — запасной вариант для пустой колонки. При макете
    // «Без сайдбара» колонки нет, значит нет и её.
    assert_contains('} elseif ($sidebar !== null) {', $view);

    // Сайт и предпросмотр в админке показывают одну и ту же колонку.
    foreach (['app/Controllers/Site/NewsController.php', 'app/Controllers/Admin/NewsController.php'] as $file) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        assert_contains("WidgetRenderer::sidebarFor(\$news['sidebar_layout'] ?? 'right_sidebar', \$lang)", $src, $file);
    }

    foreach (['app/Controllers/Site/PageController.php', 'app/Controllers/Admin/PageController.php'] as $file) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        assert_contains('WidgetRenderer::sidebarFor($layoutType, $lang)', $src, $file);
    }
});
