<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Одноразовые токены восстановления пароля. В БД хранится только SHA-256 хеш
 * токена; сам токен уходит пользователю по e-mail и в системе не сохраняется.
 * Сверка — через hash_equals по хешу.
 */
final class PasswordResetToken
{
    private const TTL_MINUTES = 30;

    /**
     * Создаёт токен для пользователя и возвращает СЫРОЙ токен (для ссылки).
     * Старые неиспользованные токены пользователя инвалидируются.
     */
    public static function issue(int $userId): string
    {
        // Инвалидируем прежние активные токены (одна ссылка за раз).
        $del = Database::pdo()->prepare('DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL');
        $del->execute([':uid' => $userId]);

        $token = bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), NOW())'
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':hash', hash('sha256', $token));
        $stmt->bindValue(':ttl', self::TTL_MINUTES, \PDO::PARAM_INT);
        $stmt->execute();

        return $token;
    }

    /**
     * Возвращает валидную запись токена (не использован, не истёк) либо null.
     * Сравнение хеша выполняется через hash_equals на стороне PHP.
     *
     * @return array<string, mixed>|null
     */
    public static function findValid(string $token): ?array
    {
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Дополнительная защита: постоянное по времени сравнение.
        if (!hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }

        return $row;
    }

    public static function markUsed(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
