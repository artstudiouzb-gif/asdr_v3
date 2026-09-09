<?php

declare(strict_types=1);

use App\Core\AdminListQuery;
use App\Models\Language;

test('AdminListQuery нормализует фильтры и безопасный возврат после POST', function (): void {
    $filters = AdminListQuery::normalize([
        'q' => '  театр  ',
        'status' => 'invalid',
        'sort' => 'wrong',
        'from' => '2026-07-20',
        'to' => '2026-07-10',
        'page' => '-5',
        'per_page' => '999',
    ], ['newest', 'title_asc'], 'newest', true);

    assert_same('театр', $filters['q']);
    assert_same('', $filters['status']);
    assert_same(Language::defaultCode(), $filters['lang']);
    assert_same('newest', $filters['sort']);
    assert_same('2026-07-10', $filters['from']);
    assert_same('2026-07-20', $filters['to']);
    assert_same(1, $filters['page']);
    assert_same(20, $filters['per_page']);

    $allLanguages = AdminListQuery::normalize(
        ['lang' => 'all'],
        ['newest'],
        'newest'
    );
    assert_same('all', $allLanguages['lang']);
    assert_same('all', AdminListQuery::urlParams($allLanguages)['lang']);

    $legacyAllLanguages = AdminListQuery::normalize(
        ['lang' => ''],
        ['newest'],
        'newest'
    );
    assert_same('all', $legacyAllLanguages['lang']);

    $return = AdminListQuery::returnPath('/admin/news', 'q=test&status=draft&evil=https://example.com');
    assert_same('/admin/news?q=test&status=draft', $return);
});

test('конструктор меню использует дерево, быстрый ввод, инспектор и доступное управление', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/menu/index.php');
    $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/MenuController.php');

    assert_true(is_string($view));
    assert_true(is_string($js));
    assert_true(is_string($controller));
    assert_contains('name="parent_id"', $view);
    assert_contains('name="page_slug"', $view);
    assert_contains('data-menu-lang-tab', $view);
    assert_contains('action="/admin/menu/synchronize"', $view);
    assert_contains('name="source_lang"', $view);
    assert_contains('name="target_lang"', $view);
    assert_contains('data-lang=', $view);
    assert_contains('data-menu-builder', $view);
    assert_contains('data-menu-create-kind', $view);
    assert_contains('data-menu-inspector=', $view);
    assert_contains('data-menu-save-status', $view);
    assert_contains('data-menu-preview-mode="desktop"', $view);
    assert_contains('data-menu-preview-mode="mobile"', $view);
    assert_contains('name="direction" value="up"', $view);
    assert_contains('name="direction" value="down"', $view);
    assert_not_contains('<script', $view, 'логика конструктора вынесена из шаблона');
    assert_contains("e.target.closest('.menu-node__handle')", $js);
    assert_contains("option.getAttribute('data-lang') === lang.value", $js);
    assert_contains("openInspector(inspect.getAttribute('data-menu-inspect')", $js);
    assert_contains("setStatus('Сохранение порядка…', 'saving')", $js);
    assert_contains('res.error', $js);
    assert_contains("'badge_pos' => \$badgePos", $controller);
    assert_contains('redirectToMenu', $controller);
});

test('основные списки используют поиск, пагинацию и возврат фильтров', function (): void {
    $news = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/news/index.php');
    $content = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/content/index.php');
    $bulk = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/BulkController.php');

    assert_true(is_string($news));
    assert_true(is_string($content));
    assert_true(is_string($bulk));
    assert_contains('name="q"', $news);
    assert_contains('name="from"', $news);
    assert_contains('name="per_page"', $news);
    assert_contains("renderPartial('admin/layout/pagination'", $news);
    assert_contains('name="return_query"', $news);
    assert_contains('name="q"', $content);
    assert_contains('AdminListQuery::returnPath', $bulk);
});
