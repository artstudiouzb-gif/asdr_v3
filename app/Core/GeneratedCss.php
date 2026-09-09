<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Publishes dynamic site CSS as immutable, content-addressed files.
 *
 * Theme-builder values are still read from the database, but they no longer
 * have to be embedded into every HTML response as style attributes or
 * <style> blocks. A changed configuration produces a new filename, so browser
 * and CDN caches can keep the file indefinitely without serving stale CSS.
 */
final class GeneratedCss
{
    private const PUBLIC_DIR = '/public/uploads/public/generated-css';
    private const PUBLIC_URL = '/uploads/public/generated-css';

    public static function publish(string $css, string $scope = 'site'): ?string
    {
        $css = trim(str_replace(["\r\n", "\r"], "\n", $css));
        if ($css === '') {
            return null;
        }

        $scope = preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'site';
        $hash = substr(hash('sha256', $css), 0, 20);
        $filename = $scope . '-' . $hash . '.css';
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $directory = $root . self::PUBLIC_DIR;
        $path = $directory . '/' . $filename;

        if (!is_file($path)) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                Logger::error('Не удалось создать каталог сгенерированных CSS.');
                return null;
            }

            $temporary = $directory . '/.' . $filename . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $payload = "/* Generated from validated CMS design settings. */\n" . $css . "\n";
            if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
                @unlink($temporary);
                Logger::error('Не удалось опубликовать сгенерированный CSS.');
                return null;
            }
            @chmod($path, 0644);
            self::collectExpired($directory, $scope, $filename);
        }

        return Asset::url(self::PUBLIC_URL . '/' . $filename);
    }

    private static function collectExpired(string $directory, string $scope, string $current): void
    {
        $cutoff = time() - 30 * 86400;
        foreach (glob($directory . '/' . $scope . '-*.css') ?: [] as $candidate) {
            if (basename($candidate) !== $current && (int) @filemtime($candidate) < $cutoff) {
                @unlink($candidate);
            }
        }
    }
}
