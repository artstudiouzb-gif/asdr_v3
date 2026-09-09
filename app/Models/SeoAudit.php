<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Seo\SeoAudit as Auditor;
use App\Core\Seo\SeoFinding;

/**
 * Снимки проверки индексации.
 *
 * Хранится история, а не только последний результат: первый вопрос по любой
 * находке — «это новое или так было всегда», и ответить на него можно только
 * сравнением с прошлым проходом.
 */
final class SeoAudit
{
    /** Дольше держать незачем: старый снимок ничего не говорит о сегодняшнем сайте. */
    private const KEEP_DAYS = 90;

    /**
     * @param list<SeoFinding> $findings
     */
    public static function save(array $findings): int
    {
        $summary = Auditor::summary($findings);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO seo_audits (errors, warnings, findings) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $summary['errors'],
            $summary['warnings'],
            json_encode(
                array_map(static fn (SeoFinding $f): array => $f->toArray(), $findings),
                JSON_UNESCAPED_UNICODE
            ),
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        self::prune();

        return $id;
    }

    /** @return array{id: int, errors: int, warnings: int, created_at: string, findings: list<SeoFinding>}|null */
    public static function latest(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT * FROM seo_audits ORDER BY id DESC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return list<array{id: int, errors: int, warnings: int, created_at: string}> */
    public static function history(int $limit = 30): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, errors, warnings, created_at FROM seo_audits ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'errors' => (int) $row['errors'],
                'warnings' => (int) $row['warnings'],
                'created_at' => (string) $row['created_at'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, errors: int, warnings: int, created_at: string, findings: list<SeoFinding>}
     */
    private static function hydrate(array $row): array
    {
        $raw = json_decode((string) ($row['findings'] ?? '[]'), true);

        return [
            'id' => (int) $row['id'],
            'errors' => (int) $row['errors'],
            'warnings' => (int) $row['warnings'],
            'created_at' => (string) $row['created_at'],
            'findings' => array_map(
                static fn (array $item): SeoFinding => SeoFinding::fromArray($item),
                array_values(array_filter(is_array($raw) ? $raw : [], 'is_array'))
            ),
        ];
    }

    private static function prune(): void
    {
        Database::pdo()
            ->prepare('DELETE FROM seo_audits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
            ->execute([self::KEEP_DAYS]);
    }
}
