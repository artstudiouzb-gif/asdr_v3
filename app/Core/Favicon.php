<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ссылки на файлы иконок сайта. Раньше три <link> печатались всегда, а самих
 * файлов в public/ нет — каждая страница тянула три ответа 404. Здесь ссылка
 * появляется только для файла, который реально существует.
 */
final class Favicon
{
    /** Файл в public/ => готовый тег. */
    private const FILES = [
        '/apple-touch-icon.png' => '<link rel="apple-touch-icon" sizes="180x180" href="%s">',
        '/favicon-32x32.png' => '<link rel="icon" type="image/png" sizes="32x32" href="%s">',
        '/favicon-16x16.png' => '<link rel="icon" type="image/png" sizes="16x16" href="%s">',
    ];

    /**
     * Теги для статичных иконок, залитых в public/.
     *
     * @return list<string>
     */
    public static function staticIcons(): array
    {
        $root = dirname(__DIR__, 2) . '/public';
        $tags = [];
        foreach (self::FILES as $path => $tag) {
            if (is_file($root . $path)) {
                $tags[] = sprintf($tag, $path);
            }
        }

        return $tags;
    }

    /** Тег для фавиконки, загруженной в настройках; '' если её нет. */
    public static function settingTag(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $type = str_ends_with(strtolower($url), '.svg') ? 'image/svg+xml' : 'image/png';

        return '<link rel="icon" type="' . $type . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
    }
}
