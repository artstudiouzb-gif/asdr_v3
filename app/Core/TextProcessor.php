<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Микро-типограф и санитайзер текста. Очищает «мусор» из Word / Google Docs,
 * расставляет неразрывные пробелы для коротких предлогов/союзов и заменяет
 * прямые кавычки и дефисы на правильные типографские знаки согласно локали.
 */
final class TextProcessor
{
    private const NBSP = "\u{00A0}";
    private const MDASH = "\u{2014}";

    // Короткие слова, после которых нежелателен перенос строки.
    private const RU_SHORT = [
        'в', 'во', 'на', 'с', 'со', 'к', 'ко', 'о', 'об', 'по', 'из', 'изо', 'от', 'ото',
        'до', 'за', 'у', 'и', 'а', 'но', 'да', 'же', 'ли', 'бы', 'не', 'ни', 'что', 'как',
        'для', 'при', 'про', 'без', 'над', 'под', 'the', 'a', 'an', 'to', 'of', 'in', 'on',
    ];

    /**
     * Обрабатывает HTML-фрагмент: чистит Word-мусор и применяет типографику
     * только к текстовым узлам (теги и атрибуты не затрагиваются).
     */
    public static function process(string $html, string $locale = 'ru'): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $html = self::cleanWordJunk($html);

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="tp-root">' . $html . '</div>';
        $loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Не удалось разобрать как HTML — типографим как обычный текст.
            return self::typograph($html, $locale);
        }

        $root = $dom->getElementById('tp-root');
        if ($root === null) {
            return self::typograph($html, $locale);
        }

        self::walkTextNodes($root, $locale);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Типографика для простого текста (без HTML): заголовки, короткие поля.
     */
    public static function typographPlain(string $text, string $locale = 'ru'): string
    {
        return self::typograph($text, $locale);
    }

    public static function truncate(string $text, int $length = 150, string $append = '...'): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . $append;
    }

    public static function cleanWordJunk(string $html): string
    {
        // Условные комментарии Word (<!--[if ...]> ... <![endif]-->).
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', $html) ?? $html;
        // Обычные комментарии.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        // Теги <o:p>, <w:...>, <m:...> и прочий XML-namespace мусор Office.
        $html = preg_replace('#</?(?:o|w|m|v|st1):[^>]*>#i', '', $html) ?? $html;
        // mso-* объявления внутри style="".
        $html = preg_replace_callback('/\sstyle="([^"]*)"/i', static function ($m) {
            $rules = array_filter(array_map('trim', explode(';', $m[1])), static function ($rule) {
                return $rule !== '' && stripos($rule, 'mso-') !== 0;
            });
            return $rules === [] ? '' : ' style="' . implode('; ', $rules) . '"';
        }, $html) ?? $html;
        // Классы вида class="MsoNormal".
        $html = preg_replace('/\sclass="Mso[^"]*"/i', '', $html) ?? $html;
        // lang-атрибуты Word.
        $html = preg_replace('/\slang="[^"]*"/i', '', $html) ?? $html;

        return $html;
    }

    private static function walkTextNodes(\DOMNode $node, string $locale): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                $child->nodeValue = self::typograph($child->nodeValue ?? '', $locale);
            } elseif ($child instanceof \DOMElement) {
                // Не трогаем содержимое <pre>, <code>, <script>, <style>.
                $tag = strtolower($child->nodeName);
                if (in_array($tag, ['pre', 'code', 'script', 'style'], true)) {
                    continue;
                }
                self::walkTextNodes($child, $locale);
            }
        }
    }

    private static function typograph(string $text, string $locale): string
    {
        if ($text === '') {
            return $text;
        }

        // Тире: " - " -> " — " (с неразрывным пробелом перед тире), "--" -> "—".
        $text = preg_replace('/ +- +/u', self::NBSP . self::MDASH . ' ', $text) ?? $text;
        $text = str_replace('--', self::MDASH, $text);

        // Кавычки.
        $text = self::typographQuotes($text, $locale);

        // Неразрывные пробелы после коротких слов.
        $pattern = '/(?<![\p{L}])(' . implode('|', array_map('preg_quote', self::RU_SHORT)) . ') +/iu';
        $text = preg_replace_callback($pattern, static function ($m) {
            return $m[1] . self::NBSP;
        }, $text) ?? $text;

        // Неразрывный пробел между числом и следующим словом (10 кг, 5 м).
        $text = preg_replace('/(\d) +(?=\p{L})/u', '$1' . self::NBSP, $text) ?? $text;

        return $text;
    }

    private static function typographQuotes(string $text, string $locale): string
    {
        if (!str_contains($text, '"')) {
            return $text;
        }

        [$open, $close] = ($locale === 'en')
            ? ["\u{201C}", "\u{201D}"] // “ ”
            : ["\u{00AB}", "\u{00BB}"]; // « »

        // Чередуем открывающую/закрывающую кавычки.
        $result = '';
        $isOpen = true;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if ($char === '"') {
                $result .= $isOpen ? $open : $close;
                $isOpen = !$isOpen;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
