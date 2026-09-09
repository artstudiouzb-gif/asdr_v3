<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\ConcurrencyException;
use App\Models\ContentRevision;
use App\Models\Project;

test('content revisions: project snapshot restores the record row', function (): void {
    if (!Database::isConnected()) {
        return;
    }

    $pdo = Database::pdo();
    $slug = 'revision-test-' . bin2hex(random_bytes(4));
    $pdo->prepare(
        "INSERT INTO pages (title, slug, entity_type, `lead`, status, is_featured, sort_order, created_at)
         VALUES ('Версия 1', :slug, 'project', 'Описание 1', 'draft', 0, 1, NOW())"
    )->execute([':slug' => $slug]);
    $id = (int) $pdo->lastInsertId();

    try {
        $revisionId = ContentRevision::capture('project', $id, null);
        assert_true(is_int($revisionId) && $revisionId > 0);

        $pdo->prepare("UPDATE pages SET title = 'Версия 2', `lead` = 'Описание 2' WHERE id = :id")
            ->execute([':id' => $id]);

        $restored = ContentRevision::restore((int) $revisionId, null);
        assert_same('project', $restored['type'] ?? null);

        // Содержимое проекта живёт в блоках со своей историей версий, поэтому
        // снимок записи возвращает паспорт: заголовок, адрес и анонс.
        $project = $pdo->query('SELECT * FROM pages WHERE id = ' . $id)->fetch();
        assert_same('Версия 1', $project['title'] ?? null);
        assert_same('Описание 1', $project['lead'] ?? null);

        assert_false(ContentRevision::isFresh('project', $id, '2000-01-01 00:00:00'));
        assert_true(ContentRevision::isFresh('project', $id, (string) $project['updated_at']));
    } finally {
        $pdo->prepare("DELETE FROM content_revisions WHERE entity_type = 'project' AND entity_id = :id")->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute([':id' => $id]);
    }
});

test('content revisions: снимок, снятый до слияния, восстанавливает анонс из description', function (): void {
    if (!Database::isConnected()) {
        return;
    }

    $pdo = Database::pdo();
    $slug = 'legacy-revision-' . bin2hex(random_bytes(4));
    $pdo->prepare(
        "INSERT INTO pages (title, slug, entity_type, `lead`, status, created_at)
         VALUES ('Новый заголовок', :slug, 'project', 'Новый анонс', 'draft', NOW())"
    )->execute([':slug' => $slug]);
    $id = (int) $pdo->lastInsertId();

    try {
        // Такой снимок оставила прежняя модель: у проекта была своя таблица,
        // и анонс назывался description.
        $snapshot = json_encode([
            'version' => 1,
            'entity' => [
                'title' => 'Старый заголовок',
                'slug' => $slug,
                'description' => 'Старый анонс',
                'cover_image' => null,
                'status' => 'draft',
                'is_featured' => 0,
                'sort_order' => 0,
            ],
            'children' => [],
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO content_revisions (entity_type, entity_id, snapshot, snapshot_hash, created_by, created_at)
             VALUES ('project', :id, :snapshot, :hash, NULL, NOW())"
        )->execute([':id' => $id, ':snapshot' => $snapshot, ':hash' => hash('sha256', (string) $snapshot)]);
        $revisionId = (int) $pdo->lastInsertId();

        $restored = ContentRevision::restore($revisionId, null);
        assert_same('project', $restored['type'] ?? null);

        $row = $pdo->query('SELECT title, `lead` FROM pages WHERE id = ' . $id)->fetch();
        assert_same('Старый заголовок', $row['title'] ?? null);
        assert_same('Старый анонс', $row['lead'] ?? null, 'анонс восстановлен из старого имени колонки');
    } finally {
        $pdo->prepare("DELETE FROM content_revisions WHERE entity_type = 'project' AND entity_id = :id")->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute([':id' => $id]);
    }
});

test('content revision UI exposes history links and local draft safeguards', function (): void {
    $root = dirname(__DIR__, 2);
    $pageForm = (string) file_get_contents($root . '/app/Views/admin/pages/form.php');
    $newsForm = (string) file_get_contents($root . '/app/Views/admin/news/form.php');
    $projectForm = (string) file_get_contents($root . '/app/Views/admin/projects/form.php');
    $adminJs = (string) file_get_contents($root . '/public/assets/js/admin.js');

    assert_contains('/admin/revisions/page/', $pageForm);
    assert_contains('/admin/revisions/news/', $newsForm);
    assert_contains('/admin/revisions/project/', $projectForm);
    assert_contains('expected_updated_at', $pageForm);
    assert_contains('expected_lock_version', $pageForm);
    assert_contains('data-content-draft', $newsForm);
    assert_contains('artstudio:draft:', $adminJs);
    assert_contains('draft_saved', $adminJs);
    assert_contains('beforeunload', $adminJs);
});

test('content save: stale lock_version rolls back parent and children', function (): void {
    if (!Database::isConnected()) {
        return;
    }
    $pdo = Database::pdo();
    $id = Project::create([
        'title' => 'CAS original', 'slug' => 'cas-' . bin2hex(random_bytes(4)),
        'description' => null, 'cover_image' => null, 'status' => 'draft',
        'is_featured' => false, 'sort_order' => 0,
    ]);
    $version = (int) Project::findById($id)['lock_version'];
    $data = [
        'title' => 'CAS first', 'slug' => Project::findById($id)['slug'],
        'description' => null, 'cover_image' => null, 'status' => 'draft',
        'is_featured' => false, 'sort_order' => 0,
    ];
    try {
        Project::update($id, $data, $version);
        $failed = false;
        try {
            Database::transaction(static function (\PDO $_pdo) use ($id, $data, $version): void {
                Project::update($id, array_merge($data, ['title' => 'CAS stale']), $version);
            });
        } catch (ConcurrencyException) {
            $failed = true;
        }
        assert_true($failed, 'устаревшее сохранение отклонено');
        assert_same('CAS first', Project::findById($id)['title']);
    } finally {
        Project::forceDelete($id);
    }
});
