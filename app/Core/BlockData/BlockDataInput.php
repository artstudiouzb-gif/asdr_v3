<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\TextProcessor;
use App\Core\UrlGuard;

/**
 * Общие безопасные преобразования полей формы для нормализаторов блоков.
 */
final class BlockDataInput
{
    /** @param array<string, mixed> $input */
    public static function plain(array $input, string $field, string $locale): string
    {
        return TextProcessor::typographPlain(self::trimmed($input, $field), $locale);
    }

    /** @param array<string, mixed> $input */
    public static function trimmed(array $input, string $field): string
    {
        return trim(self::scalarString($input[$field] ?? null));
    }

    public static function safeLink(mixed $value): string
    {
        $url = trim(self::scalarString($value));
        return $url !== '' && UrlGuard::isSafeLink($url) ? $url : '';
    }

    public static function safeMedia(mixed $value): string
    {
        $url = trim(self::scalarString($value));

        return $url !== '' && UrlGuard::isSafeMedia($url) ? $url : '';
    }

    /**
     * Значение из закрытого списка. Чужое или отсутствующее заменяется
     * умолчанием: в data блока не должно попадать ничего, чего не знает шаблон.
     *
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    public static function enum(array $input, string $field, array $allowed, string $default): string
    {
        $value = self::trimmed($input, $field);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** @param array<string, mixed> $input */
    public static function int(array $input, string $field, int $min, int $max, int $default): int
    {
        if (!isset($input[$field]) || !is_scalar($input[$field]) || self::trimmed($input, $field) === '') {
            return $default;
        }

        return max($min, min($max, (int) $input[$field]));
    }

    /** @param array<string, mixed> $input */
    public static function optionalColor(array $input, string $field): string
    {
        if (!empty($input[$field . '_off'])) {
            return '';
        }

        $value = self::trimmed($input, $field);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '';
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
