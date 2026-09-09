<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Реестр активных сессий администраторов. Даёт список устройств и мгновенный
 * серверный отзыв: сессия считается действительной, только пока её строка
 * присутствует здесь (проверяется в Auth::check()).
 *
 * Хранится хеш идентификатора сессии (sha256), а не сам id — компрометация
 * БД не раскрывает действующие session-id.
 */
final class SessionRegistry
{
    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** Регистрирует/обновляет текущую сессию пользователя. */
    public static function register(int $userId, string $sessionId, ?string $ip, ?string $userAgent): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_sessions (user_id, sid_hash, ip_address, user_agent, created_at, last_seen_at)
             VALUES (:uid, :sid, :ip, :ua, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':sid' => self::hash($sessionId),
            ':ip' => $ip !== null ? substr($ip, 0, 45) : null,
            ':ua' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);
    }

    /** Существует ли действительная (не отозванная) сессия. */
    public static function exists(int $userId, string $sessionId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM user_sessions WHERE user_id = :uid AND sid_hash = :sid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':sid' => self::hash($sessionId)]);

        return (bool) $stmt->fetchColumn();
    }

    public static function touch(int $userId, string $sessionId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = :uid AND sid_hash = :sid'
        );
        $stmt->execute([':uid' => $userId, ':sid' => self::hash($sessionId)]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, sid_hash, ip_address, user_agent, created_at, last_seen_at
             FROM user_sessions WHERE user_id = :uid ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** Отзывает одну сессию (по её id в таблице) в рамках владельца. */
    public static function revoke(int $userId, int $sessionRowId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM user_sessions WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $sessionRowId, ':uid' => $userId]);
    }

    /** Отзывает все сессии пользователя, кроме указанной (по sid_hash). */
    public static function revokeAllExcept(int $userId, ?string $keepSessionId): void
    {
        if ($keepSessionId !== null) {
            $stmt = Database::pdo()->prepare(
                'DELETE FROM user_sessions WHERE user_id = :uid AND sid_hash <> :keep'
            );
            $stmt->execute([':uid' => $userId, ':keep' => self::hash($keepSessionId)]);
        } else {
            $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
        }
    }

    /** Полностью очищает сессии пользователя (например, при смене пароля). */
    public static function revokeAll(int $userId): void
    {
        self::revokeAllExcept($userId, null);
    }

    public static function remove(string $sessionId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE sid_hash = :sid');
        $stmt->execute([':sid' => self::hash($sessionId)]);
    }
}
