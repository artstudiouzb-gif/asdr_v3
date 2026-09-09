<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Генератор динамических брендированных OpenGraph-карточек 1200x630 для соцсетей (Telegram, VK, FB, Twitter).
 */
final class OpenGraphImage
{
    /**
     * Генерирует или отдает из кэша брендированную OG-обложку.
     */
    public static function render(string $title, string $category = 'Новость', string $date = ''): void
    {
        $cacheDir = APP_ROOT . '/storage/cache/og';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $hash = hash('sha256', "{$title}|{$category}|{$date}");
        $cacheFile = "{$cacheDir}/{$hash}.png";

        if (is_file($cacheFile)) {
            self::serveImage($cacheFile);
            return;
        }

        try {
            $width = 1200;
            $height = 630;
            $img = imagecreatetruecolor($width, $height);

            // Цветовая палитра: глубокий темно-синий (#0B132B) + акцентный бирюзовый (#0D9488)
            $bg = imagecolorallocate($img, 11, 19, 43) ?: 0;
            $cardBg = imagecolorallocate($img, 19, 35, 63) ?: 0;
            $textWhite = imagecolorallocate($img, 255, 255, 255) ?: 0;
            $textMuted = imagecolorallocate($img, 148, 163, 184) ?: 0;
            $accent = imagecolorallocate($img, 13, 148, 136) ?: 0;

            imagefilledrectangle($img, 0, 0, $width, $height, $bg);

            // Карточка с субпиксельным бордером
            imagefilledrectangle($img, 60, 60, $width - 60, $height - 60, $cardBg);

            // Акцентная плашка
            imagefilledrectangle($img, 60, 60, 68, $height - 60, $accent);

            // Бренд организации
            $brand = (string) Config::get('app.name', 'Республиканский портал');
            imagestring($img, 5, 100, 100, mb_strtoupper($brand), $accent);

            // Рубрика и дата
            $meta = trim("{$category}  •  {$date}");
            if ($meta !== '') {
                imagestring($img, 4, 100, 140, $meta, $textMuted);
            }

            // Заголовок (wordwrap)
            $wrapped = wordwrap(mb_substr($title, 0, 140), 45, "\n");
            $lines = explode("\n", $wrapped);
            $y = 220;
            foreach (array_slice($lines, 0, 4) as $line) {
                imagestring($img, 5, 100, $y, $line, $textWhite);
                $y += 40;
            }

            // Нижний водяной знак
            imagestring($img, 3, 100, 520, 'Официальный источник информации', $textMuted);

            imagepng($img, $cacheFile, 6);
            unset($img);

            self::serveImage($cacheFile);
        } catch (Throwable $e) {
            Logger::error('OpenGraphImage::render failed: ' . $e->getMessage());
            http_response_code(500);
            exit('OG image error');
        }
    }

    private static function serveImage(string $file): void
    {
        $mtime = filemtime($file);
        $etag = '"' . md5($file . $mtime) . '"';

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=604800, immutable');
        header('ETag: ' . $etag);
        readfile($file);
        exit;
    }

    /**
     * Очищает устаревшие сгенерированные OG-карточки.
     */
    public static function purgeCache(int $olderThanDays = 30): int
    {
        $cacheDir = APP_ROOT . '/storage/cache/og';
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $threshold = time() - (max(1, $olderThanDays) * 86400);
        $count = 0;
        foreach (glob($cacheDir . '/*.png') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
