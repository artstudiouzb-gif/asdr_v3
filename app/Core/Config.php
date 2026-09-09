<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];

    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /** Поверхностное слияние в существующую конфигурацию (верхнеуровневые ключи). */
    public static function merge(array $data): void
    {
        self::$data = array_merge(self::$data, $data);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
