<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Валидаторы значений настроек (задача 116). Приводят пользовательский ввод к
 * строгим форматам, исключая внедрение сырого HTML/JS в поля аналитики и т.п.
 */
final class SettingsValidator
{
    /** Google Analytics Measurement ID: G-XXXXXXXXXX. Иначе — пустая строка. */
    public static function gaId(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $value) ? $value : '';
    }

    /** Яндекс.Метрика ID: только цифры. */
    public static function ymId(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4,12}$/', $value) ? $value : '';
    }

    /** HEX-цвет #rrggbb. При невалидном — $default. */
    public static function hexColor(string $value, string $default = '#1a1a1a'): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default;
    }

    /** Короткое имя PWA: обрезка до 12 символов, без управляющих символов. */
    public static function shortName(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F<>]/u', '', trim($value)) ?? '';
        return mb_substr($value, 0, 12);
    }

    /** Неотрицательное целое (например, срок хранения ПДн в днях). */
    public static function nonNegativeInt(string $value, int $default = 0): int
    {
        $value = trim($value);
        return ctype_digit($value) ? (int) $value : $default;
    }

    /** Целое число в заданном диапазоне; пустое поле получает default. */
    public static function boundedInt(string $value, int $min, int $max, int $default): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return max($min, min($max, $default));
        }
        if (!ctype_digit($value)) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    /** Однострочный текст без управляющих символов, ограниченный по длине. */
    public static function plainText(string $value, int $maxLength = 255): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return mb_substr($value, 0, max(1, $maxLength));
    }

    /** Пустой или корректный email. Невалидный ввод возвращает null. */
    public static function optionalEmail(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? mb_substr($value, 0, 254)
            : null;
    }

    /**
     * SMTP-порт в диапазоне TCP/UDP.
     * Пустое поле получает значение по умолчанию, неверное — null.
     */
    public static function port(string $value, int $default = 587): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        if (!ctype_digit($value)) {
            return null;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * Имя SMTP-хоста без схемы, пути, порта и управляющих символов.
     * Допускаются обычные DNS-имена, localhost и IP-адреса.
     */
    public static function smtpHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 253 || preg_match('/[\s\/\\\\:@?#\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Публичная база CDN: http(s), обязательный хост, без логина, query и
     * fragment. Путь допустим, чтобы поддержать CDN с префиксом каталога.
     */
    public static function publicBaseUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 500 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($value, '/');
    }

    /** Безопасное значение CSS (например, clamp(), px, rem). */
    public static function safeCssValue(string $value, string $default = ''): string
    {
        $value = trim($value);
        return preg_match('/^[a-zA-Z0-9%()., \-+]+$/', $value) ? $value : $default;
    }
}
