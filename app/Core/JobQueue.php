<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Высокопроизводительный единый менеджер асинхронных фоновых задач (Enterprise Job Queue).
 * Поддерживает приоритеты, гонко-безопасную аренду строк (QueueClaim),
 * экспоненциальный retry и обработку нескольких очередей.
 */
final class JobQueue
{
    public const PRIORITY_CRITICAL = 1;
    public const PRIORITY_HIGH = 5;
    public const PRIORITY_DEFAULT = 10;
    public const PRIORITY_LOW = 20;

    /**
     * Ставит задачу в очередь.
     *
     * @param string $handler Имя класса или callable-идентификатор обработчика
     * @param array<string, mixed> $payload Данные задачи
     * @param string $queue Имя очереди (default, mail, webhooks, notifications, social)
     * @param int $delaySeconds Задержка перед началом выполнения
     * @param int $priority Приоритет (меньше число = выше приоритет)
     * @param int $maxAttempts Максимум попыток выполнения
     */
    public static function push(
        string $handler,
        array $payload = [],
        string $queue = 'default',
        int $delaySeconds = 0,
        int $priority = self::PRIORITY_DEFAULT,
        int $maxAttempts = 3
    ): int {
        if (!Database::isConnected()) {
            return 0;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO jobs (queue, handler, payload, priority, status, attempts, max_attempts, available_at, created_at)
                 VALUES (:queue, :handler, :payload, :priority, :status, 0, :max_attempts, DATE_ADD(NOW(), INTERVAL :delay SECOND), NOW())'
            );

            $stmt->bindValue(':queue', $queue);
            $stmt->bindValue(':handler', $handler);
            $stmt->bindValue(':payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
            $stmt->bindValue(':status', 'pending');
            $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
            $stmt->bindValue(':delay', max(0, $delaySeconds), PDO::PARAM_INT);

            $stmt->execute();

            return (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            Logger::error('JobQueue::push failed: ' . $e->getMessage(), 'error', ['handler' => $handler, 'queue' => $queue]);
            return 0;
        }
    }

    /**
     * Гонко-безопасно забирает пачку готовых к выполнению задач с арендой строк.
     *
     * @return list<array{id: int, queue: string, handler: string, payload: array<string, mixed>, attempts: int, max_attempts: int}>
     */
    public static function claimBatch(int $limit = 10, ?string $queue = null, int $leaseSeconds = 60): array
    {
        if (!Database::isConnected()) {
            return [];
        }

        self::recoverStuckJobs();

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $sql = "SELECT id, queue, handler, payload, attempts, max_attempts
                    FROM jobs
                    WHERE status = 'pending'
                      AND available_at <= NOW()
                      AND (locked_until IS NULL OR locked_until < NOW())";

            $bind = [];
            if ($queue !== null && $queue !== '') {
                $sql .= ' AND queue = :queue';
                $bind[':queue'] = $queue;
            }

            $sql .= ' ORDER BY priority ASC, id ASC LIMIT ' . max(1, min(100, $limit)) . ' FOR UPDATE SKIP LOCKED';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                $pdo->commit();
                return [];
            }

            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $update = $pdo->prepare(
                "UPDATE jobs
                 SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id IN ({$placeholders})"
            );
            $update->execute([$leaseSeconds, ...$ids]);
            $pdo->commit();

            $batch = [];
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row['payload'], true);
                $batch[] = [
                    'id' => (int) $row['id'],
                    'queue' => (string) $row['queue'],
                    'handler' => (string) $row['handler'],
                    'payload' => is_array($decoded) ? $decoded : [],
                    'attempts' => (int) $row['attempts'],
                    'max_attempts' => (int) $row['max_attempts'],
                ];
            }

            return $batch;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('JobQueue::claimBatch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Помечает задачу успешно выполненной.
     */
    public static function markCompleted(int $id): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                "UPDATE jobs SET status = 'completed', locked_until = NULL WHERE id = :id"
            );
            $stmt->execute([':id' => $id]);
        } catch (Throwable $e) {
            Logger::error('JobQueue::markCompleted failed: ' . $e->getMessage(), 'error', ['id' => $id]);
        }
    }

    /**
     * Фиксирует сбой выполнения задачи с экспоненциальной задержкой retry.
     */
    public static function markFailed(int $id, int $currentAttempts, int $maxAttempts, string $error): void
    {
        try {
            $nextAttempts = $currentAttempts + 1;
            $failedPermanently = $nextAttempts >= $maxAttempts;

            // Экспоненциальный интервал: 10с, 60с, 300с...
            $backoffSeconds = (int) (10 * (2 ** max(0, $currentAttempts)));

            $stmt = Database::pdo()->prepare(
                "UPDATE jobs
                 SET attempts = :attempts,
                     status = IF(:failed = 1, 'failed', 'pending'),
                     available_at = DATE_ADD(NOW(), INTERVAL :backoff SECOND),
                     locked_until = NULL,
                     last_error = :error
                 WHERE id = :id"
            );

            $stmt->bindValue(':attempts', $nextAttempts, PDO::PARAM_INT);
            $stmt->bindValue(':failed', $failedPermanently ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':backoff', $failedPermanently ? 0 : $backoffSeconds, PDO::PARAM_INT);
            $stmt->bindValue(':error', mb_substr($error, 0, 1000));
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $e) {
            Logger::error('JobQueue::markFailed failed: ' . $e->getMessage(), 'error', ['id' => $id]);
        }
    }

    /**
     * Очищает старые завершенные задачи (храним 7 дней).
     */
    public static function purgeCompleted(int $olderThanDays = 7): int
    {
        try {
            $stmt = Database::pdo()->prepare(
                "DELETE FROM jobs WHERE status = 'completed' AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
            );
            $stmt->bindValue(':days', max(1, $olderThanDays), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            Logger::error('JobQueue::purgeCompleted failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Автоматически восстанавливает зависшие задачи (Crash/Zombie recovery).
     * Если воркер аварийно упал и блокировка истекла дольше чем $timeoutSeconds,
     * возвращает задачу в pending с увеличением счетчика попыток.
     */
    public static function recoverStuckJobs(int $timeoutSeconds = 600): int
    {
        if (!Database::isConnected()) {
            return 0;
        }

        try {
            $stmt = Database::pdo()->prepare(
                "UPDATE jobs
                 SET locked_until = NULL,
                     attempts = attempts + 1,
                     status = IF(attempts + 1 >= max_attempts, 'failed', 'pending'),
                     last_error = 'Worker timeout / unhandled termination'
                 WHERE locked_until IS NOT NULL
                   AND locked_until < DATE_SUB(NOW(), INTERVAL :timeout SECOND)"
            );
            $stmt->bindValue(':timeout', max(60, $timeoutSeconds), PDO::PARAM_INT);
            $stmt->execute();

            $recovered = (int) $stmt->rowCount();
            if ($recovered > 0) {
                Logger::warning(sprintf('JobQueue: recovered %d stuck/zombie jobs.', $recovered));
            }

            return $recovered;
        } catch (Throwable $e) {
            Logger::error('JobQueue::recoverStuckJobs failed: ' . $e->getMessage());
            return 0;
        }
    }
}
