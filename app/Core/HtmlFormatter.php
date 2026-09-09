<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Приводит готовую страницу к виду, который не стыдно открыть в «Исходном коде».
 *
 * Разметку страницы собирают три источника с разными привычками: шаблоны с
 * ручными отступами, PHP-конкатенация (шапка, меню, блоки — целые узлы в одну
 * строку) и кэш блоков. В сумме исходник получался рваным: рядом с аккуратным
 * <head> лежала строка меню длиной в несколько экранов.
 *
 * Форматтер не переписывает разметку — он двигает только пробелы, и только
 * там, где они гарантированно ничего не значат:
 *
 *  - новый перевод строки вставляется, лишь когда с одной из сторон стоит
 *    тег блочного элемента: пробел рядом с блочным боксом браузер убирает
 *    (в начале и конце блока, между блоками, а во flex/grid пробельный узел
 *    вообще не рождает бокса);
 *  - между строчными элементами (a, span, img, button) новый пробел не
 *    появляется никогда — там он виден на экране: `</a><a>` осталось бы
 *    склеенным, `</a> <a>` — с одним пробелом;
 *  - уже существующий пробел нормализуется свободно: любой его набор в
 *    обычном тексте всё равно схлопывается в один;
 *  - содержимое script, style, pre, textarea и svg не трогается вовсе — там
 *    пробел значим (или бесполезен, как в спрайте иконок).
 *
 * Поэтому вывод отличается от входа только пробелами вне значимых мест;
 * это же проверяет тест 306, сравнивая обе строки со схлопнутыми пробелами.
 */
final class HtmlFormatter
{
    private const INDENT = '  ';

    /**
     * Элементы, вокруг которых пробел незначим (блочные боксы и содержимое
     * <head>). Строчных здесь нет намеренно: a, span, img, svg, button, label,
     * input, select, textarea, br, code, small, time — у них пробел видно.
     *
     * @var array<string, true>
     */
    private const BLOCK = [
        'html' => true, 'head' => true, 'body' => true,
        'meta' => true, 'link' => true, 'title' => true, 'base' => true,
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
        'div' => true, 'main' => true, 'header' => true, 'footer' => true,
        'section' => true, 'article' => true, 'aside' => true, 'nav' => true,
        'form' => true, 'fieldset' => true, 'legend' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'p' => true, 'blockquote' => true, 'figure' => true, 'figcaption' => true,
        'ul' => true, 'ol' => true, 'li' => true, 'dl' => true, 'dt' => true, 'dd' => true,
        'table' => true, 'caption' => true, 'colgroup' => true, 'col' => true,
        'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true, 'th' => true, 'td' => true,
        'hr' => true, 'pre' => true, 'address' => true, 'details' => true,
        'summary' => true, 'dialog' => true,
    ];

    /**
     * Содержимое отдаётся как есть: внутри пробел либо значим (pre, textarea),
     * либо принадлежит другому языку (CSS, JS, SVG).
     *
     * @var array<string, true>
     */
    private const RAW = [
        'script' => true, 'style' => true, 'pre' => true, 'textarea' => true, 'svg' => true,
    ];

    /** @var array<string, true> */
    private const VOID = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true,
        'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true,
        'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];

    public static function format(string $html): string
    {
        if ($html === '' || !str_contains($html, '<')) {
            return $html;
        }

        return self::emit(self::tokenize($html));
    }

    /**
     * Аварийный выключатель в «Производительности»: преобразование трогает
     * каждый публичный ответ, и владелец должен уметь его снять без правки
     * кода. Без соединения с БД настройка читается как включённая — там же,
     * где страница ошибки.
     */
    public static function enabled(): bool
    {
        return Setting::get('perf_pretty_html', '1') === '1';
    }

    /**
     * @return list<array{t:string, html:string, name:string, kind:string, block:bool}>
     */
    private static function tokenize(string $html): array
    {
        $tokens = [];
        $len = strlen($html);
        $i = 0;

        while ($i < $len) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $tokens[] = self::token('text', substr($html, $i));
                break;
            }
            if ($lt > $i) {
                $tokens[] = self::token('text', substr($html, $i, $lt - $i));
                $i = $lt;
            }

            if (substr_compare($html, '<!--', $i, 4) === 0) {
                $end = strpos($html, '-->', $i);
                $end = $end === false ? $len : $end + 3;
                $tokens[] = self::token('comment', substr($html, $i, $end - $i), '', '', true);
                $i = $end;
                continue;
            }
            if (substr_compare($html, '<!', $i, 2) === 0) {
                $end = strpos($html, '>', $i);
                $end = $end === false ? $len : $end + 1;
                $tokens[] = self::token('doctype', substr($html, $i, $end - $i), '', '', true);
                $i = $end;
                continue;
            }
            if (preg_match('#\G</?([a-zA-Z][a-zA-Z0-9:._-]*)#', $html, $m, 0, $i) !== 1) {
                // Одинокий «<» в тексте: это не тег.
                $tokens[] = self::token('text', '<');
                $i++;
                continue;
            }

            $name = strtolower($m[1]);
            $isClose = ($html[$i + 1] ?? '') === '/';
            $tagEnd = self::tagEnd($html, $i);
            $tag = substr($html, $i, $tagEnd - $i);
            $i = $tagEnd;
            $selfClosing = str_ends_with(rtrim(substr($tag, 0, -1)), '/');

            if (!$isClose && !$selfClosing && isset(self::RAW[$name])) {
                $rawEnd = self::rawEnd($html, $i, $name);
                $tokens[] = self::token(
                    'raw',
                    $tag . substr($html, $i, $rawEnd - $i),
                    $name,
                    '',
                    isset(self::BLOCK[$name])
                );
                $i = $rawEnd;
                continue;
            }

            $kind = $isClose ? 'close' : (($selfClosing || isset(self::VOID[$name])) ? 'void' : 'open');
            $tokens[] = self::token('tag', $tag, $name, $kind, isset(self::BLOCK[$name]));
        }

        return $tokens;
    }

    /**
     * @return array{t:string, html:string, name:string, kind:string, block:bool}
     */
    private static function token(
        string $type,
        string $html,
        string $name = '',
        string $kind = '',
        bool $block = false
    ): array {
        return ['t' => $type, 'html' => $html, 'name' => $name, 'kind' => $kind, 'block' => $block];
    }

    /**
     * Позиция сразу за «>» тега. Кавычки учитываются: значение атрибута может
     * содержать «>» (в разметке проекта он экранирован, но JSON в data-*
     * приходит и от редактора).
     */
    private static function tagEnd(string $html, int $start): int
    {
        $len = strlen($html);
        $from = $start;
        while (true) {
            $gt = strpos($html, '>', $from);
            if ($gt === false) {
                return $len;
            }
            if (substr_count($html, '"', $start, $gt - $start) % 2 === 0) {
                return $gt + 1;
            }
            $from = $gt + 1;
        }
    }

    /** Позиция сразу за закрывающим тегом элемента, содержимое которого не разбираем. */
    private static function rawEnd(string $html, int $from, string $name): int
    {
        $len = strlen($html);
        $close = '</' . $name;
        $open = '<' . $name;
        $depth = 1;
        $i = $from;

        while ($i < $len) {
            $c = stripos($html, $close, $i);
            if ($c === false) {
                return $len;
            }
            // Вложенность бывает только у svg; script/style/pre/textarea её не знают.
            if ($name === 'svg') {
                $o = stripos($html, $open, $i);
                if ($o !== false && $o < $c && preg_match('#^<svg[\s/>]#i', substr($html, $o, 5)) === 1) {
                    $depth++;
                    $i = $o + strlen($open);
                    continue;
                }
            }
            $gt = strpos($html, '>', $c);
            $end = $gt === false ? $len : $gt + 1;
            if (--$depth === 0) {
                return $end;
            }
            $i = $end;
        }

        return $len;
    }

    /**
     * @param list<array{t:string, html:string, name:string, kind:string, block:bool}> $tokens
     */
    private static function emit(array $tokens): string
    {
        $out = '';
        $depth = 0;
        /** @var array{t:string, html:string, name:string, kind:string, block:bool}|null $prev */
        $prev = null;
        $pendingWs = false;

        foreach ($tokens as $token) {
            $isText = $token['t'] === 'text';
            if ($isText && trim($token['html']) === '') {
                $pendingWs = true;
                continue;
            }

            $leftBlock = $prev !== null && $prev['t'] !== 'text' && $prev['block'];
            $rightBlock = !$isText && $token['block'];

            if ($prev === null) {
                $break = false;
            } elseif ($prev['t'] === 'text') {
                // Слева текст: перевод строки допустим только вместо уже
                // существующего пробела у границы блока.
                $break = $rightBlock && $out !== '' && ctype_space(substr($out, -1));
            } elseif ($isText) {
                $break = $leftBlock && ($pendingWs || preg_match('/^\s/', $token['html']) === 1);
            } else {
                $break = $leftBlock || $rightBlock;
            }

            if ($token['t'] === 'tag' && $token['kind'] === 'close' && $token['block']) {
                $depth = max(0, $depth - 1);
            }

            if ($break) {
                $out = rtrim($out);
                if ($out !== '') {
                    $out .= "\n" . str_repeat(self::INDENT, $depth);
                }
            } elseif ($pendingWs && $out !== '') {
                // Значимый пробел между строчными элементами сохраняем.
                $out .= ' ';
            }

            $out .= $isText ? self::text($token['html'], $depth, $break) : $token['html'];

            if ($token['t'] === 'tag' && $token['kind'] === 'open' && $token['block']) {
                $depth++;
            }
            $pendingWs = false;
            $prev = $token;
        }

        return $out === '' ? $out : $out . "\n";
    }

    /** Текстовый узел: переносы внутри него выравниваются по текущему отступу. */
    private static function text(string $text, int $depth, bool $break): string
    {
        if ($break) {
            $text = ltrim($text);
        }

        return (string) preg_replace(
            '/[ \t]*\r?\n[ \t\r\n]*/',
            "\n" . str_repeat(self::INDENT, $depth),
            $text
        );
    }
}
