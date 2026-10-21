<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Новая узбекская латиница: Oʻ → Ö, Gʻ → Ğ, Sh → Ş, Ch → Ç.
 *
 * Буквы меняются на выходе, как у кириллицы (`UzCyrillic`), а в базе текст
 * остаётся прежним. Причины те же: переписать базу — это необратимая правка
 * тысяч записей разом, где ошибка правила (иностранное слово, кавычка вместо
 * апострофа) расползлась бы по контенту без следа; а вывод можно выключить
 * одной галочкой, если реформа сдвинется или правило окажется неверным.
 * Редактор при этом может набирать и новые буквы — `toOld()` сводит их к
 * прежнему написанию там, где правила строятся от него (кириллица, поиск).
 *
 * Не трогаются теги, атрибуты, script/style/code, адреса и почта, элементы с
 * `data-no-translit` (разбор общий — `UzbekText`), а также иностранные слова:
 * в них есть «c» не в «ch», «w» или смена регистра внутри (Facebook, YouTube).
 */
final class UzLatin
{
    /** «Языки» → «Новая латиница»; включена по умолчанию. */
    public const SETTING = 'uz_latin_new';

    /** Слова, которые узнаются в лицо, хотя иностранных букв в них нет. */
    private const KEEP = ['chrome', 'sharepoint', 'shopify', 'english', 'washington'];

    /** @var array<string, string> */
    private static array $memo = [];

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') !== '0';
    }

    /** Узбекская латинская страница показывается новым алфавитом. */
    public static function wanted(): bool
    {
        return Locale::current() === 'uz' && self::enabled() && !View::wantsCyrillic();
    }

    /** Переводит только текстовые узлы готовой разметки. */
    public static function html(string $html): string
    {
        return UzbekText::textNodes($html, static fn (string $text): string => self::text($text));
    }

    public static function text(string $text): string
    {
        if ($text === '' || preg_match('/[sScCoOgG]/', $text) !== 1) {
            return $text;
        }

        return UzbekText::plainChunks($text, static fn (string $chunk): string => preg_replace_callback(
            '/[\p{L}\p{M}' . self::apostrophes() . ']+/u',
            static fn (array $m): string => self::word($m[0]),
            $chunk
        ) ?? $chunk);
    }

    /**
     * Новые буквы — прежним написанием. Нужно там, где правила построены от
     * старого алфавита: кириллица и поиск (запрос «Şahar» обязан найти
     * «shahar» в базе).
     */
    public static function toOld(string $text): string
    {
        if (preg_match('/[ÖöĞğŞşÇç]/u', $text) !== 1) {
            return $text;
        }

        // Заглавная перед заглавной — слово набрано капсом: ŞAHAR → SHAHAR.
        $text = preg_replace_callback('/([ŞÇ])(?=\p{Lu})/u', static fn (array $m): string
            => $m[1] === 'Ş' ? 'SH' : 'CH', $text) ?? $text;

        return strtr($text, [
            'Ö' => 'Oʻ', 'ö' => 'oʻ', 'Ğ' => 'Gʻ', 'ğ' => 'gʻ',
            'Ş' => 'Sh', 'ş' => 'sh', 'Ç' => 'Ch', 'ç' => 'ch',
        ]);
    }

    private static function word(string $token): string
    {
        if (preg_match('/[sScCoOgG]/', $token) !== 1) {
            return $token;
        }
        if (isset(self::$memo[$token])) {
            return self::$memo[$token];
        }

        $apostrophes = self::apostrophes();
        // Апостроф в начале слова — всегда кавычка. В конце — кавычка, если
        // перед ним не «g» (togʻ → toğ) и слово не одинокое «o» (сама буква):
        // иначе ‘Mirzo’ превращалось бы в «Mirzö». То же правило, что у
        // кириллицы.
        if (preg_match('/^([' . $apostrophes . ']*)(.*?)([' . $apostrophes . ']*)$/su', $token, $parts) !== 1) {
            return $token;
        }
        [$lead, $core, $trail] = [$parts[1], $parts[2], $parts[3]];
        if ($trail !== '' && preg_match('/(?:^[oO]|[gG])$/', $core) === 1) {
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
        if ($word === ''
            || in_array(mb_strtolower($word), self::KEEP, true)
            || preg_match('/[wW]|[cC](?![hH])|\p{Ll}\p{Lu}\p{Ll}/u', $word) === 1
        ) {
            return $word;
        }

        $apostrophes = self::apostrophes();
        // oʻ и gʻ — во всех начертаниях апострофа.
        $word = preg_replace_callback('/([oOgG])[' . $apostrophes . ']/u', static fn (array $m): string
            => ['o' => 'ö', 'O' => 'Ö', 'g' => 'ğ', 'G' => 'Ğ'][$m[1]], $word) ?? $word;

        // sʼh — разделитель, а не «sh»: Isʼhoq остаётся как есть. Прочие «sh» и
        // «ch» — одна буква; регистр берётся у первой: Sh, SH → Ş; sh → ş.
        return preg_replace_callback('/([sScC])([hH])/', static function (array $m): string {
            $upper = ctype_upper($m[1]);

            return strtolower($m[1]) === 's' ? ($upper ? 'Ş' : 'ş') : ($upper ? 'Ç' : 'ç');
        }, $word) ?? $word;
    }

    private static function apostrophes(): string
    {
        return preg_quote(implode('', UzbekText::APOSTROPHES), '/');
    }
}
