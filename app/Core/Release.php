<?php

declare(strict_types=1);

namespace App\Core;

final class Release
{
    public static function id(): string
    {
        $environment = trim((string) (getenv('APP_RELEASE') ?: ''));
        if ($environment !== '') {
            return mb_substr($environment, 0, 100);
        }

        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $file = $root . '/storage/release.json';
        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            $release = is_array($data) ? trim((string) ($data['release'] ?? '')) : '';
            if ($release !== '') {
                return mb_substr($release, 0, 100);
            }
        }

        return 'unknown';
    }
}
