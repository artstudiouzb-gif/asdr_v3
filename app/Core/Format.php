<?php

declare(strict_types=1);

namespace App\Core;

final class Format
{
    /**
     * @param array{0:string,1:string,2:string} $units подписи «Б/КБ/МБ»: на
     *        публичной странице их передают переведёнными — «МБ» кириллицей
     *        на узбекской латинице читается опечаткой.
     */
    public static function fileSize(int $bytes, array $units = ['Б', 'КБ', 'МБ']): string
    {
        if ($bytes < 1024) {
            return $bytes . ' ' . $units[0];
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' ' . $units[1];
        }

        return round($bytes / (1024 * 1024), 1) . ' ' . $units[2];
    }
}
