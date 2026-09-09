<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Нормализует URL карты и не позволяет встраивать произвольный внешний сайт.
 */
final class MapEmbedUrl
{
    /** @var list<string> */
    private const HOST_SUFFIXES = [
        'google.com',
        'google.uz',
        'yandex.ru',
        'yandex.com',
        'yandex.uz',
        'openstreetmap.org',
        '2gis.com',
        '2gis.ru',
        '2gis.uz',
    ];

    public static function normalize(mixed $value): string
    {
        $url = trim(is_scalar($value) ? (string) $value : '');
        if (preg_match('/src=["\'](https:\/\/[^"\']+)["\']/i', $url, $matches)) {
            $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        if (!self::isAllowed($url)) {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (self::hostMatches($host, 'google.com') || self::hostMatches($host, 'google.uz')) {
            if (str_contains($url, '/maps/place/')) {
                $lat = null;
                $lng = null;
                if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $match)) {
                    $lat = $match[1];
                    $lng = $match[2];
                } elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $match)) {
                    $lat = $match[1];
                    $lng = $match[2];
                }
                if ($lat !== null && $lng !== null) {
                    $url = 'https://maps.google.com/maps?ll=' . $lat . ',' . $lng . '&z=18&output=embed';
                }
            }
            if (!str_contains($url, 'output=embed') && !str_contains($url, '/embed')) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'output=embed';
            }
            if (!str_contains($url, 'iwloc=')) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'iwloc=near';
            }
        }

        return $url;
    }

    private static function isAllowed(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || empty($parts['host'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        foreach (self::HOST_SUFFIXES as $suffix) {
            if (self::hostMatches($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function hostMatches(string $host, string $suffix): bool
    {
        return $host === $suffix || str_ends_with($host, '.' . $suffix);
    }
}
