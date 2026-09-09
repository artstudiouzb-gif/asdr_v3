<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Block;
use App\Models\BlockSnippet;

test('Автокопия: замена блоков обратима — снимок ложится в библиотеку (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('Автокопия', 'autobackup-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pageId = (int) $pdo->lastInsertId();

    Block::create($pageId, 'ru', 'text', 'Первый', ['title' => 'Было', 'content' => 'исходный текст'], '');
    Block::create($pageId, 'ru', 'cta', 'Второй', ['variant' => 'band', 'title' => 'Призыв'], '');

    $name = BlockSnippet::autoBackup($pageId, 'ru', 'Автокопия');
    assert_true($name !== null, 'копия должна создаться');
    assert_contains(BlockSnippet::AUTO_PREFIX, (string) $name);
    assert_contains('(ru)', (string) $name, 'в названии виден язык стека');

    // Заменяем страницу другим набором — прежние блоки удалены.
    BlockSnippet::applyToPage([
        ['type' => 'text', 'title' => 'Новый', 'data' => ['content' => 'другое'], 'custom_css' => '', 'is_active' => 1],
    ], $pageId, 'ru', true);
    assert_same(1, count(Block::forPage($pageId, 'ru')));

    // Возврат: применяем автокопию с заменой.
    $backup = null;
    foreach (BlockSnippet::all() as $row) {
        if ((string) $row['name'] === $name) {
            $backup = BlockSnippet::findById((int) $row['id']);
            break;
        }
    }
    assert_true($backup !== null, 'копия должна находиться в библиотеке');

    $blocks = json_decode((string) $backup['blocks_json'], true);
    BlockSnippet::applyToPage($blocks, $pageId, 'ru', true);

    $restored = Block::forPage($pageId, 'ru');
    assert_same(2, count($restored), 'вернулись оба блока');
    assert_same('text', (string) $restored[0]['type']);
    assert_contains('исходный текст', (string) $restored[0]['data']);
    assert_same('cta', (string) $restored[1]['type']);

    $pdo->exec("DELETE FROM block_snippets WHERE name LIKE '" . BlockSnippet::AUTO_PREFIX . "%'");
    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});

test('Автокопия: пустую страницу не копируем и историю не засоряем (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("DELETE FROM block_snippets WHERE name LIKE '" . BlockSnippet::AUTO_PREFIX . "%'");
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('Пустая', 'autobackup-empty-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pageId = (int) $pdo->lastInsertId();

    // Копировать нечего — записи в библиотеке быть не должно.
    assert_same(null, BlockSnippet::autoBackup($pageId, 'ru', 'Пустая'));

    // Храним только последние 5 автокопий, иначе библиотека превращается в свалку.
    Block::create($pageId, 'ru', 'text', 'Блок', ['content' => 'x'], '');
    for ($i = 0; $i < 8; $i++) {
        BlockSnippet::autoBackup($pageId, 'ru', 'Пустая ' . $i);
    }
    $auto = array_filter(
        BlockSnippet::all(),
        static fn (array $r): bool => str_starts_with((string) $r['name'], BlockSnippet::AUTO_PREFIX)
    );
    assert_same(5, count($auto), 'старые автокопии должны удаляться');

    $pdo->exec("DELETE FROM block_snippets WHERE name LIKE '" . BlockSnippet::AUTO_PREFIX . "%'");
    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});

test('Автокопия делается на обоих путях замены — и шаблоном, и сборкой', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/SnippetController.php');
    // Оба разрушающих действия обязаны снимать копию ДО applyToPage.
    assert_same(2, substr_count($src, 'BlockSnippet::autoBackup('), 'копия нужна и в insert(), и в applyPreset()');
    assert_same(2, substr_count($src, 'Прежние блоки сохранены как шаблон'), 'редактору сообщаем, как вернуть');
});
