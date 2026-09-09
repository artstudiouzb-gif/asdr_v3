<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\NotificationSchema;

final class NotificationPreference
{
    private const SEVERITIES = ['info', 'success', 'warning', 'error', 'critical', 'security'];
    private const DIGESTS = ['none', 'daily', 'weekly'];

    /** @return array{email_enabled:bool,telegram_enabled:bool,minimum_severity:string,quiet_start:?string,quiet_end:?string,digest_mode:string} */
    public static function forUser(int $userId): array
    {
        NotificationSchema::ensure();
        $stmt = Database::pdo()->prepare(
            'SELECT email_enabled, telegram_enabled, minimum_severity, quiet_start, quiet_end, digest_mode
             FROM notification_preferences WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::defaults();
        }

        return [
            'email_enabled' => (bool) $row['email_enabled'],
            'telegram_enabled' => (bool) $row['telegram_enabled'],
            'minimum_severity' => self::normalizeSeverity((string) $row['minimum_severity']),
            'quiet_start' => self::normalizeTime($row['quiet_start'] !== null ? (string) $row['quiet_start'] : null),
            'quiet_end' => self::normalizeTime($row['quiet_end'] !== null ? (string) $row['quiet_end'] : null),
            'digest_mode' => self::normalizeDigest((string) $row['digest_mode']),
        ];
    }

    /** @param array<string,mixed> $input */
    public static function save(int $userId, array $input): void
    {
        NotificationSchema::ensure();
        $minimum = self::normalizeSeverity((string) ($input['minimum_severity'] ?? 'warning'));
        $quietStart = self::normalizeTime(isset($input['quiet_start']) ? (string) $input['quiet_start'] : null);
        $quietEnd = self::normalizeTime(isset($input['quiet_end']) ? (string) $input['quiet_end'] : null);
        $digest = self::normalizeDigest((string) ($input['digest_mode'] ?? 'none'));

        if (($quietStart === null) !== ($quietEnd === null)) {
            throw new \InvalidArgumentException('Укажите и начало, и окончание тихих часов.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO notification_preferences
                (user_id, email_enabled, telegram_enabled, minimum_severity, quiet_start, quiet_end, digest_mode, updated_at)
             VALUES (:uid, :email, :telegram, :minimum, :quiet_start, :quiet_end, :digest, NOW())
             ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                telegram_enabled = VALUES(telegram_enabled),
                minimum_severity = VALUES(minimum_severity),
                quiet_start = VALUES(quiet_start),
                quiet_end = VALUES(quiet_end),
                digest_mode = VALUES(digest_mode),
                updated_at = NOW()'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':email' => !empty($input['email_enabled']) ? 1 : 0,
            ':telegram' => !empty($input['telegram_enabled']) ? 1 : 0,
            ':minimum' => $minimum,
            ':quiet_start' => $quietStart,
            ':quiet_end' => $quietEnd,
            ':digest' => $digest,
        ]);
    }

    public static function allowsSeverity(array $preference, string $severity): bool
    {
        $rank = [
            'info' => 10,
            'success' => 10,
            'warning' => 20,
            'error' => 30,
            'security' => 40,
            'critical' => 50,
        ];

        return ($rank[self::normalizeSeverity($severity)] ?? 10)
            >= ($rank[self::normalizeSeverity((string) ($preference['minimum_severity'] ?? 'warning'))] ?? 20);
    }

    public static function isQuietNow(array $preference, ?\DateTimeImmutable $now = null): bool
    {
        $start = self::normalizeTime(isset($preference['quiet_start']) ? (string) $preference['quiet_start'] : null);
        $end = self::normalizeTime(isset($preference['quiet_end']) ? (string) $preference['quiet_end'] : null);
        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $current = ($now ?? new \DateTimeImmutable('now'))->format('H:i:s');
        if ($start < $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }

    /** @return array{email_enabled:bool,telegram_enabled:bool,minimum_severity:string,quiet_start:?string,quiet_end:?string,digest_mode:string} */
    private static function defaults(): array
    {
        return [
            'email_enabled' => false,
            'telegram_enabled' => false,
            'minimum_severity' => 'warning',
            'quiet_start' => null,
            'quiet_end' => null,
            'digest_mode' => 'none',
        ];
    }

    private static function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, self::SEVERITIES, true) ? $severity : 'warning';
    }

    private static function normalizeDigest(string $digest): string
    {
        $digest = strtolower(trim($digest));
        return in_array($digest, self::DIGESTS, true) ? $digest : 'none';
    }

    private static function normalizeTime(?string $time): ?string
    {
        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) !== 1) {
            throw new \InvalidArgumentException('Некорректное время тихих часов.');
        }

        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
