<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Круглая печать коллажа: надпись, идущая по окружности.
 *
 * Разметка живёт здесь, а не в шаблоне блока, по двум причинам. Во-первых,
 * шаблонам запрещено носить собственную геометрию в `<svg>` — иконки берутся
 * из `Icon::render`, а этот круг иконкой не является: это текст, положенный на
 * траекторию. Во-вторых, кегль надписи задан **атрибутом**, а не стилем: число
 * измеряется в единицах viewBox, то есть это доля диаметра, а не размер текста
 * страницы. В шкале `--step-*` ему места нет — она про читаемый текст.
 */
final class CollageBadge
{
    /** Радиус окружности в единицах viewBox 100×100. */
    private const RADIUS = 38;

    /** Кегль надписи в тех же единицах: 7.5 — это 7.5 % ширины печати. */
    private const FONT_SIZE = '7.5';

    /**
     * @param string $text  надпись (уже очищенная нормализатором)
     * @param string $pathId уникальный в пределах страницы id траектории
     */
    public static function ring(string $text, string $pathId): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $id = htmlspecialchars($pathId, ENT_QUOTES);
        // Надпись повторяется дважды, иначе кольцо не замыкается и остаётся
        // пустая дуга. Диктору она читается один раз — отдельной строкой
        // рядом, поэтому сама печать скрыта от него целиком.
        $ring = htmlspecialchars($text . ' • ' . $text . ' • ', ENT_QUOTES);
        $r = self::RADIUS;
        $path = 'M50,50 m-' . $r . ',0 a' . $r . ',' . $r . ' 0 1,1 ' . ($r * 2) . ',0 a'
            . $r . ',' . $r . ' 0 1,1 -' . ($r * 2) . ',0';

        return '<svg class="collage__badge-ring" viewBox="0 0 100 100" aria-hidden="true" focusable="false">'
            . '<defs><path id="' . $id . '" d="' . $path . '"></path></defs>'
            . '<text font-size="' . self::FONT_SIZE . '"><textPath href="#' . $id . '" startOffset="0">'
            . $ring . '</textPath></text></svg>';
    }
}
