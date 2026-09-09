<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Notification;

/**
 * High-level notification API. Callers provide a safe summary, recipients and
 * an internal admin URL; the model handles deduplication and channel queues.
 */
final class NotificationCenter
{
    /** @param list<int> $userIds */
    public static function users(
        array $userIds,
        string $title,
        string $message,
        string $category = 'system',
        string $severity = 'info',
        ?string $actionUrl = null,
        bool $requiresAck = false,
        ?string $dedupeKey = null,
        ?int $createdBy = null
    ): int {
        return self::emit([
            'user_ids' => $userIds,
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'severity' => $severity,
            'action_url' => $actionUrl,
            'requires_ack' => $requiresAck,
            'dedupe_key' => $dedupeKey,
            'created_by' => $createdBy,
        ]);
    }

    /** @param list<string> $roles */
    public static function roles(
        array $roles,
        string $title,
        string $message,
        string $category = 'system',
        string $severity = 'info',
        ?string $actionUrl = null,
        bool $requiresAck = false,
        ?string $dedupeKey = null,
        ?int $createdBy = null
    ): int {
        return self::emit([
            'roles' => $roles,
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'severity' => $severity,
            'action_url' => $actionUrl,
            'requires_ack' => $requiresAck,
            'dedupe_key' => $dedupeKey,
            'created_by' => $createdBy,
        ]);
    }

    public static function administrators(
        string $title,
        string $message,
        string $category = 'system',
        string $severity = 'warning',
        ?string $actionUrl = null,
        bool $requiresAck = false,
        ?string $dedupeKey = null,
        ?int $createdBy = null
    ): int {
        return self::roles(
            ['admin'],
            $title,
            $message,
            $category,
            $severity,
            $actionUrl,
            $requiresAck,
            $dedupeKey,
            $createdBy
        );
    }

    /** @param array<string,mixed> $payload */
    private static function emit(array $payload): int
    {
        try {
            return Notification::create($payload);
        } catch (\Throwable $e) {
            // Уведомление никогда не должно сорвать основную операцию. Не
            // вызываем Logger::security здесь, чтобы исключить рекурсию.
            error_log('[notification-center] ' . $e->getMessage());
            return 0;
        }
    }
}
