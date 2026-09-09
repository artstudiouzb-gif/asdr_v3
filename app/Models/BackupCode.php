<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Резервные (backup) коды восстановления доступа к 2FA. Генерируются пулом при
 * настройке 2FA. В БД хранится только SHA-256 хеш; сверка — через hash_equals.
 * Каждый код одноразовый (used_at). Коды имеют высокую энтропию (случайные),
 * поэтому SHA-256 достаточно — медленный хеш не требуется.
 */
final class BackupCode
{
    private const POOL_SIZE = 10;

    /**
     * Полностью пересоздаёт пул кодов пользователя (старые затираются) и
     * возвращает СЫРЫЕ коды для однократного показа.
     *
     * @return array<int, string>
     */
    public static function regenerate(int $userId): array
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM backup_codes WHERE user_id = :uid')->execute([':uid' => $userId]);

        $codes = [];
        $insert = $pdo->prepare(
            'INSERT INTO backup_codes (user_id, code_hash, created_at) VALUES (:uid, :hash, NOW())'
        );

        for ($i = 0; $i < self::POOL_SIZE; $i++) {
            $code = self::randomCode();
            $codes[] = $code;
            $insert->execute([':uid' => $userId, ':hash' => hash('sha256', self::normalize($code))]);
        }

        return $codes;
    }

    /**
     * Проверяет код и, если валиден и не использован, помечает использованным.
     * Возвращает true при успешном одноразовом применении.
     */
    public static function consume(int $userId, string $code): bool
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return false;
        }

        $hash = hash('sha256', $normalized);
        $stmt = Database::pdo()->prepare(
            'SELECT id, code_hash FROM backup_codes WHERE user_id = :uid AND used_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);

        foreach ($stmt->fetchAll() as $row) {
            if (hash_equals((string) $row['code_hash'], $hash)) {
                $upd = Database::pdo()->prepare('UPDATE backup_codes SET used_at = NOW() WHERE id = :id');
                $upd->execute([':id' => (int) $row['id']]);
                return true;
            }
        }

        return false;
    }

    public static function remainingCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM backup_codes WHERE user_id = :uid AND used_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public static function hasCodes(int $userId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM backup_codes WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Формат кода: xxxx-xxxx (нижний регистр, без похожих символов). */
    private static function randomCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // без o/0/l/1/i
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $out .= '-';
            }
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** Нормализация ввода: нижний регистр, только буквы/цифры. */
    private static function normalize(string $code): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($code))) ?? '';
    }
}
