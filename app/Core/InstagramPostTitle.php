<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Заголовок поста Instagram без модели и подпись под заголовком.
 *
 * Сформулированный заголовок приходит из `Ai\AiInstagramTitles` при
 * обновлении ленты; этот класс отвечает за то, что нужно и без модели, — и
 * публичному выводу, которому про ИИ знать нельзя (тест 381): запасной
 * заголовок из первой фразы подписи и подпись, не повторяющая его.
 */
final class InstagramPostTitle
{
    public const MAX_LENGTH = 90;

    /**
     * Заголовок без модели: первая фраза подписи без эмодзи и хештегов,
     * обрезанная по слову.
     */
    public static function fallback(string $caption): string
    {
        $text = (string) preg_replace('/#[\p{L}\p{N}_]+/u', ' ', $caption);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if (mb_strlen($line) < 3) {
                continue;
            }
            if (preg_match('/^(.{20,}?[.!?])(\s|$)/u', $line, $m) === 1) {
                $line = $m[1];
            }

            return self::clean(self::cut($line));
        }

        return '';
    }

    /**
     * Подпись под заголовком: если заголовок — её же первая фраза (запасной
     * вариант без модели), она не повторяется дважды подряд.
     */
    public static function rest(string $caption, string $title): string
    {
        $head = rtrim($title, '…');
        $at = $head !== '' ? mb_stripos($caption, $head) : false;
        if ($at === false || $at > 12) {
            return trim($caption);
        }

        return trim(mb_substr($caption, $at + mb_strlen($head)), " \t\n\r.!?:;,—-");
    }

    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}\x{20E3}]+/u', ' ', strip_tags($text));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " \t\"«»'.:;,-—");

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space !== false && $space > 40 ? $space : self::MAX_LENGTH - 1)) . '…';
    }
}
