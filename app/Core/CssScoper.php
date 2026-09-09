<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Изолирует CSS блока, добавляя ко всем селекторам уникальный префикс
 * (например, "#block-123 "), чтобы стили одного блока никогда не влияли
 * на остальную страницу. Работает через разбор фигурных скобок, а не
 * регулярку "в лоб", поэтому корректно обрабатывает вложенные @media/@supports.
 */
final class CssScoper
{
    private const AT_RULES_PASSTHROUGH = ['@font-face', '@keyframes', '@-webkit-keyframes', '@page', '@charset'];

    public static function scope(string $css, string $scopeSelector): string
    {
        $css = self::stripDangerous($css);
        $css = self::stripComments($css);

        return trim(self::processBlock($css, $scopeSelector));
    }

    private static function stripDangerous(string $css): string
    {
        $css = preg_replace('/@import[^;]*;/i', '', $css) ?? $css;
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/expression\s*\(/i', 'blocked(', $css) ?? $css;
        $css = preg_replace('#</?\s*script[^>]*>#i', '', $css) ?? $css;

        return $css;
    }

    private static function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
    }

    private static function processBlock(string $css, string $scope): string
    {
        $length = strlen($css);
        $output = '';
        $i = 0;

        while ($i < $length) {
            $bracePos = strpos($css, '{', $i);
            if ($bracePos === false) {
                break;
            }

            $header = trim(substr($css, $i, $bracePos - $i));

            // Лишняя закрывающая скобка не должна съедать следующее правило:
            // «.a{}} body{…}» — это .a и body, а не селектор «} body». Иначе
            // правило получало невалидный селектор и молча пропадало.
            $strayBrace = strrpos($header, '}');
            if ($strayBrace !== false) {
                $header = trim(substr($header, $strayBrace + 1));
            }

            $closePos = self::findMatchingBrace($css, $bracePos);
            if ($closePos === false) {
                // Незакрытое правило: концом блока считаем конец ввода — так же
                // поступает браузер. Иначе одна забытая скобка выбрасывала
                // весь CSS блока, и редактор не понимал, куда делись стили.
                $closePos = $length;
            }

            $body = substr($css, $bracePos + 1, $closePos - $bracePos - 1);

            if ($header === '') {
                $i = $closePos + 1;
                continue;
            }

            if ($header[0] === '@') {
                $atRuleName = strtolower((string) strtok($header, ' ('));
                if (in_array($atRuleName, self::AT_RULES_PASSTHROUGH, true)) {
                    $output .= $header . '{' . $body . '}';
                } else {
                    // @media, @supports и т.п.: рекурсивно скоупим правила внутри
                    $output .= $header . '{' . self::processBlock($body, $scope) . '}';
                }
            } else {
                $output .= self::scopeSelectorList($header, $scope) . '{' . $body . '}';
            }

            $i = $closePos + 1;
        }

        return $output;
    }

    private static function scopeSelectorList(string $selectorList, string $scope): string
    {
        $selectors = array_map('trim', explode(',', $selectorList));

        $scoped = array_map(static function (string $selector) use ($scope): string {
            if ($selector === '') {
                return '';
            }
            if (stripos($selector, ':root') === 0) {
                return $scope . ' ' . trim(substr($selector, 5));
            }
            if (in_array(strtolower($selector), ['html', 'body', '*'], true)) {
                return $scope;
            }

            return $scope . ' ' . $selector;
        }, $selectors);

        return implode(', ', array_filter($scoped, static fn ($s) => $s !== ''));
    }

    private static function findMatchingBrace(string $css, int $openPos): int|false
    {
        $depth = 0;
        $length = strlen($css);

        for ($j = $openPos; $j < $length; $j++) {
            if ($css[$j] === '{') {
                $depth++;
            } elseif ($css[$j] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return false;
    }
}
