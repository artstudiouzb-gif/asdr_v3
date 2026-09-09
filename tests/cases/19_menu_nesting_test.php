<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\MenuItem;

test('MenuItem::buildTree строит двухуровневое дерево (задача 3)', function () {
    $rows = [
        ['id' => 1, 'parent_id' => null, 'title' => 'Услуги', 'lang' => '', 'is_active' => 1],
        ['id' => 2, 'parent_id' => 1, 'title' => 'Дизайн', 'lang' => '', 'is_active' => 1],
        ['id' => 3, 'parent_id' => 1, 'title' => 'Печать', 'lang' => '', 'is_active' => 1],
        ['id' => 4, 'parent_id' => null, 'title' => 'Контакты', 'lang' => '', 'is_active' => 1],
        // «осиротевший» ребёнок несуществующего родителя — не должен появиться.
        ['id' => 5, 'parent_id' => 99, 'title' => 'Потеряшка', 'lang' => '', 'is_active' => 1],
    ];
    $tree = MenuItem::buildTree($rows);
    assert_same(2, count($tree), 'два пункта верхнего уровня');
    assert_same(1, (int) $tree[0]['id']);
    assert_same(2, count($tree[0]['children']), 'у «Услуги» двое детей');
    assert_same(0, count($tree[1]['children']), 'у «Контакты» детей нет');
});

test('MenuItem сохраняет выбранный язык во внутренних произвольных ссылках', function () {
    $custom = static fn (string $url): array => ['url_type' => 'custom', 'url_value' => $url];

    // Без тестовой БД язык по умолчанию — ru, поэтому uz моделирует
    // неосновную локаль так же, как ru на сайте с основным языком uz.
    assert_same('/uz/projects', MenuItem::resolveUrl($custom('/projects'), 'uz'));
    assert_same('/uz/projects?view=grid#latest', MenuItem::resolveUrl($custom('/projects?view=grid#latest'), 'uz'));
    assert_same('/uz/projects', MenuItem::resolveUrl($custom('/uz/projects'), 'uz'), 'префикс не дублируется');
    assert_same('/uz/projects', MenuItem::resolveUrl($custom('/ru/projects'), 'uz'), 'старый языковой префикс заменяется');

    assert_same('https://example.com/projects', MenuItem::resolveUrl($custom('https://example.com/projects'), 'uz'));
    assert_same('mailto:info@example.com', MenuItem::resolveUrl($custom('mailto:info@example.com'), 'uz'));
    assert_same('#contacts', MenuItem::resolveUrl($custom('#contacts'), 'uz'));
});

test('MenuItem: вложенность, ограничение глубины и reorder (БД, задача 3)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM menu_items');

    $parent = MenuItem::create(['title' => 'Услуги', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/services', 'is_active' => 1]);
    $child = MenuItem::create(['title' => 'Дизайн', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/design', 'parent_id' => $parent, 'is_active' => 1]);
    $other = MenuItem::create(['title' => 'Контакты', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/contacts', 'is_active' => 1]);

    // Ребёнок реально привязан к родителю.
    $row = MenuItem::findById($child);
    assert_same($parent, (int) $row['parent_id']);

    // Валидатор: верхний уровень — ок.
    assert_same(null, MenuItem::validateParent(null, $other, 'ru'));
    // Сам себе родитель — ошибка.
    assert_true(MenuItem::validateParent($other, $other, 'ru') !== null, 'сам себе родитель запрещён');
    // Глубина > 1: сделать $other ребёнком $child (у которого уже есть родитель) — ошибка.
    assert_true(MenuItem::validateParent($child, $other, 'ru') !== null, 'глубина > 1 запрещена');
    // Нельзя вкладывать пункт, у которого есть дети ($parent) — ошибка.
    assert_true(MenuItem::validateParent($other, $parent, 'ru') !== null, 'у пункта есть дети — нельзя вкладывать');

    // reorder: переносим $other под $parent как второго ребёнка.
    MenuItem::reorder([
        ['id' => $parent, 'parent_id' => null, 'sort_order' => 1],
        ['id' => $child, 'parent_id' => $parent, 'sort_order' => 1],
        ['id' => $other, 'parent_id' => $parent, 'sort_order' => 2],
    ]);
    assert_same($parent, (int) MenuItem::findById($other)['parent_id']);

    // reorder с превышением глубины отклоняется целиком и сообщает об ошибке.
    $depthRejected = false;
    try {
        MenuItem::reorder([
            ['id' => $parent, 'parent_id' => $child, 'sort_order' => 1],
        ]);
    } catch (\DomainException) {
        $depthRejected = true;
    }
    assert_true($depthRejected, 'невалидная структура должна вернуть ошибку');
    assert_same(null, MenuItem::findById($parent)['parent_id'], 'глубину нельзя превысить через reorder');

    // Пункты разных языков нельзя объединять в одну ветку.
    $uz = MenuItem::create(['title' => 'Aloqa', 'lang' => 'uz', 'url_type' => 'custom', 'url_value' => '/uz/contact', 'is_active' => 1]);
    $langRejected = false;
    try {
        MenuItem::reorder([
            ['id' => $uz, 'parent_id' => $parent, 'sort_order' => 1],
        ]);
    } catch (\DomainException) {
        $langRejected = true;
    }
    assert_true($langRejected, 'межъязыковое вложение запрещено');
    assert_same(null, MenuItem::findById($uz)['parent_id']);

    // Каскадное удаление: удаляем родителя — дети уходят (ON DELETE CASCADE).
    MenuItem::delete($parent);
    assert_same(null, MenuItem::findById($child), 'ребёнок удалён каскадно');
    MenuItem::delete($uz);

    $pdo->exec('DELETE FROM menu_items');
});
