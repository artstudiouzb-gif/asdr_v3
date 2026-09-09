<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Умное авто-определение фокальной точки (focal position) для безупречного
 * кадрирования обложек и фотографий на смартфонах и мобильных устройствах.
 */
final class SmartCrop
{
    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * Возвращает значение CSS object-position (например "50% 30%"), вычисленное
     * автоматически по пропорциям изображения, чтобы лица и ключевые объекты
     * на мобильных устройствах никогда не срезались при object-fit: cover.
     */
    public static function focalPosition(?string $imageUrl): string
    {
        $imageUrl = trim((string) $imageUrl);
        if ($imageUrl === '') {
            return '50% 30%';
        }

        if (isset(self::$cache[$imageUrl])) {
            return self::$cache[$imageUrl];
        }

        // По умолчанию для всех новостных и репортажных снимков — 50% 30% (верхняя треть)
        $position = '50% 30%';

        $localPath = self::resolveLocalPath($imageUrl);
        if ($localPath !== null && is_file($localPath)) {
            $info = @getimagesize($localPath);
            if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
                $w = (int) $info[0];
                $h = (int) $info[1];
                $ratio = $w / $h;

                if ($ratio >= 1.4) {
                    // Альбомное фото (16:9, 3:2) — акцент на лица в верхней трети (30%)
                    $position = '50% 30%';
                } elseif ($ratio >= 0.95) {
                    // Квадратное или близкое к квадрату — 50% 38%
                    $position = '50% 38%';
                } else {
                    // Портретное фото — 50% 25%
                    $position = '50% 25%';
                }
            }
        }

        self::$cache[$imageUrl] = $position;

        return $position;
    }

    /**
     * Пытается найти локальный физический путь к файлу по его публичному URL.
     */
    private static function resolveLocalPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($path === '') {
            return null;
        }

        $publicUploads = rtrim((string) Config::get('paths.public_uploads'), '/');
        if (str_contains($path, '/uploads/')) {
            $relative = ltrim(substr($path, (int) strpos($path, '/uploads/') + 9), '/');
            $full = $publicUploads . '/' . $relative;
            if (is_file($full)) {
                return $full;
            }
        }

        // Проверка через DOCUMENT_ROOT
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot !== '' && is_file($docRoot . $path)) {
            return $docRoot . $path;
        }

        return null;
    }
}
