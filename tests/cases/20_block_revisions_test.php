<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\Page;

test('BlockRevision: снимок, ограничение 20 версий и восстановление (БД, группа 5.1)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM block_revisions');
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'rev-test', 'title' => 'Rev', 'status' => 'draft',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $blockId = Block::create($pageId, '', 'text', 'v0', ['content' => 'нулевая'], '');

    // Снимаем 25 ревизий — храниться должны только последние 20 (BlockRevision::KEEP).
    for ($i = 1; $i <= 25; $i++) {
        BlockRevision::snapshot($blockId, 'v' . $i, ['content' => 'версия ' . $i], '', null);
    }
    assert_same(BlockRevision::KEEP, BlockRevision::countForBlock($blockId), 'должно храниться ровно 20 версий');

    $list = BlockRevision::forBlock($blockId);
    assert_same(20, count($list));
    // Новейшая сверху — это v25.
    assert_same('v25', (string) $list[0]['title']);

    // Восстановление: применяем самую старую из хранимых версий к блоку.
    $target = $list[count($list) - 1]; // самая старая из 20
    $data = json_decode((string) $target['data'], true);
    Block::update($blockId, (string) $target['title'], $data, '');
    $block = Block::findById($blockId);
    assert_same((string) $target['title'], (string) $block['title']);
    $restored = json_decode((string) $block['data'], true);
    assert_same($data['content'], $restored['content']);

    // Каскад: удаление блока стирает его ревизии (ON DELETE CASCADE).
    Block::delete($blockId);
    assert_same(0, BlockRevision::countForBlock($blockId));

    $pdo->exec('DELETE FROM pages');
});
