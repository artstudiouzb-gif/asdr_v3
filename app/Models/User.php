<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\SecretBox;

final class User
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT id, username, email, phone, role, admin_lang, last_login_at, created_at FROM users ORDER BY id ASC');

        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function delete(int $id): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function emailExists(string $email): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        return self::decryptSecrets($row ?: null);
    }

    /**
     * Память строк в пределах запроса: id → запись (null тоже запоминается).
     *
     * Учётку текущего пользователя спрашивают отовсюду — интерфейс панели,
     * RBAC, вьюхи, — и на странице админки выходило 3–4 одинаковых запроса.
     * Любая запись в таблицу сбрасывает память, поэтому «прочитали после
     * своей же правки» отдаёт свежую строку, а не прежнюю.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private static array $byIdMemo = [];

    public static function findById(int $id): ?array
    {
        if (array_key_exists($id, self::$byIdMemo)) {
            return self::$byIdMemo[$id];
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return self::$byIdMemo[$id] = self::decryptSecrets($row ?: null);
    }

    /** Сбрасывает память строк: данные пользователей изменились. */
    public static function forgetCache(): void
    {
        self::$byIdMemo = [];
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return self::decryptSecrets($row ?: null);
    }

    public static function updatePassword(int $id, string $newPassword): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            ':hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            ':id' => $id,
        ]);
    }

    public static function enableTotp(int $id, string $secret): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET totp_secret = :secret, totp_enabled = 1 WHERE id = :id');
        $stmt->execute([':secret' => SecretBox::encrypt($secret, 'users.totp_secret'), ':id' => $id]);
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
        // Поле одноразовости шага TOTP: прежняя строка в памяти означала бы,
        // что подсмотренный код снова считается неиспользованным.
        self::forgetCache();

        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE users SET totp_last_step = :step
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
                'Нет колонки users.totp_last_step — защита от повтора кода TOTP отключена. '
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
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Телефон (E.164) для кода входа через Telegram; null — вход без кода. */
    public static function updatePhone(int $id, ?string $phone): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET phone = :phone WHERE id = :id');
        $stmt->execute([':phone' => $phone, ':id' => $id]);
    }

    /** chat_id Telegram-бота для кодов входа; null — отвязать. */
    public static function updateTelegramChatId(int $id, ?int $chatId): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET telegram_chat_id = :cid WHERE id = :id');
        $stmt->execute([':cid' => $chatId, ':id' => $id]);
    }

    public static function touchLastLogin(int $id): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function updateAdminLang(int $id, ?string $adminLang): void
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare('UPDATE users SET admin_lang = :lang WHERE id = :id');
        $stmt->execute([':lang' => $adminLang, ':id' => $id]);
    }

    public static function create(string $username, string $email, string $password, string $role = 'admin', ?string $phone = null, ?string $adminLang = null): int
    {
        self::forgetCache();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (username, email, phone, password_hash, role, admin_lang, created_at)
             VALUES (:username, :email, :phone, :password, :role, :admin_lang, NOW())'
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':phone' => $phone,
            ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ':role' => $role,
            ':admin_lang' => $adminLang,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    private static function decryptSecrets(?array $row): ?array
    {
        if ($row !== null && array_key_exists('totp_secret', $row)) {
            $row['totp_secret'] = SecretBox::decrypt($row['totp_secret'] !== null ? (string) $row['totp_secret'] : null, 'users.totp_secret');
        }

        return $row;
    }
}
