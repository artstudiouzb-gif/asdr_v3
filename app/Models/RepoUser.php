<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\SecretBox;

/**
 * Учётная запись портала защищённого файлового хранилища. Полностью отделена
 * от таблицы users (админ-панель): у портала своя авторизация и своя сессия.
 */
final class RepoUser
{
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT id, username, full_name, organization, email, totp_enabled, is_active, last_login_at, created_at
             FROM repo_users ORDER BY username ASC'
        )->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM repo_users')->fetchColumn();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM repo_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return self::decryptSecrets($stmt->fetch() ?: null);
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM repo_users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);

        return self::decryptSecrets($stmt->fetch() ?: null);
    }

    public static function usernameExists(string $username): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM repo_users WHERE username = :u');
        $stmt->execute([':u' => $username]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function emailExists(string $email): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM repo_users WHERE email = :e');
        $stmt->execute([':e' => $email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(string $username, string $fullName, string $email, string $password, string $organization = ''): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO repo_users (username, full_name, organization, email, password_hash, created_at)
             VALUES (:u, :f, :o, :e, :p, NOW())'
        );
        $stmt->execute([
            ':u' => $username,
            ':f' => $fullName,
            ':o' => $organization,
            ':e' => $email,
            ':p' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function updatePassword(int $id, string $newPassword): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET password_hash = :p WHERE id = :id');
        $stmt->execute([
            ':p' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            ':id' => $id,
        ]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $active ? 1 : 0, ':id' => $id]);
    }

    public static function enableTotp(int $id, string $secret): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id');
        $stmt->execute([':s' => SecretBox::encrypt($secret, 'repo_users.totp_secret'), ':id' => $id]);
    }

    /**
     * Одноразовое использование шага TOTP: принимает шаг только один раз и
     * только вперёд. UPDATE атомарен, поэтому две параллельные попытки с
     * одним кодом не пройдут обе. Возвращает true, если код можно засчитать.
     *
     * Колонки может не быть: код выложили, а `php database/migrate.php` не
     * запустили. Ронять на этом второй фактор нельзя — владелец останется
     * заперт снаружи админки, и чинить придётся через доступ к БД. Поэтому
     * недостающая колонка возвращает поведение до защиты (код принимается),
     * громко пишет в security-лог, и только.
     */
    public static function consumeTotpStep(int $id, int $step): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE repo_users SET totp_last_step = :step
                 WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :guard)'
            );
            // Один и тот же именованный параметр дважды PDO не принимает
            // при нативных prepared statements — отсюда отдельный :guard.
            $stmt->execute([':step' => $step, ':id' => $id, ':guard' => $step]);

            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            if (!self::missingReplayColumn($e)) {
                throw $e;
            }

            Logger::security(
                'Нет колонки repo_users.totp_last_step — защита от повтора кода TOTP отключена. '
                . 'Примените миграции: php database/migrate.php',
                ['user_id' => $id]
            );

            return true;
        }
    }

    /** Отсутствующая колонка (42S22/1054), а не любая ошибка запроса. */
    private static function missingReplayColumn(\PDOException $e): bool
    {
        return $e->getCode() === '42S22' && str_contains($e->getMessage(), 'totp_last_step');
    }

    public static function disableTotp(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Привязка/отвязка Telegram для 2FA (null — отвязать). */
    public static function setTelegramChatId(int $id, ?int $chatId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET telegram_chat_id = :cid WHERE id = :id');
        $stmt->execute([':cid' => $chatId, ':id' => $id]);
    }

    public static function touchLastLogin(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM repo_users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private static function decryptSecrets(?array $row): ?array
    {
        if ($row !== null && array_key_exists('totp_secret', $row)) {
            $row['totp_secret'] = SecretBox::decrypt($row['totp_secret'] !== null ? (string) $row['totp_secret'] : null, 'repo_users.totp_secret');
        }

        return $row;
    }
}
