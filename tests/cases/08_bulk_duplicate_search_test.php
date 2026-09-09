<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\AdminListQuery;
use App\Core\Search;
use App\Models\Page;

// Все проверки этого файла требуют тестовую БД (см. TEST_DB_* в run.php).
function ensure_test_db(): void
{
    $db = getenv('TEST_DB_DATABASE');
    if ($db === false || $db === '') {
        skip_test('TEST_DB_* не заданы');
    }
    if (!Database::isConnected()) {
        Database::init([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => $db,
            'username' => getenv('TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
    }
}

test('Duplicate: копия страницы — черновик, slug -copy, с блоками и переводами', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'dup-' . bin2hex(random_bytes(3));
    $pid = Page::create(['title' => 'Оригинал', 'slug' => $slug, 'meta_title' => null, 'meta_description' => null, 'status' => 'published', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    $pdo->prepare('INSERT INTO blocks (page_id,lang,type,data,sort_order) VALUES (?,?,?,?,0)')->execute([$pid, 'ru', 'text', '{"content":"x"}']);
    $pdo->prepare('INSERT INTO page_translations (page_id,lang,title) VALUES (?,?,?)')->execute([$pid, 'uz', 'Asl']);

    $dupId = Page::duplicate($pid);
    assert_true($dupId !== null);
    $dup = Page::findById($dupId);
    assert_same($slug . '-copy', $dup['slug']);
    assert_same('draft', $dup['status']);
    assert_same(0, (int) $dup['is_home']);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM blocks WHERE page_id=$dupId")->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM page_translations WHERE page_id=$dupId")->fetchColumn());
});

test('Bulk: setStatus меняет статус, filter учитывает его и deleted_at', function () {
    ensure_test_db();
    $slug = 'bulk-' . bin2hex(random_bytes(3));
    $pid = Page::create(['title' => 'Bulk', 'slug' => $slug, 'meta_title' => null, 'meta_description' => null, 'status' => 'published', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    Page::setStatus($pid, 'draft');
    assert_same('draft', Page::findById($pid)['status']);

    $drafts = array_column(Page::filter('draft', null), 'id');
    assert_true(in_array($pid, array_map('intval', $drafts), true));

    Page::delete($pid); // в корзину
    $draftsAfter = array_map('intval', array_column(Page::filter('draft', null), 'id'));
    assert_false(in_array($pid, $draftsAfter, true), 'удалённая страница не должна попадать в фильтр');
});

test('Admin filters: поиск, количество и пагинация страниц согласованы', function () {
    ensure_test_db();
    $marker = 'adminfilter-' . bin2hex(random_bytes(3));
    $ids = [];
    foreach (['Первый', 'Второй'] as $title) {
        $ids[] = Page::create([
            'title' => $title . ' ' . $marker,
            'slug' => $marker . '-' . count($ids),
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'draft',
            'is_home' => 0,
            'layout_type' => 'no_sidebar',
        ]);
    }
    try {
        $filters = AdminListQuery::normalize([
            'q' => $marker,
            'status' => 'draft',
            'sort' => 'title_asc',
            'per_page' => 20,
        ], ['newest', 'title_asc'], 'newest');
        assert_same(2, Page::adminCount($filters));
        assert_same(2, count(Page::adminList($filters)));
    } finally {
        foreach ($ids as $id) {
            Page::forceDelete($id);
        }
    }
});

test('Search: находит по заголовку и slug, ссылки на редактирование', function () {
    ensure_test_db();
    $slug = 'searchme-' . bin2hex(random_bytes(3));
    $pid = Page::create(['title' => 'НайдиМеня', 'slug' => $slug, 'meta_title' => null, 'meta_description' => null, 'status' => 'published', 'is_home' => 0, 'layout_type' => 'no_sidebar']);

    $byTitle = Search::query('НайдиМеня');
    assert_true(count($byTitle) >= 1);
    $urls = array_column($byTitle, 'url');
    assert_true(in_array('/admin/pages/' . $pid . '/edit', $urls, true));

    $bySlug = Search::query('searchme');
    assert_true(count($bySlug) >= 1);

    assert_same([], Search::query('a')); // слишком короткий запрос
});
