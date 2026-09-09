<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Версионированное authenticated encryption для секретов, хранящихся в БД.
 * Незашифрованные значения читаются для бесшовной миграции старых установок,
 * но все новые записи требуют корректный 256-битный ключ.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain, string $context): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::key('crypto.encryption_key');
        if ($key === null) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY должен содержать 32 байта (64 hex-символа или base64).');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($context),
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Не удалось зашифровать секрет.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(?string $stored, string $context): ?string
    {
        if ($stored === null || $stored === '' || !self::isEncrypted($stored)) {
            return $stored;
        }

        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new \RuntimeException('Повреждённый формат зашифрованного секрета.');
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        foreach (['crypto.encryption_key', 'crypto.previous_encryption_key'] as $configKey) {
            $key = self::key($configKey);
            if ($key === null) {
                continue;
            }
            $plain = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                self::aad($context)
            );
            if ($plain !== false) {
                return $plain;
            }
        }

        throw new \RuntimeException('Секрет не расшифрован: проверьте APP_ENCRYPTION_KEY и APP_PREVIOUS_ENCRYPTION_KEY.');
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function hasValidCurrentKey(): bool
    {
        return self::key('crypto.encryption_key') !== null;
    }

    private static function aad(string $context): string
    {
        return 'asdr-cms|' . $context;
    }

    private static function key(string $configKey): ?string
    {
        $raw = trim((string) Config::get($configKey, ''));
        if (preg_match('/^[a-f0-9]{64}$/i', $raw) === 1) {
            return hex2bin($raw) ?: null;
        }
        $decoded = base64_decode($raw, true);

        return $decoded !== false && strlen($decoded) === 32 ? $decoded : null;
    }
}
