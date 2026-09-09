<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SearchLog
{
    public static function record(string $query, int $resultsCount): void
    {
        $query = trim(mb_substr($query, 0, 100));
        if ($query === '' || mb_strlen($query) < 2) {
            return;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO search_log (query, results_count, created_at) VALUES (:query, :cnt, NOW())'
            );
            $stmt->execute([
                ':query' => $query,
                ':cnt' => $resultsCount,
            ]);
        } catch (\Throwable $e) {
            // Игнорируем некритичные ошибки при логировании
        }
    }

    public static function popular(int $limit = 5): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT query, COUNT(*) AS searches_count, MAX(results_count) AS last_results_count
                 FROM search_log
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY query
                 ORDER BY searches_count DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
