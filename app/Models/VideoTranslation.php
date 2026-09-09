<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class VideoTranslation
{
    /**
     * @return array<string, array<string, mixed>> переводы по коду языка
     */
    public static function forVideo(int $videoId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM video_translations WHERE video_id = :id');
        $stmt->execute([':id' => $videoId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    public static function find(int $videoId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM video_translations WHERE video_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $videoId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Пакетная загрузка одного перевода для списка видео — устраняет N+1.
     * @param list<int> $videoIds
     * @return array<int, array<string, mixed>>
     */
    public static function forVideoIds(array $videoIds, string $lang): array
    {
        $videoIds = array_values(array_unique(array_filter(array_map('intval', $videoIds), static fn (int $id): bool => $id > 0)));
        if ($videoIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM video_translations WHERE video_id IN ({$placeholders}) AND lang = ?"
        );
        $stmt->execute([...$videoIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['video_id']] = $row;
        }
        return $result;
    }

    public static function upsert(int $videoId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO video_translations (video_id, lang, title, description)
             VALUES (:video_id, :lang, :title, :description)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)'
        );
        $stmt->execute([
            ':video_id' => $videoId,
            ':lang' => $lang,
            ':title' => $data['title'] ?? null,
            ':description' => $data['description'] ?? null,
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }
}
