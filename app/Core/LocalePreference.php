<?php

declare(strict_types=1);

namespace App\Core;

/** Хранит явно выбранный посетителем язык без запуска PHP-сессии. */
final class LocalePreference
{
    public const COOKIE = 'site_lang';
    public const QUERY = '_lang';
    private static bool $changed = false;

    /** @param string[] $activeCodes */
    public static function requestedCode(string $uri, array $activeCodes): ?string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $rawCode = $query[self::QUERY] ?? null;
        if (!is_string($rawCode)) {
            return null;
        }
        $code = strtolower(trim($rawCode));

        return $code !== '' && in_array($code, $activeCodes, true) ? $code : null;
    }

    /** @param array<string, mixed> $cookies @param string[] $activeCodes */
    public static function storedCode(array $cookies, array $activeCodes): ?string
    {
        $code = strtolower(trim(is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : ''));

        return $code !== '' && in_array($code, $activeCodes, true) ? $code : null;
    }

    /** Возвращает query-часть без служебного параметра переключения. */
    public static function querySuffix(string $uri): string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        unset($query[self::QUERY]);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $encoded === '' ? '' : '?' . $encoded;
    }

    public static function remember(string $code): void
    {
        $_COOKIE[self::COOKIE] = $code;
        self::$changed = true;
        if (PHP_SAPI === 'cli') {
            return;
        }

        setcookie(self::COOKIE, $code, [
            'expires' => time() + 31536000,
            'path' => '/',
            'domain' => '',
            'secure' => RequestUrl::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function forget(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::$changed = true;
        if (PHP_SAPI === 'cli') {
            return;
        }

        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => RequestUrl::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function changedThisRequest(): bool
    {
        return self::$changed;
    }

    /** Языковое предпочтение применяется только к страницам публичного сайта. */
    public static function managesPath(string $path): bool
    {
        foreach (['/admin', '/repo', '/install', '/assets', '/uploads', '/health',
            '/manifest.webmanifest', '/sitemap.xml', '/robots.txt'] as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }

        return true;
    }
}
