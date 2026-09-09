<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\MenuItem;

test('Мега-меню: допустимы только 0 и 2..4, у вложенного пункта — всегда 0', function () {
    assert_same(0, MenuItem::megaColumns(0));
    assert_same(3, MenuItem::megaColumns(3));
    assert_same(2, MenuItem::megaColumns('2'), 'значение из формы приходит строкой');
    assert_same(4, MenuItem::megaColumns(4));

    // За границами диапазона — обычная выпадашка, а не кривая сетка.
    assert_same(0, MenuItem::megaColumns(1));
    assert_same(0, MenuItem::megaColumns(9));
    assert_same(0, MenuItem::megaColumns('мусор'));

    // Раскладку задаёт только верхний уровень.
    assert_same(0, MenuItem::megaColumns(3, 42), 'у дочернего пункта мега-меню быть не может');
});

test('Мега-меню: значение сохраняется и читается (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $id = MenuItem::create([
        'lang' => 'ru', 'title' => 'Мега', 'icon_svg' => null, 'is_divider' => false,
        'url_type' => 'custom', 'url_value' => '/news', 'parent_id' => null,
        'mega_columns' => 3, 'is_active' => true,
    ]);
    assert_same(3, (int) MenuItem::findById($id)['mega_columns']);

    // Дочерний пункт: даже если пришло 3, сохраняем 0.
    $childId = MenuItem::create([
        'lang' => 'ru', 'title' => 'Вложенный', 'icon_svg' => null, 'is_divider' => false,
        'url_type' => 'custom', 'url_value' => '/news', 'parent_id' => $id,
        'mega_columns' => 3, 'is_active' => true,
    ]);
    assert_same(0, (int) MenuItem::findById($childId)['mega_columns']);

    // Переключение обратно на обычное подменю.
    MenuItem::update($id, [
        'lang' => 'ru', 'title' => 'Мега', 'icon_svg' => null, 'is_divider' => false,
        'url_type' => 'custom', 'url_value' => '/news', 'parent_id' => null,
        'mega_columns' => 0, 'is_active' => true,
    ]);
    assert_same(0, (int) MenuItem::findById($id)['mega_columns']);

    $pdo->exec("DELETE FROM menu_items WHERE id IN ({$childId}, {$id})");
});

test('Мега-меню: шапка получает класс и число колонок', function () {
    $header = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    assert_contains('site-menu__item--mega', $header);
    assert_contains('site-submenu--mega', $header);
    assert_contains('site-submenu--cols-', $header);
    assert_not_contains('style="--mega-cols:', $header);

    $css = theme_css();
    // Раскладка включается только на десктопе: на мобильных меню вертикальное.
    assert_contains('.site-submenu--mega', $css);
    assert_contains('repeat(var(--mega-cols, 3), minmax(0, 1fr))', $css);
});

test('Живой поиск: подсказки — отдельный маршрут, отдающий фрагмент', function () {
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_contains("'/search/suggest'", $routes);

    $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Site/SearchController.php');
    assert_contains("Fragment::render('site/_search_suggest'", $controller);
    // Анти-абуз: подсказки летят чаще обычного поиска, но лимит есть.
    assert_contains('site_suggest', $controller);
    assert_contains('RateLimiter::throttle', $controller);

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');
    assert_contains('search-suggest', $js);
    assert_contains("'/suggest?q='", $js);
});

test('Подсказки: пустой результат и список рендерятся без обвязки страницы', function () {
    $empty = \App\Core\View::renderPartial('site/_search_suggest', [
        'results' => [], 'query' => 'ничего', 'allUrl' => '/search?q=x',
    ]);
    assert_contains('search-suggest__empty', $empty);
    assert_not_contains('<html', $empty, 'это фрагмент, а не страница');

    $list = \App\Core\View::renderPartial('site/_search_suggest', [
        'results' => [
            ['type' => 'Новость', 'title' => 'Заголовок <b>с тегом</b>', 'url' => '/news/x', 'excerpt' => ''],
        ],
        'query' => 'заголовок',
        'allUrl' => '/search?q=x',
    ]);
    assert_contains('/news/x', $list);
    assert_contains('search-suggest__all', $list);
    // Заголовки из БД экранируются — разметка не протекает в подсказки.
    assert_not_contains('<b>с тегом</b>', $list);
});
