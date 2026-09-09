<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small atomic limiter for request throttles.
 *
 * Unlike the login-attempt audit table, routine public throttles do not need
 * to write to MySQL on every request. This local limiter protects the database
 * from becoming the bottleneck of its own abuse protection.
 */
final class FileRateLimiter
{
    public static function allow(string $identifier, int $maxAttempts, int $windowSeconds): ?bool
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $dir = APP_ROOT . '/storage/cache/rate-limits';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        if (random_int(1, 1000) === 1) {
            self::collectExpired($dir);
        }

        $path = $dir . '/' . hash('sha256', $identifier) . '.limit';
        $handle = @fopen($path, 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($state) || (int) ($state['expires'] ?? 0) <= $now) {
                $state = ['count' => 0, 'expires' => $now + $windowSeconds];
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);

            return $state['count'] <= $maxAttempts;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function forget(string $identifier): void
    {
        $path = APP_ROOT . '/storage/cache/rate-limits/' . hash('sha256', $identifier) . '.limit';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function collectExpired(string $dir): void
    {
        $checked = 0;
        foreach (glob($dir . '/*.limit') ?: [] as $path) {
            if (++$checked > 200) {
                break;
            }
            $state = json_decode((string) @file_get_contents($path), true);
            if (!is_array($state) || (int) ($state['expires'] ?? 0) <= time()) {
                @unlink($path);
            }
        }
    }
}
