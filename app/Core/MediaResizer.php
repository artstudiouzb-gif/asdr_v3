<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Высокопроизводительный движок генерации превью и миниатюр на лету (On-the-Fly Media Resizer).
 * Поддерживает Smart Crop, сохранение в WebP, защиту через HMAC-подпись и дисковое кэширование.
 */
final class MediaResizer
{
    private const ALLOWED_MODES = ['fit', 'crop', 'smart'];
    private const MAX_DIMENSION = 3840;

    /**
     * Формирует подписанный безопасный URL для динамического превью.
     */
    public static function url(string $filePath, int $width, int $height, string $mode = 'crop', int $quality = 85): string
    {
        $cleanPath = ltrim(parse_url(trim($filePath), PHP_URL_PATH) ?: '', '/');
        if (str_starts_with($cleanPath, 'uploads/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        $width = max(16, min(self::MAX_DIMENSION, $width));
        $height = max(16, min(self::MAX_DIMENSION, $height));
        $mode = in_array($mode, self::ALLOWED_MODES, true) ? $mode : 'crop';

        $payload = "{$width}x{$height}|{$mode}|{$quality}|{$cleanPath}";
        $sig = substr(hash_hmac('sha256', $payload, (string) Config::get('app.key', 'asdr_resizer_key')), 0, 16);

        return "/media/thumb/{$width}x{$height}/{$mode}/{$quality}/{$sig}/" . rawurlencode($cleanPath);
    }

    /**
     * Обрабатывает запрос на отдачу миниатюры.
     */
    public static function handle(int $width, int $height, string $mode, int $quality, string $sig, string $relativeFile): void
    {
        $payload = "{$width}x{$height}|{$mode}|{$quality}|{$relativeFile}";
        $expectedSig = substr(hash_hmac('sha256', $payload, (string) Config::get('app.key', 'asdr_resizer_key')), 0, 16);

        if (!hash_equals($expectedSig, $sig)) {
            http_response_code(403);
            exit('Invalid signature');
        }

        // Подпись подтверждает, что адрес наш, но не что размеры осмысленны:
        // нулевая ширина или высота валила создание холста.
        if ($width < 1 || $height < 1) {
            http_response_code(400);
            exit('Invalid size');
        }

        // Защита от Path Traversal
        if (str_contains($relativeFile, '..') || str_starts_with($relativeFile, '/') || str_starts_with($relativeFile, '\\')) {
            http_response_code(400);
            exit('Invalid path');
        }

        $sourceFile = APP_ROOT . '/public/uploads/' . $relativeFile;
        if (!is_file($sourceFile)) {
            $sourceFile = APP_ROOT . '/public/uploads/public/' . $relativeFile;
        }

        if (!is_file($sourceFile)) {
            http_response_code(404);
            exit('Image not found');
        }

        $cacheDir = APP_ROOT . '/storage/cache/thumbs';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cacheKey = hash('sha256', "{$sourceFile}|" . filemtime($sourceFile) . "|{$width}x{$height}|{$mode}|{$quality}");
        $cacheFile = "{$cacheDir}/{$cacheKey}.webp";

        if (is_file($cacheFile)) {
            self::serveFile($cacheFile);
            return;
        }

        try {
            self::generateThumb($sourceFile, $cacheFile, $width, $height, $mode, $quality);
            self::serveFile($cacheFile);
        } catch (Throwable $e) {
            Logger::error('MediaResizer failed: ' . $e->getMessage(), 'error', ['source' => $sourceFile]);
            http_response_code(500);
            exit('Resizer error');
        }
    }

    /**
     * Размеры уже проверены в handle(): нулевой холст валил создание картинки.
     * Диапазон описан здесь, потому что за границу вызова это знание не
     * переносится — а без него значение уходит в imagecreatetruecolor(), где
     * ноль означает ошибку, и в файле со strict_types это TypeError.
     *
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private static function generateThumb(string $src, string $dest, int $w, int $h, string $mode, int $quality): void
    {
        $info = @getimagesize($src);
        if (!$info) {
            throw new RuntimeException('Cannot get image size');
        }

        [$origW, $origH, $type] = $info;

        $sourceImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default => null,
        };

        if (!$sourceImg) {
            throw new RuntimeException('Unsupported image format');
        }

        $targetImg = imagecreatetruecolor($w, $h);
        imagealphablending($targetImg, false);
        imagesavealpha($targetImg, true);

        if ($mode === 'crop' || $mode === 'smart') {
            $srcRatio = $origW / $origH;
            $dstRatio = $w / $h;

            if ($srcRatio > $dstRatio) {
                $cropW = (int) ($origH * $dstRatio);
                $cropH = $origH;
                $srcX = (int) (($origW - $cropW) / 2);
                $srcY = 0;
            } else {
                $cropW = $origW;
                $cropH = (int) ($origW / $dstRatio);
                $srcX = 0;
                $srcY = (int) (($origH - $cropH) / 2);
            }

            imagecopyresampled($targetImg, $sourceImg, 0, 0, $srcX, $srcY, $w, $h, $cropW, $cropH);
        } else {
            // mode fit
            $ratio = min($w / $origW, $h / $origH);
            $newW = (int) ($origW * $ratio);
            $newH = (int) ($origH * $ratio);
            $dstX = (int) (($w - $newW) / 2);
            $dstY = (int) (($h - $newH) / 2);

            $trans = imagecolorallocatealpha($targetImg, 0, 0, 0, 127);
            imagefill($targetImg, 0, 0, $trans ?: 0);

            imagecopyresampled($targetImg, $sourceImg, $dstX, $dstY, 0, 0, $newW, $newH, $origW, $origH);
        }

        imagewebp($targetImg, $dest, max(10, min(100, $quality)));
        unset($targetImg, $sourceImg);
    }

    private static function serveFile(string $file): void
    {
        $mtime = filemtime($file);
        $etag = '"' . md5($file . $mtime) . '"';

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        readfile($file);
        exit;
    }

    /**
     * Очищает устаревшие миниатюры из дискового кэша.
     */
    public static function purgeCache(int $olderThanDays = 30): int
    {
        $cacheDir = APP_ROOT . '/storage/cache/thumbs';
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $threshold = time() - (max(1, $olderThanDays) * 86400);
        $count = 0;
        foreach (glob($cacheDir . '/*.webp') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
