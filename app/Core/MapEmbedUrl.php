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

        // Обычная ссылка из адресной строки Яндекс Карт не является URL
        // виджета: /maps/... может запрещать показ внутри iframe. Кнопка
        // «Поделиться» также отдаёт короткую /maps/-/TOKEN, для которой
        // встраиваемый эквивалент — /map-widget/v1/-/TOKEN.
        if (self::isYandexHost($host)) {
            $url = self::normalizeYandex($url, $host);
        }

        return $url;
    }

    private static function isYandexHost(string $host): bool
    {
        return self::hostMatches($host, 'yandex.ru')
            || self::hostMatches($host, 'yandex.com')
            || self::hostMatches($host, 'yandex.uz');
    }

    private static function normalizeYandex(string $url, string $host): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        // Уже готовые URL виджета и frame API менять нельзя.
        if (str_contains($path, '/map-widget/v1/')
            || str_contains($path, '/frame/v1/')
            || str_contains($path, '/services/constructor/')) {
            return $url;
        }

        $query = (string) ($parts['query'] ?? '');
        $suffix = $query !== '' ? '?' . $query : '';

        if (preg_match('#^/maps/-/([^/]+)$#', rtrim($path, '/'), $match)) {
            return 'https://' . $host . '/map-widget/v1/-/' . $match[1] . $suffix;
        }

        if (preg_match('#^/maps(?:/|$)#', $path)) {
            $params = [];
            if ($query !== '') {
                parse_str($query, $params);
            }

            // У ссылки на карточку организации идентификатор хранится в пути,
            // тогда как виджет ждёт его параметром oid.
            if (preg_match('#^/maps/org/[^/]+/(\\d+)(?:/|$)#', $path, $match)) {
                $params += [
                    'oid' => $match[1],
                    'ol' => 'biz',
                    'mode' => 'search',
                ];
            }

            $widgetQuery = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

            return 'https://' . $host . '/map-widget/v1/' . ($widgetQuery !== '' ? '?' . $widgetQuery : '');
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
