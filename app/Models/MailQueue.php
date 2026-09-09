<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class MailQueue
{
    private const MAX_ATTEMPTS = 3;

    public static function enqueue(string $toEmail, string $subject, string $body, ?string $toName = null): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO mail_queue (to_email, to_name, subject, body, status, created_at)
             VALUES (:to_email, :to_name, :subject, :body, :status, NOW())'
        );
        $stmt->execute([
            ':to_email' => $toEmail,
            ':to_name' => $toName,
            ':subject' => $subject,
            ':body' => $body,
            ':status' => 'pending',
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Ставит персональный дайджест в очередь ровно один раз за период.
     * false означает, что письмо с таким dedupe_key уже существует.
     */
    public static function enqueueDigest(
        int $subscriberId,
        string $toEmail,
        string $subject,
        string $body,
        string $dedupeKey
    ): bool {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO mail_queue
                (to_email, subscriber_id, purpose, dedupe_key, subject, body, status, created_at)
             VALUES
                (:to_email, :subscriber_id, 'digest', :dedupe_key, :subject, :body, 'pending', NOW())
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->execute([
            ':to_email' => $toEmail,
            ':subscriber_id' => $subscriberId,
            ':dedupe_key' => mb_substr($dedupeKey, 0, 190),
            ':subject' => $subject,
            ':body' => $body,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pendingBatch(int $limit = 20): array
    {
        // Гонко-безопасная выборка: FOR UPDATE SKIP LOCKED + аренда строк,
        // чтобы параллельные воркеры не дублировали обработку (QueueClaim).
        return \App\Core\QueueClaim::batch('mail_queue', self::MAX_ATTEMPTS, $limit);
    }

    public static function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE mail_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        // После MAX_ATTEMPTS помечаем как failed, иначе оставляем pending для повтора.
        $stmt = Database::pdo()->prepare(
            "UPDATE mail_queue
             SET attempts = attempts + 1,
                 last_error = :error,
                 status = IF(attempts + 1 >= :max, 'failed', 'pending')
             WHERE id = :id"
        );
        $stmt->bindValue(':error', mb_substr($error, 0, 500));
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function markCancelled(int $id, string $reason): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE mail_queue
             SET status = 'cancelled', locked_until = NULL, last_error = :reason
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            ':id' => $id,
            ':reason' => mb_substr($reason, 0, 500),
        ]);
    }

    /** @return array{total:int,pending:int,sent:int,failed:int,cancelled:int,last_queued:?string} */
    public static function digestStats(): array
    {
        $row = Database::pdo()->query(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'sent') AS sent,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'cancelled') AS cancelled,
                    MAX(created_at) AS last_queued
             FROM mail_queue
             WHERE purpose = 'digest'"
        )->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'last_queued' => isset($row['last_queued']) ? (string) $row['last_queued'] : null,
        ];
    }
}
