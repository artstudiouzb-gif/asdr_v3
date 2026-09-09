<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Block;
use App\Models\BlockSnippet;

test('Шаблон страницы: снимок включает детей колонок и активность (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('TplSrc', 'tpl-src-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pid = (int) $pdo->lastInsertId();

    $hero = Block::create($pid, 'ru', 'hero', 'Шапка', ['title' => 'Привет'], '.x{color:red}');
    $cols = Block::create($pid, 'ru', 'columns', 'Колонки', ['columns' => 2, 'gap' => 'medium'], '');
    Block::create($pid, 'ru', 'text', 'Левый', ['content' => 'L'], '', $cols, 0);
    $right = Block::create($pid, 'ru', 'text', 'Правый', ['content' => 'R'], '', $cols, 1);
    Block::setActive($right, false);
    Block::setActive($hero, false);

    $snap = BlockSnippet::captureFromPage($pid, 'ru');
    assert_same(2, count($snap), 'два блока верхнего уровня');
    assert_same('hero', $snap[0]['type']);
    assert_same(0, $snap[0]['is_active'], 'выключенный hero сохранён выключенным');
    assert_same('columns', $snap[1]['type']);
    assert_same(2, count($snap[1]['children']), 'дети колонок в снимке');
    assert_same(0, $snap[1]['children'][0]['column_index']);
    assert_same(1, $snap[1]['children'][1]['column_index']);
    assert_same(0, $snap[1]['children'][1]['is_active']);
    assert_same('.x{color:red}', $snap[0]['custom_css']);

    $pdo->exec("DELETE FROM pages WHERE id = {$pid}");
});

test('Шаблон страницы: применение append и replace, дети восстанавливаются (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('TplDst', 'tpl-dst-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pid = (int) $pdo->lastInsertId();

    Block::create($pid, 'ru', 'text', 'Старый', ['content' => 'old'], '');

    $tpl = [
        ['type' => 'hero', 'title' => 'Новая шапка', 'data' => ['title' => 'Hi'], 'custom_css' => '', 'is_active' => 1],
        [
            'type' => 'columns', 'title' => null, 'data' => ['columns' => 2], 'custom_css' => '', 'is_active' => 1,
            'children' => [
                ['column_index' => 0, 'type' => 'text', 'title' => 'L', 'data' => ['content' => 'l'], 'custom_css' => '', 'is_active' => 1],
                ['column_index' => 1, 'type' => 'text', 'title' => 'R', 'data' => ['content' => 'r'], 'custom_css' => '', 'is_active' => 0],
            ],
        ],
    ];

    // append: старый блок остаётся, добавлены 2 верхних + 2 детей.
    assert_same(4, BlockSnippet::applyToPage($tpl, $pid, 'ru', false));
    assert_same(3, count(Block::forPage($pid, 'ru')));

    // replace: остаются только блоки шаблона, дети колонок на месте.
    assert_same(4, BlockSnippet::applyToPage($tpl, $pid, 'ru', true));
    $top = Block::forPage($pid, 'ru');
    assert_same(2, count($top), 'после replace — только блоки шаблона');
    assert_same('hero', $top[0]['type']);

    $children = Block::childrenOf((int) $top[1]['id']);
    assert_same(2, count($children));
    assert_same(0, (int) $children[1]['is_active'], 'выключенный ребёнок восстановлен выключенным');
    assert_same(1, (int) $children[1]['column_index']);

    // Старых детей-сирот не осталось (каскад по FK).
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM blocks WHERE page_id = :p');
    $stmt->execute([':p' => $pid]);
    assert_same(4, (int) $stmt->fetchColumn(), 'в БД ровно 4 блока страницы');

    // Битые элементы шаблона пропускаются.
    assert_same(0, BlockSnippet::applyToPage([['no_type' => true], 'мусор'], $pid, 'ru', false));

    $pdo->exec("DELETE FROM pages WHERE id = {$pid}");
});

test('Шаблон страницы: полный цикл сохранить→применить на другую страницу (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('TplA', 'tpl-a-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $src = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('TplB', 'tpl-b-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $dst = (int) $pdo->lastInsertId();

    $cols = Block::create($src, 'ru', 'columns', null, ['columns' => 3], '');
    Block::create($src, 'ru', 'text', 'K1', ['content' => '1'], '', $cols, 0);
    Block::create($src, 'ru', 'text', 'K2', ['content' => '2'], '', $cols, 2);

    $sid = BlockSnippet::create('Цикл', BlockSnippet::captureFromPage($src, 'ru'));
    $row = BlockSnippet::findById($sid);
    $blocks = json_decode((string) $row['blocks_json'], true);
    assert_same(3, BlockSnippet::applyToPage($blocks, $dst, 'ru', true));

    $top = Block::forPage($dst, 'ru');
    assert_same(1, count($top));
    $kids = Block::childrenOf((int) $top[0]['id']);
    assert_same(2, count($kids));
    assert_same(2, (int) $kids[1]['column_index'], 'номер колонки сохранён');
    assert_same('K2', (string) $kids[1]['title']);

    BlockSnippet::delete($sid);
    $pdo->exec("DELETE FROM pages WHERE id = {$src}");
    $pdo->exec("DELETE FROM pages WHERE id = {$dst}");
});
