<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Slug;
use App\Core\Logger;
use App\Core\Translations;

/**
 * Фотоальбомы: галереи изображений с обложкой. Изображения хранятся ссылками
 * на файлы медиабиблиотеки (photo_album_images, каскадное удаление по FK).
 */
final class PhotoAlbum
{
    /** @return array<int, array<string, mixed>> */
    public static function all(bool $publishedOnly = false, ?string $lang = null): array
    {
        $sql = 'SELECT a.*, (SELECT COUNT(*) FROM photo_album_images i WHERE i.album_id = a.id) AS images_count
                FROM photo_albums a';
        if ($publishedOnly) {
            $sql .= ' WHERE a.is_published = 1';
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC';

        $rows = Database::pdo()->query($sql)->fetchAll();

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM photo_albums WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findPublishedBySlug(string $slug, ?string $lang = null): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM photo_albums WHERE slug = :slug AND is_published = 1 LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ($lang === null || $lang === Language::defaultCode()) {
            return $row;
        }

        return self::localize($row, $lang);
    }

    /**
     * Накладывает перевод указанного языка на базовую строку. Пустые поля
     * перевода откатываются к значению основного языка (graceful fallback).
     */
    public static function localize(array $row, string $lang): array
    {
        return self::applyTranslation($row, PhotoAlbumTranslation::find((int) $row['id'], $lang));
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private static function localizeRows(array $rows, string $lang): array
    {
        $translations = PhotoAlbumTranslation::forAlbumIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $lang
        );
        return array_map(
            static fn (array $row): array => self::applyTranslation($row, $translations[(int) $row['id']] ?? null),
            $rows
        );
    }

    private static function applyTranslation(array $row, ?array $translation): array
    {
        return Translations::overlayFields($row, $translation, ['title', 'description']);
    }

    /**
     * Языки контента для набора альбомов одним запросом (без N+1).
     * Контент на языке = непустой перевод заголовка или описания.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    /**
     * Языки контента для набора альбомов одним запросом (без N+1).
     * Разбор — общий для всех сущностей механизма А: полсотни строк, которые
     * повторялись здесь дословно, живут в `Translations::availableLangs()`.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    public static function availableLangsForIds(array $ids): array
    {
        return Translations::availableLangs('photo_albums', $ids, ['title', 'description']);
    }

    /** Создаёт альбом; slug — из названия, при коллизии добавляется суффикс. */
    public static function create(string $title, string $description = '', string $coverUrl = '', bool $published = true): ?int
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }
        $base = Slug::make($title) ?: 'album';
        $slug = $base;
        $n = 2;
        while (self::slugExists($slug)) {
            $slug = $base . '-' . $n++;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO photo_albums (title, slug, description, cover_url, is_published, created_at)
             VALUES (:t, :s, :d, :c, :p, NOW())'
        );
        $stmt->execute([
            ':t' => mb_substr($title, 0, 255),
            ':s' => mb_substr($slug, 0, 255),
            ':d' => trim($description),
            ':c' => mb_substr(trim($coverUrl), 0, 500),
            ':p' => $published ? 1 : 0,
        ]);
        self::bustPageCache();

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, string $title, string $description, string $coverUrl, bool $published, bool $featured = false): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE photo_albums SET title = :t, description = :d, cover_url = :c, is_published = :p, is_featured = :f WHERE id = :id'
        );
        $stmt->execute([
            ':t' => mb_substr(trim($title), 0, 255),
            ':d' => trim($description),
            ':c' => mb_substr(trim($coverUrl), 0, 500),
            ':p' => $published ? 1 : 0,
            ':f' => $featured ? 1 : 0,
            ':id' => $id,
        ]);
        self::bustPageCache();
    }

    /**
     * Альбомы для блока «Медиа» на главной: только помеченные «показать на
     * главном». Если ни один не отмечен — откат на последние опубликованные.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forHome(int $limit = 8, ?string $lang = null): array
    {
        $limit = max(1, min(24, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM photo_albums WHERE is_published = 1 AND is_featured = 1
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            $stmt = Database::pdo()->prepare(
                'SELECT * FROM photo_albums WHERE is_published = 1
                 ORDER BY created_at DESC, id DESC LIMIT ' . $limit
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    /**
     * Срез опубликованных альбомов для постраничного вывода в блоке «Медиа».
     * forHome() — витрина главной, здесь нужен весь список подряд.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publishedSlice(int $limit, int $offset = 0, ?string $lang = null): array
    {
        $limit = max(1, min(48, $limit));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM photo_albums WHERE is_published = 1
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    /** Сколько всего опубликованных альбомов (для полосы страниц). */
    public static function publishedTotal(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM photo_albums WHERE is_published = 1')->fetchColumn();
    }

    /**
     * Публикация/снятие без переписывания заголовка, описания и обложки —
     * нужна массовым действиям списка, которым незачем перечитывать альбом.
     */
    public static function setPublished(int $id, bool $published): void
    {
        Database::pdo()->prepare('UPDATE photo_albums SET is_published = :p WHERE id = :id')
            ->execute([':p' => $published ? 1 : 0, ':id' => $id]);
        self::bustPageCache();
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM photo_albums WHERE id = :id')->execute([':id' => $id]);
        self::bustPageCache();
    }

    public static function slugExists(string $slug): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM photo_albums WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public static function images(int $albumId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM photo_album_images WHERE album_id = :a ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':a' => $albumId]);

        return $stmt->fetchAll();
    }

    public static function addImage(int $albumId, string $imageUrl, string $caption = '', string $credit = ''): ?int
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || self::findById($albumId) === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM photo_album_images WHERE album_id = :a'
        );
        $stmt->execute([':a' => $albumId]);
        $next = (int) $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO photo_album_images (album_id, image_url, caption, credit, sort_order, created_at)
             VALUES (:a, :u, :c, :cr, :o, NOW())'
        );
        $stmt->execute([
            ':a' => $albumId,
            ':u' => mb_substr($imageUrl, 0, 500),
            ':c' => mb_substr(trim($caption), 0, 255),
            ':cr' => mb_substr(trim($credit), 0, 255),
            ':o' => $next,
        ]);
        self::bustPageCache();

        return (int) Database::pdo()->lastInsertId();
    }

    public static function deleteImage(int $imageId): void
    {
        Database::pdo()->prepare('DELETE FROM photo_album_images WHERE id = :id')->execute([':id' => $imageId]);
        self::bustPageCache();
    }

    /** Обложка альбома: заданная вручную или первое фото. */
    public static function coverFor(array $album): string
    {
        $cover = trim((string) ($album['cover_url'] ?? ''));
        if ($cover !== '') {
            return $cover;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT image_url FROM photo_album_images WHERE album_id = :a ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $stmt->execute([':a' => (int) $album['id']]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    private static function bustPageCache(): void
    {
        \App\Core\Cache::forgetPrefix('page:');
    }
}
