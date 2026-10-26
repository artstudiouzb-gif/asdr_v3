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
     * Версия правил заголовка в сохранённой копии ленты. Заголовки прежней
     * версии резались по байтам («материа?») и посреди слова; копия без
     * отметки получает свежий запасной заголовок и спрашивается у модели
     * заново при следующем обновлении.
     */
    public const VERSION = 2;

    /**
     * Заголовок без модели: первая фраза подписи без эмодзи и хештегов,
     * обрезанная по слову.
     */
    public static function fallback(string $caption): string
    {
        $text = (string) preg_replace('/#[\p{L}\p{N}_]+/u', ' ', $caption);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::trimEdges((string) preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}\x{20E3}]+/u', ' ', strip_tags($line)), '.:;,—–\\-');
            if (mb_strlen($line) < 3) {
                continue;
            }
            if (preg_match('/^(.{20,}?[.!?])(\s|$)/u', $line, $m) === 1) {
                $line = $m[1];
            }

            return self::clean($line);
        }

        return '';
    }

    /**
     * Подпись под заголовком: если заголовок — её же первая фраза (запасной
     * вариант без модели), она не повторяется дважды подряд.
     */
    public static function rest(string $caption, string $title): string
    {
        $head = (string) preg_replace('/…$/u', '', $title);
        $at = $head !== '' ? mb_stripos($caption, $head) : false;
        if ($at === false || $at > 12) {
            return trim($caption);
        }

        return self::trimEdges(mb_substr($caption, $at + mb_strlen($head)), '.!?:;,—–\\-');
    }

    /**
     * Заголовок без эмодзи, лишних пробелов и висячих знаков по краям,
     * обрезанный по слову.
     *
     * Края чистятся регуляркой, а не trim(): trim() режет по байтам, и
     * многобайтные «, », — в его списке отрезали хвостовой байт у соседней
     * буквы — «материал» становился «материа?», а “Tahlil” — «?Tahlil».
     * Кавычка по краю снимается, только если пары ей в заголовке нет:
     * «Özbekiston — 2030» strategiyasi без открывающей читается поломкой.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}\x{20E3}]+/u', ' ', strip_tags($text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = self::unpairedQuotes(self::trimEdges($text, '.:;,—–\\-'));

        return self::cut(self::trimEdges($text, '.:;,—–\\-'));
    }

    /** Снимает пробелы и знаки из класса $chars с обоих краёв (по символам). */
    private static function trimEdges(string $text, string $chars): string
    {
        return (string) preg_replace('/^[\s' . $chars . ']+|[\s' . $chars . ']+$/u', '', $text);
    }

    /** Кавычка у края без пары внутри — мусор обрезки, парная — часть текста. */
    private static function unpairedQuotes(string $text): string
    {
        $pairs = ['«' => '»', '“' => '”', '„' => '“', '"' => '"', "'" => "'"];
        $first = mb_substr($text, 0, 1);
        if (isset($pairs[$first]) && !str_contains(mb_substr($text, 1), $pairs[$first])) {
            $text = mb_substr($text, 1);
        }
        $last = mb_substr($text, -1);
        $openers = array_keys($pairs, $last, true);
        if ($openers !== []) {
            $before = mb_substr($text, 0, -1);
            $paired = false;
            foreach ($openers as $open) {
                $paired = $paired || str_contains($before, (string) $open);
            }
            if (!$paired) {
                $text = $before;
            }
        }

        return trim($text);
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');
        $cut = mb_substr($cut, 0, $space !== false && $space > 40 ? $space : self::MAX_LENGTH - 1);

        return self::trimEdges($cut, '.:;,—–\\-') . '…';
    }
}
