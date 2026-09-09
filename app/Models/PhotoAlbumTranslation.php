<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PhotoAlbumTranslation
{
    /**
     * @return array<string, array<string, mixed>> переводы по коду языка
     */
    public static function forAlbum(int $albumId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM photo_album_translations WHERE album_id = :id');
        $stmt->execute([':id' => $albumId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    public static function find(int $albumId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM photo_album_translations WHERE album_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $albumId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Пакетная загрузка одного перевода для списка альбомов — устраняет N+1.
     * @param list<int> $albumIds
     * @return array<int, array<string, mixed>>
     */
    public static function forAlbumIds(array $albumIds, string $lang): array
    {
        $albumIds = array_values(array_unique(array_filter(array_map('intval', $albumIds), static fn (int $id): bool => $id > 0)));
        if ($albumIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM photo_album_translations WHERE album_id IN ({$placeholders}) AND lang = ?"
        );
        $stmt->execute([...$albumIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['album_id']] = $row;
        }
        return $result;
    }

    public static function upsert(int $albumId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO photo_album_translations (album_id, lang, title, description)
             VALUES (:album_id, :lang, :title, :description)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)'
        );
        $stmt->execute([
            ':album_id' => $albumId,
            ':lang' => $lang,
            ':title' => $data['title'] ?? null,
            ':description' => $data['description'] ?? null,
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }
}
