<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Language;

test('Скрытый язык: посетителю не виден, супер-админу виден, язык по умолчанию не скрывается', function () {
    $active = [
        ['code' => 'uz', 'is_default' => 1, 'is_public' => 0],
        ['code' => 'ru', 'is_default' => 0, 'is_public' => 1],
        ['code' => 'en', 'is_default' => 0, 'is_public' => 0],
        // Строка до миграции: колонки ещё нет — язык открыт, как и был.
        ['code' => 'tr', 'is_default' => 0],
    ];
    $codes = static fn (array $rows): array => array_column($rows, 'code');

    assert_same(['uz', 'ru', 'tr'], $codes(Language::forSite($active, false)));
    assert_same(['uz', 'ru', 'en', 'tr'], $codes(Language::forSite($active, true)));
});

test('Скрытый язык: нет в открытом списке, остаётся в админском (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("DELETE FROM languages WHERE code = 'xx'");
    $id = Language::create(['code' => 'xx', 'name' => 'Тест', 'is_active' => true, 'is_public' => false]);

    assert_true(in_array('xx', Language::activeCodes(), true));
    assert_false(in_array('xx', Language::publicCodes(), true));
    // Без входа в панель предпросмотра нет.
    assert_false(in_array('xx', Language::siteCodes(), true));

    Language::update($id, ['code' => 'xx', 'name' => 'Тест', 'is_active' => true, 'is_public' => true]);
    assert_true(in_array('xx', Language::publicCodes(), true));

    Language::delete($id);
});
