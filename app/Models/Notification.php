<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\NotificationSchema;
use App\Core\UrlGuard;
use PDO;

final class Notification
{
    private const CATEGORIES = ['system', 'security', 'content', 'forms', 'users', 'integration'];
    private const SEVERITIES = ['info', 'success', 'warning', 'error', 'critical', 'security'];
    private const ROLES = ['admin', 'editor'];

    /**
     * @param array{
     *   category?:string,severity?:string,title:string,message:string,
     *   action_url?:?string,dedupe_key?:?string,requires_ack?:bool,
     *   created_by?:?int,expires_at?:?string,user_ids?:list<int>,roles?:list<string>
     * } $data
     */
    public static function create(array $data): int
    {
        NotificationSchema::ensure();

        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ($title === '' || $message === '') {
            throw new \InvalidArgumentException('Уведомлению нужны заголовок и сообщение.');
        }

        $category = self::normalizeCategory((string) ($data['category'] ?? 'system'));
        $severity = self::normalizeSeverity((string) ($data['severity'] ?? 'info'));
        $actionUrl = self::normalizeActionUrl($data['action_url'] ?? null);
        $dedupeKey = trim((string) ($data['dedupe_key'] ?? ''));
        $dedupeKey = $dedupeKey !== '' ? mb_substr($dedupeKey, 0, 190) : null;
        $createdBy = isset($data['created_by']) && (int) $data['created_by'] > 0
            ? (int) $data['created_by']
            : null;
        $expiresAt = self::normalizeDate($data['expires_at'] ?? null);
        $userIds = self::recipientIds($data['user_ids'] ?? [], $data['roles'] ?? []);
        if ($userIds === []) {
            return 0;
        }

        return Database::transaction(function () use (
            $title,
            $message,
            $category,
            $severity,
            $actionUrl,
            $dedupeKey,
            $createdBy,
            $expiresAt,
            $userIds,
            $data
        ): int {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO notifications
                    (category, severity, title, message, action_url, dedupe_key,
                     requires_ack, created_by, expires_at, created_at)
                 VALUES (:category, :severity, :title, :message, :url, :dedupe,
                         :requires_ack, :created_by, :expires_at, NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $stmt->execute([
                ':category' => $category,
                ':severity' => $severity,
                ':title' => mb_substr($title, 0, 190),
                ':message' => mb_substr($message, 0, 10000),
                ':url' => $actionUrl,
                ':dedupe' => $dedupeKey,
                ':requires_ack' => !empty($data['requires_ack']) ? 1 : 0,
                ':created_by' => $createdBy,
                ':expires_at' => $expiresAt,
            ]);
            $notificationId = (int) Database::pdo()->lastInsertId();

            foreach ($userIds as $userId) {
                $recipientId = self::attachRecipient($notificationId, $userId);
                self::queueExternalDelivery($recipientId, $userId, $severity);
            }

            return $notificationId;
        });
    }

    public static function unreadCount(int $userId): int
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = :uid AND r.read_at IS NULL AND r.dismissed_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at > NOW())'
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function pendingAcknowledgementCount(int $userId): int
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = :uid AND n.requires_ack = 1
               AND r.acknowledged_at IS NULL AND r.dismissed_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at > NOW())'
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        NotificationSchema::ensure();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT n.id, n.category, n.severity, n.title, n.message, n.action_url,
                    n.requires_ack, n.created_at, n.expires_at,
                    r.read_at, r.acknowledged_at, r.dismissed_at
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = :uid AND r.dismissed_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function recentForUser(int $userId, int $limit = 6): array
    {
        return self::forUser($userId, max(1, min(12, $limit)), 0);
    }

    public static function markRead(int $notificationId, int $userId): bool
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients
             SET read_at = COALESCE(read_at, NOW())
             WHERE notification_id = :nid AND user_id = :uid AND dismissed_at IS NULL'
        );
        $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function markAllRead(int $userId): int
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             SET r.read_at = COALESCE(r.read_at, NOW())
             WHERE r.user_id = :uid AND r.dismissed_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at > NOW())'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    public static function acknowledge(int $notificationId, int $userId): bool
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             SET r.read_at = COALESCE(r.read_at, NOW()), r.acknowledged_at = COALESCE(r.acknowledged_at, NOW())
             WHERE r.notification_id = :nid AND r.user_id = :uid
               AND n.requires_ack = 1 AND r.dismissed_at IS NULL'
        );
        $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function dismiss(int $notificationId, int $userId): bool
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             SET r.read_at = COALESCE(r.read_at, NOW()), r.dismissed_at = NOW()
             WHERE r.notification_id = :nid AND r.user_id = :uid
               AND (n.requires_ack = 0 OR r.acknowledged_at IS NOT NULL)'
        );
        $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<int> */
    private static function recipientIds(array $rawUserIds, array $rawRoles): array
    {
        $ids = [];
        foreach ($rawUserIds as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $roles = [];
        foreach ($rawRoles as $value) {
            $role = strtolower(trim((string) $value));
            if (in_array($role, self::ROLES, true)) {
                $roles[$role] = $role;
            }
        }
        if ($roles !== []) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = Database::pdo()->prepare("SELECT id FROM users WHERE role IN ({$placeholders})");
            $stmt->execute(array_values($roles));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    private static function attachRecipient(int $notificationId, int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO notification_recipients (notification_id, user_id, created_at)
             VALUES (:nid, :uid, NOW())'
        );
        $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);

        $lookup = Database::pdo()->prepare(
            'SELECT id FROM notification_recipients
             WHERE notification_id = :nid AND user_id = :uid LIMIT 1'
        );
        $lookup->execute([':nid' => $notificationId, ':uid' => $userId]);
        return (int) $lookup->fetchColumn();
    }

    private static function queueExternalDelivery(int $recipientId, int $userId, string $severity): void
    {
        if ($recipientId <= 0) {
            return;
        }

        $preference = NotificationPreference::forUser($userId);
        if (!NotificationPreference::allowsSeverity($preference, $severity)
            || (string) ($preference['digest_mode'] ?? 'none') !== 'none') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT email, telegram_chat_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        if (!empty($preference['email_enabled'])
            && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            NotificationDelivery::enqueue($recipientId, 'email', (string) $user['email']);
        }
        $chatId = (int) ($user['telegram_chat_id'] ?? 0);
        if (!empty($preference['telegram_enabled']) && $chatId !== 0) {
            NotificationDelivery::enqueue($recipientId, 'telegram', (string) $chatId);
        }
    }

    private static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, self::CATEGORIES, true) ? $category : 'system';
    }

    private static function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, self::SEVERITIES, true) ? $severity : 'info';
    }

    private static function normalizeActionUrl(mixed $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // Внутренний центр не должен превращаться в фишинговую рассылку.
        if (!str_starts_with($url, '/admin') || !UrlGuard::isSafeLink($url)) {
            return null;
        }
        return mb_substr($url, 0, 500);
    }

    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
