<?php

declare(strict_types=1);

use App\Core\AdminBrand;
use App\Core\Database;
use App\Core\FrontendAssets;
use App\Core\MediaMetadataSchema;
use App\Models\FileEntry;
use App\Models\NewsImage;

test('миграция метаданных совместима с MySQL 8 и повторным запуском', function (): void {
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_03_media_library_metadata.sql');
    $executableSql = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;

    assert_true(!str_contains($executableSql, 'ADD COLUMN IF NOT EXISTS'), 'не используется MariaDB-синтаксис в исполняемом SQL');
    assert_contains('information_schema.COLUMNS', $migration, 'наличие колонок проверяется перед DDL');
    assert_contains('PREPARE asdr_stmt FROM @asdr_sql', $migration, 'условный DDL выполняется через MySQL PREPARE');

    if (!Database::isConnected()) {
        return;
    }

    $pdo = Database::pdo();
    $pdo->exec($migration);
    $pdo->exec($migration);

    foreach (['alt_text', 'caption', 'description', 'credit', 'focal_x', 'focal_y', 'metadata_updated_at'] as $column) {
        $stmt = $pdo->query('SHOW COLUMNS FROM `files` LIKE ' . $pdo->quote($column));
        assert_true($stmt !== false && $stmt->fetchColumn() !== false, "колонка files.{$column} существует после повторного запуска");
    }
});

test('медиабиблиотека хранит редакционные метаданные отдельно от новости', function (): void {
    if (!Database::isConnected()) {
        return;
    }

    MediaMetadataSchema::ensure();
    $pdo = Database::pdo();

    foreach (['alt_text', 'caption', 'description', 'credit', 'focal_x', 'focal_y', 'metadata_updated_at'] as $column) {
        $stmt = $pdo->query('SHOW COLUMNS FROM `files` LIKE ' . $pdo->quote($column));
        assert_true($stmt !== false && $stmt->fetchColumn() !== false, "колонка files.{$column} существует");
    }

    $suffix = bin2hex(random_bytes(6));
    $stored = $suffix . '.jpg';
    $fileId = FileEntry::create([
        'original_name' => 'audit-' . $stored,
        'stored_name' => $stored,
        'mime_type' => 'image/jpeg',
        'size' => 128,
        'access_type' => 'public',
        'access_token' => null,
        'uploaded_by' => null,
    ]);
    $newsId = 0;

    try {
        $updated = FileEntry::updateMetadata($fileId, [
            'alt_text' => 'Сотрудники на мероприятии',
            'caption' => 'Рабочая встреча',
            'description' => 'Редакционное описание кадра.',
            'credit' => 'Фото: пресс-служба',
            'focal_x' => 48,
            'focal_y' => 36,
        ]);
        assert_true(is_array($updated), 'метаданные сохранены');
        assert_same('Рабочая встреча', (string) $updated['caption']);
        assert_same(48, (int) $updated['focal_x']);

        $url = FileEntry::publicUrl($updated);
        $found = FileEntry::findPublicByUrl($url);
        assert_same($fileId, (int) ($found['id'] ?? 0), 'файл находится только по каноническому URL библиотеки');
        assert_true(FileEntry::findPublicByUrl('https://example.org/' . $stored) === null, 'внешний URL не подменяет файл библиотеки');

        $slug = 'media-meta-test-' . $suffix;
        $insert = $pdo->prepare(
            "INSERT INTO news (title, slug, status, lang, created_at, updated_at)
             VALUES (:title, :slug, 'draft', 'ru', NOW(), NOW())"
        );
        $insert->execute([':title' => 'Media metadata test', ':slug' => $slug]);
        $newsId = (int) $pdo->lastInsertId();

        $imageId = NewsImage::create($newsId, $url, null, 0);
        $stmt = $pdo->prepare('SELECT * FROM news_images WHERE id = :id');
        $stmt->execute([':id' => $imageId]);
        $image = $stmt->fetch();
        assert_same('Сотрудники на мероприятии', (string) $image['alt_text']);
        assert_same('Рабочая встреча', (string) $image['caption']);
        assert_same('Фото: пресс-служба', (string) $image['credit']);
        assert_same(48, (int) $image['focal_x']);
        assert_same(36, (int) $image['focal_y']);
    } finally {
        if ($newsId > 0) {
            $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
        }
        FileEntry::delete($fileId);
    }
});

test('редактор галереи использует единый multiple-picker медиабиблиотеки', function (): void {
    $adminJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $legacyJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-media-metadata.js');
    $legacyCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-media-metadata.css');
    $workflowJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-workflow-fixes.js');
    $loadMoreJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-media-loadmore.js');

    assert_contains("pickMany: function (cb) { open(null, cb, 'image', true); }", $adminJs, 'множественный выбор принадлежит основной медиабиблиотеке');
    assert_contains("var galleryPick = e.target.closest('[data-media-gallery-pick]')", $adminJs, 'галерея вызывает штатный media picker');
    assert_contains("fetch('/admin/files/chunk'", $adminJs, 'загрузка из основного picker сразу попадает в медиабиблиотеку');
    assert_contains("input.name = 'gallery_library[]'", $adminJs, 'в форму новости попадает ссылка на выбранный библиотечный файл');
    assert_not_contains("className = 'gallery-media-modal'", $legacyJs, 'второй gallery picker больше не создаётся');
    assert_not_contains('stopImmediatePropagation()', $legacyJs, 'legacy-слой больше не перехватывает штатный обработчик');
    assert_contains('Legacy compatibility shim', $legacyJs, 'старый URL JS остаётся безопасным для старого кэша');
    assert_contains('Legacy compatibility shim', $legacyCss, 'старый URL CSS остаётся безопасным для старого кэша');
    assert_not_contains('.gallery-media-modal', $legacyCss, 'старые стили второго модального окна физически удалены');
    assert_not_contains('media-client-pager', $workflowJs, 'старая пагинация физически удалена из workflow-слоя');
    assert_not_contains('gallery-media-modal__grid', $loadMoreJs, 'progressive loading обслуживает только основную медиабиблиотеку');
});

test('устаревшие gallery-assets больше не подключаются в свежую админку', function (): void {
    assert_true(in_array('/assets/css/public-content-modes.css', FrontendAssets::styles(), true));

    $head = AdminBrand::styleTag();
    assert_not_contains('admin-media-metadata.css', $head);
    assert_not_contains('admin-media-metadata.js', $head);

    $printCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-content-modes.css');
    assert_contains('body > main.site-content', $printCss);
    assert_contains('main.site-content .layout__sidebar', $printCss);
    assert_contains('.reader-mode-container', $printCss);
});

test('медиабиблиотека владеет файлом до явного удаления', function (): void {
    $cleaner = (string) file_get_contents(APP_ROOT . '/app/Core/MediaCleaner.php');
    assert_contains('SELECT COUNT(*) FROM files WHERE stored_name = :stored', $cleaner);
    assert_contains('max(0, $refs - 1)', (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FileController.php'));
});
