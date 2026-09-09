<?php

declare(strict_types=1);

use App\Models\Page;

test('Страницы: родитель, цепочка хлебных крошек и защита от циклов (БД)', function (): void {
    ensure_test_db();
    $suffix = bin2hex(random_bytes(3));

    $rootId = Page::create([
        'title' => 'Раздел ' . $suffix,
        'slug' => 'section-' . $suffix,
        'status' => 'published',
        'is_home' => 0,
        'lang' => 'ru',
    ]);
    $childId = Page::create([
        'title' => 'Подраздел ' . $suffix,
        'slug' => 'subsection-' . $suffix,
        'status' => 'published',
        'is_home' => 0,
        'lang' => 'ru',
        'parent_id' => $rootId,
    ]);
    $leafId = Page::create([
        'title' => 'Материал ' . $suffix,
        'slug' => 'material-' . $suffix,
        'status' => 'published',
        'is_home' => 0,
        'lang' => 'ru',
        'parent_id' => $childId,
    ]);

    $leaf = Page::findById($leafId);
    assert_same($childId, (int) $leaf['parent_id'], 'непосредственный родитель сохранён');

    $trail = Page::ancestorTrail($leaf, 'ru');
    assert_same([$rootId, $childId], array_map(static fn (array $row): int => (int) $row['id'], $trail));

    assert_true(Page::validateParent($rootId, $rootId) !== null, 'страница не может быть родителем себе');
    assert_true(Page::validateParent($leafId, $rootId) !== null, 'цикл через потомка запрещён');

    $cycleRejected = false;
    try {
        $root = Page::findById($rootId);
        Page::update($rootId, array_merge($root, ['parent_id' => $leafId]));
    } catch (\DomainException) {
        $cycleRejected = true;
    }
    assert_true($cycleRejected, 'модель отклоняет цикл даже без административной формы');
    assert_same(null, Page::findById($rootId)['parent_id'], 'невалидная связь не сохранена');

    $optionIds = array_map(static fn (array $option): int => (int) $option['id'], Page::parentOptions($rootId));
    assert_false(in_array($rootId, $optionIds, true), 'сама страница исключена из вариантов');
    assert_false(in_array($childId, $optionIds, true), 'потомок исключён из вариантов');
    assert_false(in_array($leafId, $optionIds, true), 'глубокий потомок исключён из вариантов');

    Page::forceDelete($rootId);
    assert_same(null, Page::findById($childId)['parent_id'], 'при удалении родителя дочерняя страница сохраняется');

    Page::forceDelete($childId);
    Page::forceDelete($leafId);
});

test('Страницы: интерфейс и схема содержат поддержку родителя', function (): void {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_07_29_page_hierarchy.sql');
    $settings = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/_settings_sidebar.php');
    $publicPage = (string) file_get_contents(APP_ROOT . '/app/Views/site/page.php');

    assert_contains('parent_id', $schema);
    assert_contains('ON DELETE SET NULL', $migration);
    assert_contains('name="parent_id"', $settings);
    assert_contains('Page::ancestorTrail', $publicPage);
});
