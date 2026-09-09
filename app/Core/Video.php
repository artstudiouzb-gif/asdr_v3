<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор ссылок на YouTube без обращения к серверам YouTube. Извлекает id
 * ролика из распространённых форматов URL и строит ссылки на обложку и
 * embed. Обложка используется как fallback в News::getCoverImage.
 */
final class Video
{
    /** Извлекает 11-символьный id ролика YouTube или null. */
    public static function youtubeId(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $patterns = [
            '#youtu\.be/([A-Za-z0-9_-]{11})#',
            '#youtube\.com/watch\?[^ ]*\bv=([A-Za-z0-9_-]{11})#',
            '#youtube\.com/embed/([A-Za-z0-9_-]{11})#',
            '#youtube\.com/shorts/([A-Za-z0-9_-]{11})#',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $url, $m)) {
                return $m[1];
            }
        }

        // Голый id.
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
            return $url;
        }

        return null;
    }

    public static function isYoutube(?string $url): bool
    {
        return self::youtubeId($url) !== null;
    }

    /** URL обложки максимального доступного размера (с fallback hqdefault). */
    public static function youtubeThumbnail(string $id, bool $hq = true): string
    {
        return 'https://i.ytimg.com/vi/' . $id . '/' . ($hq ? 'hqdefault' : 'mqdefault') . '.jpg';
    }

    public static function youtubeEmbed(string $id): string
    {
        // YouTube больше не позволяет полностью выключить рекомендации через
        // rel=0. Поэтому скрываем штатные контролы/кнопку «Другие видео», а
        // enablejsapi позволяет сайту закрыть финальный экран своей обложкой.
        return 'https://www.youtube-nocookie.com/embed/' . $id
            . '?rel=0&controls=0&disablekb=1&fs=0&iv_load_policy=3&playsinline=1&enablejsapi=1';
    }
}
