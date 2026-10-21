<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Общее для письменностей узбекского текста: варианты апострофа и разбор
 * готовой страницы на то, что можно переписывать, и то, что нельзя.
 *
 * Разбор один на кириллицу (`UzCyrillic`) и новую латиницу (`UzLatin`):
 * второй копии правил «что не трогать» не заводим — она разошлась бы с
 * первой, и одна письменность ломала бы адреса, которые бережёт другая.
 */
final class UzbekText
{
    /** Теги, внутри которых текст не трогаем. */
    private const SKIP_TAGS = ['script', 'style', 'code', 'pre', 'textarea', 'kbd', 'samp'];

    /** Апострофы, которыми в текстах записывают oʻ, gʻ и tutuq belgisi. */
    public const APOSTROPHES = ['ʻ', 'ʼ', 'ʹ', '′', '‘', '’', '`', "'", '´'];

    /** Приводит все варианты к буквенному апострофу U+02BB. */
    public static function normalizeApostrophes(string $text): string
    {
        return str_replace(self::APOSTROPHES, 'ʻ', $text);
    }

    /**
     * Применяет преобразование к текстовым узлам разметки: теги, атрибуты,
     * содержимое script/style/code и элементы с `data-no-translit` (вместе
     * со всем содержимым) остаются как есть.
     *
     * @param callable(string): string $convert
     */
    public static function textNodes(string $html, callable $convert): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $skipTag = null;   // имя тега, внутри которого пропускаем текст
        $skipDepth = 0;
        $out = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] !== '<') {
                $out .= $skipTag === null ? $convert($part) : $part;
                continue;
            }

            $out .= $part;
            if (!preg_match('/^<\s*(\/?)\s*([a-zA-Z0-9-]+)/', $part, $m)) {
                continue;
            }
            $isClosing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $selfClosing = str_ends_with(rtrim($part), '/>');

            if ($skipTag === null) {
                $skipHere = in_array($tag, self::SKIP_TAGS, true)
                    || preg_match('/\sdata-no-translit(?=\s|=|\/?>)/i', $part) === 1;
                if (!$isClosing && !$selfClosing && $skipHere) {
                    $skipTag = $tag;
                    $skipDepth = 1;
                }
                continue;
            }

            if ($tag !== $skipTag || $selfClosing) {
                continue;
            }
            if ($isClosing) {
                $skipDepth--;
                if ($skipDepth <= 0) {
                    $skipTag = null;
                }
            } else {
                $skipDepth++;
            }
        }

        return $out;
    }

    /**
     * Применяет преобразование к обычному тексту между сущностями, адресами,
     * почтой и доменами: их переписывание ломает адрес или саму HTML-сущность.
     *
     * @param callable(string): string $convert
     */
    public static function plainChunks(string $text, callable $convert): string
    {
        $chunks = preg_split(
            '~(
                &[a-zA-Z][a-zA-Z0-9]+;
                |&\#(?:\d+|[xX][0-9a-fA-F]+);
                |(?:https?|ftp)://[^\s<]+
                |(?:mailto:|tel:)[^\s<]+
                |[\w.+\-]+@[\w.\-]+\.[\p{L}]{2,63}
                |(?<![\w@])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+
                    [\p{L}]{2,63}(?:/[^\s<]*)?
            )~iux',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($chunks === false) {
            return $text;
        }

        $out = '';
        foreach ($chunks as $index => $chunk) {
            // Захваченные разделители стоят на нечётных местах.
            $out .= $index % 2 === 1 ? $chunk : $convert($chunk);
        }

        return $out;
    }
}
