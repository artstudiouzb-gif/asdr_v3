<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Версионирование статических ассетов для инвалидации кэша CDN (задача 127).
 * К URL добавляется отпечаток содержимого (?v=hash), поэтому после деплоя новой
 * версии файла CDN отдаёт его как новый ресурс без ручной очистки кэша.
 * Отпечаток берётся из mtime+size файла (быстро, без чтения содержимого).
 */
final class Asset
{
    /** @var array<string,string> */
    private static array $cache = [];
    private static ?string $cdnBase = null;

    public static function url(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $cacheKey = $path;
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $publicRoot = $root . '/public';

        $out = $path;
        $signature = '';
        $stat = @stat($publicRoot . $path);
        if ($stat !== false && is_file($publicRoot . $path)) {
            $signature = $path . ':' . $stat['mtime'] . '-' . $stat['size'] . ';';
        }
        if ($signature !== '') {
            $v = substr(hash('crc32b', $signature), 0, 8);
            $out = $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $v;
        }

        // CDN-префикс из настроек производительности (пусто — отдаём с этого же
        // домена). Применяется к версионированному URL статики.
        $cdn = self::cdnBase();
        if ($cdn !== '') {
            $out = $cdn . $out;
        }

        return self::$cache[$cacheKey] = $out;
    }

    public static function cdnBase(): string
    {
        if (self::$cdnBase === null) {
            try {
                self::$cdnBase = rtrim((string) \App\Models\Setting::get('perf_cdn_url', ''), '/');
            } catch (\Throwable) {
                self::$cdnBase = '';
            }
        }

        return self::$cdnBase;
    }

    /**
     * Переписать в готовом HTML ссылки на публичные загрузки (/uploads/public/…)
     * на CDN-хост. Это позволяет раздавать картинки/видео через pull-zone CDN
     * без переноса домена: CDN тянет файл с origin по этому пути и кэширует.
     *
     * Совпадение якорится по предшествующему символу (кавычка, скобка, запятая,
     * пробел), поэтому абсолютные URL (og:image, canonical) не затрагиваются.
     * Статика (/assets/…) уже отдаётся через Asset::url() и здесь не трогается.
     */
    public static function rewriteMedia(string $html): string
    {
        $cdn = self::cdnBase();
        if ($cdn === '' || $html === '') {
            return $html;
        }

        return (string) preg_replace(
            '#(["\'(,\s])/uploads/public/#',
            '$1' . $cdn . '/uploads/public/',
            $html
        );
    }
}
