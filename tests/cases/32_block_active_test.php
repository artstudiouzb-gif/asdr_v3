<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Block;

test('Block: отключённый блок скрыт на сайте, но виден в конструкторе (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('BlockToggle', 'block-toggle-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pid = (int) $pdo->lastInsertId();

    $b1 = Block::create($pid, 'ru', 'text', 'A', ['title' => 'A', 'content' => 'a'], '');
    $b2 = Block::create($pid, 'ru', 'text', 'B', ['title' => 'B', 'content' => 'b'], '');

    // По умолчанию блоки активны и выводятся.
    assert_same(2, count(Block::forPageLocalized($pid, 'ru')));

    // Отключаем второй — на сайте остаётся один, в конструкторе — оба.
    Block::setActive($b2, false);
    assert_same(1, count(Block::forPageLocalized($pid, 'ru')));
    assert_same(2, count(Block::forPage($pid, 'ru')));
    assert_same(0, (int) Block::findById($b2)['is_active']);

    // Включаем обратно.
    Block::setActive($b2, true);
    assert_same(2, count(Block::forPageLocalized($pid, 'ru')));

    $pdo->exec("DELETE FROM pages WHERE id = {$pid}");
});

test('Block::childrenOf с activeOnly скрывает отключённые вложенные блоки (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('Cols', 'cols-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pid = (int) $pdo->lastInsertId();

    $cols = Block::create($pid, 'ru', 'columns', 'Колонки', ['columns' => 2, 'gap' => 'medium'], '');
    $c1 = Block::create($pid, 'ru', 'text', 'C1', ['content' => 'c1'], '', $cols, 0);
    $c2 = Block::create($pid, 'ru', 'text', 'C2', ['content' => 'c2'], '', $cols, 1);

    assert_same(2, count(Block::childrenOf($cols, true)));
    Block::setActive($c2, false);
    assert_same(1, count(Block::childrenOf($cols, true)));
    assert_same(2, count(Block::childrenOf($cols, false)));

    $pdo->exec("DELETE FROM pages WHERE id = {$pid}");
});
