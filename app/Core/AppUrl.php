<?php

declare(strict_types=1);

namespace App\Core;

/** Канонический публичный origin приложения для HTML, sitemap и уведомлений. */
final class AppUrl
{
    public static function base(): string
    {
        $configured = rtrim(trim((string) Config::get('app.url', '')), '/');
        if ($configured === '') {
            return PHP_SAPI === 'cli' ? '' : RequestUrl::origin();
        }

        $parts = parse_url($configured);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return PHP_SAPI === 'cli' ? '' : RequestUrl::origin();
        }

        // Типичная схема nginx -> PHP-FPM: в config остался http, а внешний
        // запрос уже HTTPS. Повышаем только схему; host берём из config, а не
        // из пользовательского HTTP_HOST.
        return self::normalize($configured, RequestUrl::isHttps());
    }

    public static function normalize(string $configured, bool $requestIsHttps): string
    {
        $configured = rtrim(trim($configured), '/');
        $parts = parse_url($configured);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $configured;
        }

        if ($requestIsHttps && strtolower((string) $parts['scheme']) === 'http') {
            return 'https://' . self::authority($parts) . rtrim((string) ($parts['path'] ?? ''), '/');
        }

        return $configured;
    }

    /** @param array<string, mixed> $parts */
    private static function authority(array $parts): string
    {
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
