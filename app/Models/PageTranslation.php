<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PageTranslation
{
    /**
     * @return array<string, array<string, mixed>> переводы по коду языка
     */
    public static function forPage(int $pageId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM page_translations WHERE page_id = :id');
        $stmt->execute([':id' => $pageId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    public static function find(int $pageId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM page_translations WHERE page_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $pageId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function upsert(int $pageId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO page_translations (page_id, lang, title, meta_title, meta_description, `lead`)
             VALUES (:page_id, :lang, :title, :meta_title, :meta_description, :lead)
             ON DUPLICATE KEY UPDATE title = VALUES(title), meta_title = VALUES(meta_title),
                meta_description = VALUES(meta_description), `lead` = VALUES(lead)'
        );
        $stmt->execute([
            ':page_id' => $pageId,
            ':lang' => $lang,
            ':title' => $data['title'] ?? null,
            ':meta_title' => $data['meta_title'] ?? null,
            ':meta_description' => $data['meta_description'] ?? null,
            ':lead' => $data['lead'] ?? null,
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }
}
