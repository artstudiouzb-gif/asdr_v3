<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Фотографии галереи новости. При привязке файла из медиабиблиотеки копирует
 * редакционные метаданные в снимок новости: последующая правка общей карточки
 * файла не меняет уже опубликованную подпись задним числом.
 */
final class NewsImage
{
    /** @return array<int, array<string, mixed>> */
    public static function forNews(int $newsId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM news_images WHERE news_id = :nid ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':nid' => $newsId]);

        return $stmt->fetchAll();
    }

    public static function create(int $newsId, string $path, ?string $alt = null, int $sortOrder = 0): int
    {
        $libraryFile = FileEntry::findPublicByUrl($path);
        $resolvedAlt = $alt ?? self::nullableString($libraryFile['alt_text'] ?? null, 255);
        $caption = self::nullableString($libraryFile['caption'] ?? null, 255);
        $credit = self::nullableString($libraryFile['credit'] ?? null, 255);
        $focalX = self::nullablePercent($libraryFile['focal_x'] ?? null);
        $focalY = self::nullablePercent($libraryFile['focal_y'] ?? null);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO news_images
                (news_id, path, alt_text, caption, credit, focal_x, focal_y, sort_order, created_at)
             VALUES
                (:nid, :path, :alt, :caption, :credit, :fx, :fy, :sort, NOW())'
        );
        $stmt->execute([
            ':nid' => $newsId,
            ':path' => $path,
            ':alt' => $resolvedAlt,
            ':caption' => $caption,
            ':credit' => $credit,
            ':fx' => $focalX,
            ':fy' => $focalY,
            ':sort' => $sortOrder,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function updateMeta(int $id, int $newsId, ?string $alt, int $sortOrder, ?int $focalX, ?int $focalY, ?string $caption = null, ?string $credit = null): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE news_images SET alt_text = :alt, caption = :caption, credit = :credit, sort_order = :sort, focal_x = :fx, focal_y = :fy
             WHERE id = :id AND news_id = :nid'
        );
        $stmt->execute([
            ':alt' => $alt,
            ':caption' => $caption,
            ':credit' => $credit,
            ':sort' => $sortOrder,
            ':fx' => $focalX,
            ':fy' => $focalY,
            ':id' => $id,
            ':nid' => $newsId,
        ]);
    }

    public static function delete(int $id, int $newsId): ?string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT path FROM news_images WHERE id = :id AND news_id = :nid');
        $stmt->execute([':id' => $id, ':nid' => $newsId]);
        $path = $stmt->fetchColumn();
        if ($path === false) {
            return null;
        }

        $pdo->prepare('DELETE FROM news_images WHERE id = :id AND news_id = :nid')
            ->execute([':id' => $id, ':nid' => $newsId]);

        return (string) $path;
    }

    /** @return array<int, string> пути удалённых фото (для очистки файлов) */
    public static function deleteForNews(int $newsId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT path FROM news_images WHERE news_id = :nid');
        $stmt->execute([':nid' => $newsId]);
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM news_images WHERE news_id = :nid')->execute([':nid' => $newsId]);

        return array_map('strval', $paths);
    }

    public static function firstPath(int $newsId): ?string
    {
        $stmt = Database::pdo()->prepare(
            'SELECT path FROM news_images WHERE news_id = :nid ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $stmt->execute([':nid' => $newsId]);
        $path = $stmt->fetchColumn();

        return $path !== false ? (string) $path : null;
    }

    private static function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private static function nullablePercent(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, min(100, (int) $value));
    }
}
