<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Защита очередей от состояния гонки воркеров: выборка задач через
 * SELECT ... FOR UPDATE SKIP LOCKED (MariaDB 10.6+/MySQL 8.0+) внутри
 * транзакции + аренда строк (locked_until), чтобы параллельные cron-процессы
 * не подхватывали и не дублировали одни и те же записи. Если СУБД не знает
 * SKIP LOCKED (старые MariaDB) — прозрачный откат на простую выборку.
 */
final class QueueClaim
{
    /** Таблицы-очереди, для которых разрешён claim (защита от инъекций). */
    private const TABLES = ['mail_queue', 'webhook_deliveries', 'social_posts'];

    /** Аренда: сколько минут строка считается «занятой» воркером. */
    private const LEASE_MINUTES = 5;

    /**
     * Дополнительное условие готовности задачи по таблицам. У публикаций
     * бывает отложенная отправка: задача лежит в очереди, но её время ещё не
     * пришло — воркер такую не берёт.
     */
    private const READY_CLAUSE = [
        'social_posts' => 'AND (scheduled_at IS NULL OR scheduled_at <= NOW())',
    ];

    /**
     * Забирает пачку pending-задач эксклюзивно для этого процесса.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function batch(string $table, int $maxAttempts, int $limit): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Неизвестная таблица очереди: ' . $table);
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();

            $ready = self::READY_CLAUSE[$table] ?? '';
            $stmt = $pdo->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending' AND attempts < :max
                   AND (locked_until IS NULL OR locked_until < NOW())
                   {$ready}
                 ORDER BY created_at ASC LIMIT :limit
                 FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ($rows !== []) {
                $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $upd = $pdo->prepare(
                    "UPDATE {$table} SET locked_until = DATE_ADD(NOW(), INTERVAL " . self::LEASE_MINUTES . " MINUTE)
                     WHERE id IN ({$in})"
                );
                $upd->execute($ids);
            }

            $pdo->commit();

            return $rows;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Старые СУБД без SKIP LOCKED / без колонки locked_until: обычная
            // выборка (защиту от наложения даёт ProcessLock на этом хосте).
            $stmt = $pdo->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND attempts < :max
                 " . (self::READY_CLAUSE[$table] ?? '') . "
                 ORDER BY created_at ASC LIMIT :limit"
            );
            $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        }
    }
}
