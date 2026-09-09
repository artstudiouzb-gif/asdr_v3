<?php

declare(strict_types=1);

namespace App\Core;

final class Format
{
    public static function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' КБ';
        }

        return round($bytes / (1024 * 1024), 1) . ' МБ';
    }
}
