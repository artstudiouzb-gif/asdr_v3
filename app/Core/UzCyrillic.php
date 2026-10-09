<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Транслитерация узбекской латиницы в кириллицу.
 *
 * Сайт наполняется на латинице, а часть читателей привыкла к кириллице.
 * Перевод делается на выходе, поэтому в базе остаётся один вариант текста:
 * ничего не дублируется и не расходится.
 *
 * Переводится только текст страницы: теги, атрибуты, содержимое script/style,
 * HTML-сущности, адреса и почта остаются как есть. Элемент с атрибутом
 * data-no-translit не трогается вместе со всем содержимым (в панели настроек
 * подписи «Lotin»/«Кирилл» должны остаться собой).
 */
final class UzCyrillic
{
    /** Теги, внутри которых текст не трогаем. */
    private const SKIP_TAGS = ['script', 'style', 'code', 'pre', 'textarea', 'kbd', 'samp'];

    /**
     * Знаки, которыми латиница ставит и кавычки: в конце слова такой знак —
     * кавычка, а не tutuq belgisi.
     */
    private const QUOTES = ['‘', '’', '`', "'"];

    /** Слова, которые узнаются в лицо, хотя иностранных букв в них нет. */
    private const KEEP = ['google', 'youtube', 'tiktok', 'iphone', 'linkedin', 'zoom', 'apple'];

    /**
     * Слово целиком. Месяцы сохраняют «ь» только без окончания: «5 сентябрь»,
     * но «сентябрда» и «7 февралдаги» — так пишет кириллица lex.uz.
     */
    private const WHOLE_WORDS = [
        'yanvar' => 'январь', 'fevral' => 'февраль', 'aprel' => 'апрель',
        'iyun' => 'июнь', 'iyul' => 'июль', 'sentabr' => 'сентябрь',
        'sentyabr' => 'сентябрь', 'oktabr' => 'октябрь', 'oktyabr' => 'октябрь',
        'noyabr' => 'ноябрь', 'dekabr' => 'декабрь', 'nyu' => 'нью',
    ];

    /**
     * Начало слова. Официальная латиница пишет русское «ц» буквой «s» после
     * согласной и в начале слова (sement, Fransiya), «ё» после гласной — «yo»
     * (rayon), а «ь» не пишет вовсе. Правилом это не восстановить: «sirka» —
     * узбекский уксус, «sirk» — цирк. Поэтому заимствования перечислены.
     */
    private const WORD_START = [
        'sentabr' => 'сентябр', 'sentyabr' => 'сентябр', 'oktabr' => 'октябр', 'oktyabr' => 'октябр',
        'fransuz' => 'француз', 'sement' => 'цемент', 'sentr' => 'центр', 'sentner' => 'центнер',
        'sirk' => 'цирк', 'sitrus' => 'цитрус', 'sitata' => 'цитата', 'sikl' => 'цикл',
        'silindr' => 'цилиндр', 'sivil' => 'цивил', 'sensura' => 'цензура', 'sex' => 'цех',
        'konsert' => 'концерт', 'konsern' => 'концерн', 'konsentr' => 'концентр',
        'konsepsiya' => 'концепция', 'konsept' => 'концепт', 'prinsip' => 'принцип',
        'inersiya' => 'инерция', 'kalsiy' => 'кальций', 'lisenz' => 'лиценз', 'lisey' => 'лицей',
        'aksiz' => 'акциз', 'opsiya' => 'опция', 'rayon' => 'район', 'mayor' => 'майор',
        'york' => 'йорк', 'kompyuter' => 'компьютер', 'intervyu' => 'интервью',
        'batalyon' => 'батальон', 'pavilyon' => 'павильон', 'bilyard' => 'бильярд',
    ];

    /**
     * Внутри слова. «-ksiya» и «-nsiya» — почти всегда русское «-кция/-нция»
     * (aksiya, funksiya, konferensiya, stansiya, Fransiya); «-ssiya», «-rsiya»
     * остаются «-сия» (Rossiya, versiya). Исключения «pensiya» и «ekspansiya»
     * отсекает условие перед «nsiya» в stemPatterns().
     */
    private const INSIDE = [
        'korrupsiya' => 'коррупция', 'ksiya' => 'кция', 'ksion' => 'кцион', 'nsiya' => 'нция',
    ];

    /** Условия вокруг основы: [перед, после]. */
    private const GUARDS = [
        'sirk' => ['', '(?!a)'],
        'nsiya' => ['(?<!pe|pa)', ''],
    ];

    /** @var array<string, string>|null */
    private static ?array $map = null;

    /** @var array{0: string, 1: string}|null */
    private static ?array $stemPatterns = null;

    /**
     * Готовые слова в пределах запроса: на странице они повторяются.
     *
     * @var array<string, string>
     */
    private static array $memo = [];

    public static function text(string $latin): string
    {
        if ($latin === '' || !preg_match('/[a-zA-Z]/', $latin)) {
            return $latin;
        }

        // Сущности, URI, почта и домены — не обычный текст: их
        // транслитерация ломает адрес или саму HTML-сущность.
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
            $latin,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($chunks === false) {
            return $latin;
        }

        $out = '';
        foreach ($chunks as $index => $chunk) {
            // Захваченные разделители стоят на нечётных местах.
            $out .= $index % 2 === 1 ? $chunk : self::convert($chunk);
        }

        return $out;
    }

    /** Переводит только текстовые узлы готовой разметки. */
    public static function html(string $html): string
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
                $out .= $skipTag === null ? self::text($part) : $part;
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

    private static function convert(string $text): string
    {
        // Слово — буквы вместе с апострофами: «oʻ», «gʻ» и tutuq belgisi
        // живут внутри слова, а кавычки по краям отрезает word().
        return preg_replace_callback(
            '/[\p{L}\p{M}' . self::apostropheClass() . ']+/u',
            static fn (array $m): string => self::word($m[0]),
            $text
        ) ?? $text;
    }

    private static function word(string $token): string
    {
        if (!preg_match('/[a-zA-Z]/', $token)) {
            return $token;
        }
        if (isset(self::$memo[$token])) {
            return self::$memo[$token];
        }

        $apostrophes = self::apostropheClass();
        $quotes = preg_quote(implode('', self::QUOTES), '/');

        // Апостроф в начале слова — всегда кавычка. В конце — кавычка, если
        // это знак кавычки и перед ним не «g» (tog‘ — «тоғ») и не одинокое «o»
        // (сама буква «oʻ»): иначе ‘Mirzo’ превращалось бы в «Мирзў».
        if (!preg_match('/^([' . $apostrophes . ']*)(.*?)([' . $quotes . ']*)$/su', $token, $parts)) {
            return $token;
        }
        [$lead, $core, $trail] = [$parts[1], $parts[2], $parts[3]];
        if ($trail !== '' && preg_match('/(?:^[oO]|[gG])$/', $core)) {
            $core .= $trail;
            $trail = '';
        }

        $result = $lead . self::core($core) . $trail;
        if (count(self::$memo) < 5000) {
            self::$memo[$token] = $result;
        }

        return $result;
    }

    private static function core(string $word): string
    {
        if ($word === '' || !preg_match('/[a-zA-Z]/', $word)) {
            return $word;
        }

        $lower = mb_strtolower($word);

        // Иностранное слово остаётся как есть: c (не в «ch»), w и буквы с
        // диакритикой в узбекском алфавите не встречаются (Facebook, Microsoft),
        // а «YouTube», «iPhone» и «TikTok» выдаёт смена регистра внутри слова.
        // Аббревиатура с окончанием («AQShda», «ToshDU») этим не считается.
        if (in_array($lower, self::KEEP, true)
            || preg_match('/[wW]|[cC](?![hH])|[\x{00C0}-\x{024F}]|\p{Ll}\p{Lu}\p{Ll}/u', $word)
        ) {
            return $word;
        }

        if (isset(self::WHOLE_WORDS[$lower])) {
            return self::withCase($word, self::WHOLE_WORDS[$lower]);
        }

        [$startPattern, $insidePattern] = self::stemPatterns();
        $word = preg_replace_callback($startPattern, static fn (array $m): string
            => self::withCase($m[0], self::WORD_START[mb_strtolower($m[0])] ?? $m[0]), $word) ?? $word;
        $word = preg_replace_callback($insidePattern, static fn (array $m): string
            => self::withCase($m[0], self::INSIDE[mb_strtolower($m[0])] ?? $m[0]), $word) ?? $word;

        $apostrophes = self::apostropheClass();

        // s'h — разделитель, а не tutuq: Is'hoq → Исҳоқ, а не «Ишоқ» и не «Исъҳоқ».
        $word = preg_replace_callback('/([sS])[' . $apostrophes . ']([hH])/u', static fn (array $m): string
            => ($m[1] === 'S' ? 'С' : 'с') . ($m[2] === 'H' ? 'Ҳ' : 'ҳ'), $word) ?? $word;

        // «ts» — это «ц» только в заимствованиях: в начале слова (tsement),
        // перед «e» (protsent, kontsert) и в «-tsiya», «-tsion», «-tsist»,
        // «meditsina». На стыке основы на «t» и суффикса это «тс»: aytsa,
        // ketsin, ahamiyatsiz, maʼrifatsevar.
        if (stripos($word, 'ts') !== false) {
            $word = preg_replace_callback(
                '/^ts|ts(?=e(?!var)|i[aeiouy]|i[bdfklprstv]|in[aeiou])/i',
                static fn (array $m): string => self::withCase($m[0], 'ц'),
                $word
            ) ?? $word;
        }

        if (stripos($word, 'y') !== false) {
            // Разделительный «ъ» после русских приставок: obyekt → объект,
            // subyekt, podyezd, syezd, inyeksiya, adyutant, konyunktura.
            $word = preg_replace_callback(
                '/^(ob|sub|pod)(?=y[eo])|^(s|in)(?=ye)|^(ad|kon)(?=yu)/i',
                static fn (array $m): string => $m[0] . (ctype_upper(substr($word, strlen($m[0]), 1)) ? 'Ъ' : 'ъ'),
                $word
            ) ?? $word;
            // После согласной «ye» в узбекском слове не встречается: это
            // русское «ье» — premyer → премьер, kuryer → курьер.
            $word = preg_replace_callback(
                '/([bdfgjklmnpqrstvxz])(y)(?=e)/i',
                static fn (array $m): string => $m[1] . ($m[2] === 'Y' ? 'Ь' : 'ь') . $m[2],
                $word
            ) ?? $word;
        }

        // «e» в начале слова и после «a», «o», «u» — «э»: Ekologiya → Экология,
        // aeroport → аэропорт, poeziya → поэзия. Внутри слова после согласной
        // — «е» (kelajak → келажак). После «i» тоже «е»: «клиент» и «диета»
        // пишут и без «y», а «иэ» в языке почти не бывает.
        $word = preg_replace_callback(
            '/^e|(?<=[aouаоуё])e/iu',
            static fn (array $m): string => $m[0] === 'E' ? 'Э' : 'э',
            $word
        ) ?? $word;

        // Tutuq belgisi в слове заглавными — заглавный «Ъ» (MAʼLUMOT).
        $word = preg_replace(
            '/(?<=\p{Lu})(?<![oOgG])[' . $apostrophes . '](?=\p{Lu})/u',
            'Ъ',
            $word
        ) ?? $word;

        // strtr с массивом подставляет сначала самые длинные ключи, поэтому
        // «sh» срабатывает раньше «s», а «oʻ» — раньше «o».
        return strtr($word, self::map());
    }

    /** Регистр образца на кириллицу: всё заглавными, с заглавной или строчными. */
    private static function withCase(string $source, string $cyrillic): string
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $source) ?? $source;
        if (mb_strlen($letters) > 1 && mb_strtoupper($letters) === $letters) {
            return mb_strtoupper($cyrillic);
        }
        if (preg_match('/^\p{Lu}/u', $source)) {
            return mb_strtoupper(mb_substr($cyrillic, 0, 1)) . mb_substr($cyrillic, 1);
        }

        return $cyrillic;
    }

    private static function apostropheClass(): string
    {
        return preg_quote(implode('', UzbekText::APOSTROPHES), '/');
    }

    /** @return array{0: string, 1: string} */
    private static function stemPatterns(): array
    {
        if (self::$stemPatterns !== null) {
            return self::$stemPatterns;
        }

        return self::$stemPatterns = [
            '/^(?:' . self::alternation(array_keys(self::WORD_START)) . ')/i',
            '/(?:' . self::alternation(array_keys(self::INSIDE)) . ')/i',
        ];
    }

    /**
     * Длинные основы раньше коротких: «konsepsiya» раньше «konsept».
     *
     * @param list<string> $stems
     */
    private static function alternation(array $stems): string
    {
        usort($stems, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', array_map(static function (string $stem): string {
            [$before, $after] = self::GUARDS[$stem] ?? ['', ''];

            return $before . preg_quote($stem, '/') . $after;
        }, $stems));
    }

    /** @return array<string, string> */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];

        // Буквы с апострофом и tutuq belgisi — во всех распространённых
        // начертаниях апострофа. yoʻ — отдельный важный случай: это «йў»
        // (yoʻl → йўл), а не «ёъ».
        foreach (UzbekText::APOSTROPHES as $apostrophe) {
            $map['yo' . $apostrophe] = 'йў';
            $map['Yo' . $apostrophe] = 'Йў';
            $map['YO' . $apostrophe] = 'ЙЎ';
            $map['o' . $apostrophe] = 'ў';
            $map['O' . $apostrophe] = 'Ў';
            $map['g' . $apostrophe] = 'ғ';
            $map['G' . $apostrophe] = 'Ғ';
            $map[$apostrophe] = 'ъ';
        }

        // Диграфы: строчный, с заглавной и полностью заглавный.
        $digraphs = [
            'sh' => 'ш',
            'ch' => 'ч',
            'yo' => 'ё',
            'yu' => 'ю',
            'ya' => 'я',
            'ye' => 'е',
        ];
        foreach ($digraphs as $latin => $cyrillic) {
            $map[$latin] = $cyrillic;
            $map[ucfirst($latin)] = mb_strtoupper($cyrillic);
            $map[mb_strtoupper($latin)] = mb_strtoupper($cyrillic);
        }

        $letters = [
            'a' => 'а', 'b' => 'б', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г',
            'h' => 'ҳ', 'i' => 'и', 'j' => 'ж', 'k' => 'к', 'l' => 'л', 'm' => 'м',
            'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'қ', 'r' => 'р', 's' => 'с',
            't' => 'т', 'u' => 'у', 'v' => 'в', 'x' => 'х', 'y' => 'й', 'z' => 'з',
        ];
        foreach ($letters as $latin => $cyrillic) {
            $map[$latin] = $cyrillic;
            $map[mb_strtoupper($latin)] = mb_strtoupper($cyrillic);
        }

        self::$map = $map;

        return $map;
    }
}
