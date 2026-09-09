<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Publishes dynamic site JS as immutable, content-addressed files.
 *
 * Prevents inline script tags and inline handlers in HTML, maintaining compliance
 * with strict Content-Security-Policy (CSP) headers without requiring inline script nonces.
 */
final class GeneratedJs
{
    private const PUBLIC_DIR = '/public/uploads/public/generated-js';
    private const PUBLIC_URL = '/uploads/public/generated-js';

    public static function publish(string $js, string $scope = 'page'): ?string
    {
        $js = trim(str_replace(["\r\n", "\r"], "\n", $js));
        if ($js === '') {
            return null;
        }

        $scope = preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'page';
        $hash = substr(hash('sha256', $js), 0, 20);
        $filename = $scope . '-' . $hash . '.js';
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $directory = $root . self::PUBLIC_DIR;
        $path = $directory . '/' . $filename;

        if (!is_file($path)) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                Logger::error('Не удалось создать каталог сгенерированных JS.');
                return null;
            }

            $temporary = $directory . '/.' . $filename . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $payload = "/* Generated page script. */\n" . $js . "\n";
            if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
                @unlink($temporary);
                Logger::error('Не удалось опубликовать сгенерированный JS.');
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
        foreach (glob($directory . '/' . $scope . '-*.js') ?: [] as $candidate) {
            if (basename($candidate) !== $current && (int) @filemtime($candidate) < $cutoff) {
                @unlink($candidate);
            }
        }
    }
}
