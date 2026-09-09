<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\NotificationSchema;
use PDO;

final class NotificationDelivery
{
    private const MAX_ATTEMPTS = 5;
    private const LEASE_MINUTES = 5;

    public static function enqueue(int $recipientId, string $channel, string $destination): void
    {
        NotificationSchema::ensure();
        $channel = strtolower(trim($channel));
        $destination = trim($destination);
        if (!in_array($channel, ['email', 'telegram'], true) || $destination === '') {
            return;
        }

        $key = hash('sha256', $recipientId . '|' . $channel . '|' . $destination);
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO notification_deliveries
                (recipient_id, channel, destination, status, attempts, next_attempt_at, idempotency_key, created_at, updated_at)
             VALUES (:rid, :channel, :destination, \'pending\', 0, NOW(), :idem, NOW(), NOW())'
        );
        $stmt->execute([
            ':rid' => $recipientId,
            ':channel' => $channel,
            ':destination' => mb_substr($destination, 0, 255),
            ':idem' => $key,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function claimBatch(int $limit = 20): array
    {
        NotificationSchema::ensure();
        $limit = max(1, min(100, $limit));
        $pdo = Database::pdo();

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(self::claimSql(true));
            $stmt->bindValue(':max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ($rows !== []) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $lease = $pdo->prepare(
                    'UPDATE notification_deliveries
                     SET locked_until = DATE_ADD(NOW(), INTERVAL ' . self::LEASE_MINUTES . " MINUTE), updated_at = NOW()
                     WHERE id IN ({$placeholders})"
                );
                $lease->execute($ids);
            }
            $pdo->commit();
            return $rows;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Fallback для старой MariaDB без SKIP LOCKED. ProcessLock в
            // worker дополнительно предотвращает параллельный запуск на хосте.
            $stmt = $pdo->prepare(self::claimSql(false));
            $stmt->bindValue(':max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }
    }

    public static function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_deliveries
             SET status = \'sent\', sent_at = NOW(), locked_until = NULL,
                 last_error = NULL, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public static function defer(int $id, int $minutes = 15): void
    {
        $next = date('Y-m-d H:i:s', time() + max(1, min(720, $minutes)) * 60);
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_deliveries
             SET next_attempt_at = :next_attempt, locked_until = NULL, updated_at = NOW()
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([':next_attempt' => $next, ':id' => $id]);
    }

    public static function markFailed(int $id, int $attempts, string $error): void
    {
        $attempts = max(0, $attempts) + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
        $delay = match ($attempts) {
            1 => 1,
            2 => 5,
            3 => 15,
            default => 60,
        };
        $next = date('Y-m-d H:i:s', time() + $delay * 60);

        $stmt = Database::pdo()->prepare(
            'UPDATE notification_deliveries
             SET status = :status, attempts = :attempts,
                 next_attempt_at = :next_attempt, locked_until = NULL,
                 last_error = :error, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
        $stmt->bindValue(':next_attempt', $next);
        $stmt->bindValue(':error', mb_substr(trim($error), 0, 4000));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array{pending:int,sent:int,failed:int} */
    public static function summary(): array
    {
        NotificationSchema::ensure();
        $rows = Database::pdo()->query(
            'SELECT status, COUNT(*) AS total FROM notification_deliveries GROUP BY status'
        )->fetchAll();
        $summary = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status] = (int) $row['total'];
            }
        }
        return $summary;
    }

    private static function claimSql(bool $skipLocked): string
    {
        return 'SELECT d.id, d.recipient_id, d.channel, d.destination, d.attempts,
                       n.id AS notification_id, n.title, n.message, n.action_url,
                       n.severity, n.category, n.requires_ack,
                       u.id AS user_id, u.username
                FROM notification_deliveries d
                JOIN notification_recipients r ON r.id = d.recipient_id
                JOIN notifications n ON n.id = r.notification_id
                JOIN users u ON u.id = r.user_id
                WHERE d.status = \'pending\'
                  AND d.attempts < :max_attempts
                  AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= NOW())
                  AND (d.locked_until IS NULL OR d.locked_until < NOW())
                  AND r.dismissed_at IS NULL
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY d.created_at ASC
                LIMIT :limit'
            . ($skipLocked ? ' FOR UPDATE SKIP LOCKED' : '');
    }
}
