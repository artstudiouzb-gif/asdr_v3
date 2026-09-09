<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Подписки браузеров на webpush-уведомления + очередь рассылки по новостям. */
final class WebPushSubscription
{
    /** Сохраняет подписку (idempotent по endpoint). Возвращает false при мусоре. */
    public static function save(string $endpoint, string $p256dh, string $auth): bool
    {
        if (!preg_match('#^https://\S{10,900}$#', $endpoint) || $p256dh === '' || $auth === '') {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO webpush_subscriptions (endpoint, endpoint_hash, p256dh, auth)
             VALUES (:endpoint, :hash, :p256dh, :auth)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)'
        );
        $stmt->execute([
            ':endpoint' => $endpoint,
            ':hash' => sha1($endpoint),
            ':p256dh' => $p256dh,
            ':auth' => $auth,
        ]);

        return true;
    }

    public static function deleteByEndpoint(string $endpoint): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM webpush_subscriptions WHERE endpoint_hash = :hash');
        $stmt->execute([':hash' => sha1($endpoint)]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(int $limit = 10000): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM webpush_subscriptions ORDER BY id ASC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM webpush_subscriptions')->fetchColumn();
    }

    // ===== Очередь уведомлений по новостям =====

    /** Ставит новость в очередь webpush (idempotent). */
    public static function enqueueNews(int $newsId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO webpush_queue (news_id) VALUES (:news_id)'
        );
        $stmt->execute([':news_id' => $newsId]);
    }

    /** @return list<array<string,mixed>> pending-задания на рассылку */
    public static function pendingQueue(int $limit = 5): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM webpush_queue WHERE status = 'pending' AND attempts < 3 ORDER BY id ASC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function markQueueSent(int $id): void
    {
        Database::pdo()->prepare("UPDATE webpush_queue SET status = 'sent', sent_at = NOW() WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public static function markQueueFailed(int $id, string $error): void
    {
        Database::pdo()->prepare(
            "UPDATE webpush_queue SET attempts = attempts + 1,
             status = IF(attempts + 1 >= 3, 'failed', 'pending'),
             last_error = :error WHERE id = :id"
        )->execute([':id' => $id, ':error' => mb_substr($error, 0, 490)]);
    }
}
