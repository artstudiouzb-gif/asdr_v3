<?php

declare(strict_types=1);

use App\Core\BlockVersioning;
use App\Core\ConcurrencyException;
use App\Core\Database;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\Page;

test('Редактор блока передаёт версию записи и сохраняет локальный черновик', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    $revisions = (string) file_get_contents(APP_ROOT . '/app/Views/admin/blocks/revisions.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    $model = (string) file_get_contents(APP_ROOT . '/app/Models/Block.php');
    $script = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_07_23_block_locking.sql');

    assert_contains('name="expected_lock_version"', $form);
    assert_contains('name="expected_lock_version"', $revisions);
    assert_contains('data-content-draft="block:', $form);
    assert_contains('catch (ConcurrencyException)', $controller);
    assert_contains('AND lock_version = :expected_lock_version', $model);
    assert_contains('lock_version = lock_version + 1', $model);
    assert_contains("el.name === 'expected_lock_version'", $script);
    assert_contains('ADD COLUMN lock_version', $migration);
});

test('Устаревшее сохранение блока не меняет данные и не оставляет ревизию (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM block_revisions');
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'locked-block-' . bin2hex(random_bytes(4)),
        'title' => 'Locked block',
        'status' => 'draft',
        'meta_title' => '',
        'meta_description' => '',
        'layout_type' => 'no_sidebar',
    ]);
    $blockId = Block::create($pageId, '', 'text', 'Исходный', ['content' => 'Исходный текст'], '');

    try {
        $stale = Block::findById($blockId);
        assert_true($stale !== null);
        assert_same(1, (int) $stale['lock_version']);

        BlockVersioning::updateWithSnapshot(
            $stale,
            'Первая правка',
            ['content' => 'Сохранённый текст'],
            '',
            null,
            1
        );

        $updated = Block::findById($blockId);
        assert_true($updated !== null);
        assert_same(2, (int) $updated['lock_version']);
        assert_same(1, BlockRevision::countForBlock($blockId));

        $conflicted = false;
        try {
            BlockVersioning::updateWithSnapshot(
                $stale,
                'Устаревшая правка',
                ['content' => 'Не должно сохраниться'],
                '',
                null,
                1
            );
        } catch (ConcurrencyException) {
            $conflicted = true;
        }

        assert_true($conflicted, 'устаревшая версия должна вызвать конфликт');
        assert_same(1, BlockRevision::countForBlock($blockId), 'снимок конфликтной правки должен откатиться');

        $afterConflict = Block::findById($blockId);
        assert_same('Первая правка', (string) $afterConflict['title']);
        assert_same('Сохранённый текст', (string) json_decode((string) $afterConflict['data'], true)['content']);
        assert_same(2, (int) $afterConflict['lock_version']);
    } finally {
        $pdo->exec('DELETE FROM block_revisions');
        $pdo->exec('DELETE FROM blocks');
        $pdo->exec('DELETE FROM pages');
    }
});
