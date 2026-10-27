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

    /**
     * Атрибуты, которые читает человек: диктор (`alt`, `aria-*`), подсказка
     * при наведении и пустое поле формы. Остальные атрибуты — адреса, классы,
     * данные для скриптов — переписывать нельзя.
     */
    private const TEXT_ATTRIBUTES = ['alt', 'title', 'aria-label', 'aria-description', 'aria-roledescription', 'placeholder'];

    /**
     * `<meta>`, чьё `content` уходит в сниппет поиска и превью ссылки в
     * мессенджерах. Без них поиск показывал страницу новым алфавитом в
     * заголовке и старым в описании.
     */
    private const TEXT_META = [
        'description', 'og:title', 'og:description', 'og:site_name', 'og:image:alt',
        'twitter:title', 'twitter:description', 'twitter:image:alt',
    ];

    /**
     * Строки JSON-LD, которые человек читает в выдаче поиска. `@type`, адреса,
     * даты и идентификаторы — данные, их не трогаем.
     */
    private const JSON_LD_KEYS = ['name', 'alternateName', 'headline', 'description', 'caption', 'text', 'articleBody', 'slogan'];

    /**
     * Слова, которые узнаются в лицо, хотя иностранных букв в них нет. Список
     * общий: пока у каждой письменности был свой, кириллица писала «чроме»
     * и «шопифй», а новая латиница — «Google» без изменений, и наоборот.
     */
    public const FOREIGN_WORDS = [
        'google', 'youtube', 'tiktok', 'iphone', 'linkedin', 'zoom', 'apple',
        'chrome', 'sharepoint', 'shopify', 'english', 'washington',
        // Слова названий организаций без признаков на письме. Узбекский пишет
        // их иначе (xalqaro, milliy, menejment, institut), поэтому в узбекском
        // тексте такого слова не бывает.
        'development', 'international', 'national', 'investment', 'management',
        'institute', 'programme', 'united', 'asian', 'european', 'global',
    ];

    /** Служебные слова внутри иностранного названия: «Bank of America». */
    private const NAME_CONNECTORS = ['of', 'and', 'for', 'the', 'in', 'on', 'de', 'du', 'la', 'und', 'für'];

    /** Апострофы, которыми в текстах записывают oʻ, gʻ и tutuq belgisi. */
    public const APOSTROPHES = ['ʻ', 'ʼ', 'ʹ', '′', '‘', '’', '`', "'", '´'];

    /**
     * Иностранное слово: письменность его не трогает. Признаки двух родов.
     * Буквы, которых в узбекском алфавите нет (w, c не в «ch», диакритика),
     * и смена регистра внутри (YouTube). И сочетания, которых нет в узбекских
     * словах: -tion/-sion (узбекский пишет -tsiya/-siya), ck, ee, oo, ou, ph,
     * ght, th- в начале, -ble, -ness, -ship, -ed, -ian, -mme, -ity, -ary,
     * -ory, -ive, -ure, -ies. Сверено со словарём `lang/uz.php`, контентом
     * Агентства, миграциями и узбекскими строками тестов: узбекских совпадений
     * нет. Отвергнуты -ay (qulay), -ery (tendery) и -ment (parlament,
     * dokument). Буквы новой латиницы (Ö, Ş…) иностранными не считаются.
     */
    public static function isForeignWord(string $word): bool
    {
        $word = strtr($word, ['Ö' => 'O', 'ö' => 'o', 'Ğ' => 'G', 'ğ' => 'g', 'Ş' => 'S', 'ş' => 's', 'Ç' => 'C', 'ç' => 'c']);
        $lower = mb_strtolower($word);

        return in_array($lower, self::FOREIGN_WORDS, true)
            || preg_match('/[wW]|[cC](?![hH])|[\x{00C0}-\x{024F}]|\p{Ll}\p{Lu}\p{Ll}/u', $word) === 1
            || preg_match('/ck|ee|oo|ou|ph|ght|^th|[ts]ions?$|bles?$|ness$|ships?$|..ed$|ians?$|mme$|ity$|ary$|ory$|ives?$|ures?$|ies$/', $lower) === 1;
    }

    /**
     * Иностранные названия целиком: «Sustainable Development Solutions
     * Network (SDSN)». По отдельности слова «Development» в нём ничем себя не
     * выдаёт, и название выходило наполовину кириллицей. Поэтому ряд слов с
     * заглавной буквы (служебные of/and/the между ними допускаются) остаётся
     * латиницей, если иностранных в нём не меньше половины (служебные of/and/
     * the — голос «за»: в узбекском их нет), а при ничьей — только если первым
     * стоит иностранное: «World Bank» — название, «Toshkent Facebook» — нет.
     * Слово с узбекскими
     * буквами (q, x, oʻ, gʻ, ʼ) ряд прерывает: «Toshkent» рядом с брендом не
     * поглощается. Заодно остаётся латиницей аббревиатура, стоящая вплотную к
     * такому названию (через скобку, запятую или тире).
     *
     * @return list<array{int, int}> отрезки [начало, длина] в байтах
     */
    public static function foreignNames(string $text): array
    {
        if (preg_match('/\p{Lu}[\p{L}\p{M}]*[ \-]+(?:\p{Ll}+[ \-]+)?\p{Lu}/u', $text) !== 1) {
            return [];
        }
        $apostrophes = preg_quote(implode('', self::APOSTROPHES), '/');
        if (preg_match_all('/[\p{L}\p{M}' . $apostrophes . ']+/u', $text, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $tokens = $m[0];
        $count = count($tokens);
        $gap = static fn (int $i): string => substr($text, $tokens[$i][1] + strlen($tokens[$i][0]), $tokens[$i + 1][1] - $tokens[$i][1] - strlen($tokens[$i][0]));
        $isName = static fn (string $w): bool => preg_match('/^\p{Lu}/u', $w) === 1
            && preg_match('/[qQxX]|[' . $apostrophes . ']/u', $w) !== 1;
        $isAbbr = static fn (string $w): bool => preg_match('/^\p{Lu}{2,8}$/u', $w) === 1;

        $spans = [];
        $i = 0;
        while ($i < $count) {
            if (!$isName($tokens[$i][0])) {
                $i++;
                continue;
            }
            // Ряд: имена, между ними — только пробел, дефис, «&» и служебные слова.
            $end = $i;
            $j = $i;
            while ($j + 1 < $count && preg_match('/^[ \x{00A0}\-&]+$/u', $gap($j)) === 1) {
                $next = $tokens[$j + 1][0];
                if ($isName($next)) {
                    $j++;
                    $end = $j;
                } elseif (in_array($next, self::NAME_CONNECTORS, true) && $j + 2 < $count
                    && preg_match('/^[ \x{00A0}]+$/u', $gap($j + 1)) === 1 && $isName($tokens[$j + 2][0])) {
                    $j += 2;
                    $end = $j;
                } else {
                    break;
                }
            }
            $words = [];
            $connectors = 0;
            for ($k = $i; $k <= $end; $k++) {
                if (in_array($tokens[$k][0], self::NAME_CONNECTORS, true)) {
                    $connectors++;
                } else {
                    $words[] = $tokens[$k][0];
                }
            }
            $votes = count(array_filter($words, [self::class, 'isForeignWord'])) + $connectors;
            $total = count($words) + $connectors;
            $named = count($words) >= 2 && ($votes * 2 > $total
                || ($votes * 2 === $total && self::isForeignWord($words[0])));
            if ($named) {
                $from = $i;
                $to = $end;
                $near = '/^[\s,;:()\x{2013}\x{2014}\-\/]+$/u';
                if ($to + 1 < $count && $isAbbr($tokens[$to + 1][0]) && preg_match($near, $gap($to)) === 1) {
                    $to++;
                }
                if ($from > 0 && $isAbbr($tokens[$from - 1][0]) && preg_match($near, $gap($from - 1)) === 1) {
                    $from--;
                }
                $start = $tokens[$from][1];
                $spans[] = [$start, $tokens[$to][1] + strlen($tokens[$to][0]) - $start];
            }
            $i = $end + 1;
        }

        return $spans;
    }

    /** Приводит все варианты к буквенному апострофу U+02BB. */
    public static function normalizeApostrophes(string $text): string
    {
        return str_replace(self::APOSTROPHES, 'ʻ', $text);
    }

    /**
     * Применяет преобразование к тексту разметки: к текстовым узлам, к
     * читаемым атрибутам (`TEXT_ATTRIBUTES`, `content` у `TEXT_META`) и к
     * строкам JSON-LD (`JSON_LD_KEYS`). Теги, прочие атрибуты, содержимое
     * script/style/code и элементы с `data-no-translit` (вместе со всем
     * содержимым) остаются как есть.
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
        $jsonLd = false;   // пропускаемый тег — разметка JSON-LD
        $out = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] !== '<') {
                $out .= match (true) {
                    $skipTag === null => $convert($part),
                    $jsonLd => self::jsonLd($part, $convert),
                    default => $part,
                };
                continue;
            }

            if (!preg_match('/^<\s*(\/?)\s*([a-zA-Z0-9-]+)/', $part, $m)) {
                $out .= $part;
                continue;
            }
            $isClosing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $selfClosing = str_ends_with(rtrim($part), '/>');

            if ($skipTag === null) {
                $noTranslit = preg_match('/\sdata-no-translit(?=\s|=|\/?>)/i', $part) === 1;
                $out .= $isClosing || $noTranslit ? $part : self::attributes($part, $tag, $convert);
                if (!$isClosing && !$selfClosing && ($noTranslit || in_array($tag, self::SKIP_TAGS, true))) {
                    $skipTag = $tag;
                    $skipDepth = 1;
                    $jsonLd = $tag === 'script' && !$noTranslit
                        && preg_match('/\stype\s*=\s*["\']?application\/ld\+json/i', $part) === 1;
                }
                continue;
            }

            $out .= $part;

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
     * Читаемые атрибуты тега. Атрибуты разбираются по порядку вместе со
     * значениями, поэтому «alt=» внутри чужого значения атрибутом не считается.
     *
     * @param callable(string): string $convert
     */
    private static function attributes(string $tag, string $name, callable $convert): string
    {
        $names = self::TEXT_ATTRIBUTES;
        if ($name === 'meta') {
            $key = preg_match('/\s(?:name|property)\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $m) === 1 ? strtolower($m[1]) : '';
            if (in_array($key, self::TEXT_META, true)) {
                $names[] = 'content';
            }
        }
        if (preg_match('/\s(?:' . implode('|', array_map('preg_quote', $names)) . ')\s*=/i', $tag) !== 1) {
            return $tag;
        }

        return preg_replace_callback(
            '/(\s+)([^\s=\/>"\']+)(?:(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>"\']+))?/',
            static function (array $m) use ($names, $convert): string {
                if (!isset($m[4]) || !in_array(strtolower($m[2]), $names, true)) {
                    return $m[0];
                }
                $value = $m[4];
                $quote = $value[0] === '"' || $value[0] === "'" ? $value[0] : '';
                $inner = $quote === '' ? $value : substr($value, 1, -1);

                return $m[1] . $m[2] . $m[3] . $quote . $convert($inner) . $quote;
            },
            $tag
        ) ?? $tag;
    }

    /**
     * Читаемые строки JSON-LD. Значение раскодируется, переводится и
     * кодируется обратно с `JSON_HEX_TAG`: перевод не добавляет «<», но и
     * снимать экранирование, которое было, нельзя — это выход из `<script>`.
     *
     * @param callable(string): string $convert
     */
    private static function jsonLd(string $json, callable $convert): string
    {
        return preg_replace_callback(
            '/("(?:' . implode('|', self::JSON_LD_KEYS) . ')"\s*:\s*)("(?:[^"\\\\]|\\\\.)*")/u',
            static function (array $m) use ($convert): string {
                $value = json_decode($m[2]);
                if (!is_string($value)) {
                    return $m[0];
                }
                $converted = $convert($value);
                if ($converted === $value) {
                    return $m[0];
                }
                $encoded = json_encode($converted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);

                return $m[1] . ($encoded === false ? $m[2] : $encoded);
            },
            $json
        ) ?? $json;
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
            $out .= $index % 2 === 1 ? $chunk : self::outsideNames($chunk, $convert);
        }

        return $out;
    }

    /**
     * @param callable(string): string $convert
     */
    private static function outsideNames(string $text, callable $convert): string
    {
        $out = '';
        $at = 0;
        foreach (self::foreignNames($text) as [$start, $length]) {
            $out .= $convert(substr($text, $at, $start - $at)) . substr($text, $start, $length);
            $at = $start + $length;
        }

        return $out . $convert(substr($text, $at));
    }
}
