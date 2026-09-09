<?php

declare(strict_types=1);

use App\Core\BlockVersioning;
use App\Core\Database;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\Page;

test('Версионное сохранение блока выполняется одной транзакцией', function () {
    $service = (string) file_get_contents(APP_ROOT . '/app/Core/BlockVersioning.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    $blockModel = (string) file_get_contents(APP_ROOT . '/app/Models/Block.php');
    $revisionModel = (string) file_get_contents(APP_ROOT . '/app/Models/BlockRevision.php');

    assert_contains('Database::transaction(static function', $service);
    assert_contains('BlockRevision::snapshot(', $service);
    assert_contains('Block::update(', $service);
    assert_same(2, substr_count($controller, 'BlockVersioning::updateWithSnapshot('));
    assert_contains('JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR', $blockModel);
    assert_contains('JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR', $revisionModel);
});

test('Версионное сохранение блока: ошибка обновления откатывает снимок (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM block_revisions');
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'atomic-block-' . bin2hex(random_bytes(4)),
        'title' => 'Atomic block',
        'status' => 'draft',
        'meta_title' => '',
        'meta_description' => '',
        'layout_type' => 'no_sidebar',
    ]);
    $blockId = Block::create($pageId, '', 'text', 'До', ['content' => 'Старое'], 'old-css');

    try {
        $current = Block::findById($blockId);
        assert_true($current !== null);

        BlockVersioning::updateWithSnapshot(
            $current,
            'После',
            ['content' => 'Новое'],
            'new-css',
            null
        );

        $updated = Block::findById($blockId);
        assert_same('После', (string) $updated['title']);
        assert_same('Новое', (string) json_decode((string) $updated['data'], true)['content']);
        assert_same(1, BlockRevision::countForBlock($blockId));
        assert_same('До', (string) BlockRevision::forBlock($blockId)[0]['title']);

        $revisionCount = BlockRevision::countForBlock($blockId);
        $failed = false;
        try {
            BlockVersioning::updateWithSnapshot(
                $updated,
                'Не должно сохраниться',
                ['content' => "\xB1\x31"],
                'broken-css',
                null
            );
        } catch (\JsonException) {
            $failed = true;
        }

        assert_true($failed, 'невалидный UTF-8 должен прервать обновление');
        assert_same($revisionCount, BlockRevision::countForBlock($blockId), 'снимок должен откатиться');

        $afterFailure = Block::findById($blockId);
        assert_same('После', (string) $afterFailure['title']);
        assert_same('Новое', (string) json_decode((string) $afterFailure['data'], true)['content']);
        assert_same('new-css', (string) $afterFailure['custom_css']);
    } finally {
        $pdo->exec('DELETE FROM block_revisions');
        $pdo->exec('DELETE FROM blocks');
        $pdo->exec('DELETE FROM pages');
    }
});
