<?php

declare(strict_types=1);

namespace App\Core\Design;

/**
 * Приведение присланных чисел к CSS-значениям: одно правило на все поля
 * «Дизайна» в пикселях, иначе границы и округление разъехались бы.
 *
 * Вынесено из DesignSettings (там остался фасад с теми же именами).
 */
final class CssValue
{
    public static function pixels(string $raw, float $min, float $max): string
    {
        $raw = strtolower(trim(str_replace(',', '.', $raw)));
        $raw = preg_replace('/px$/', '', $raw) ?? '';
        if ($raw === '' || !preg_match('/^\d{1,3}(?:\.\d)?$/', $raw)) {
            return '';
        }
        $value = (float) $raw;
        if ($value < $min || $value > $max) {
            return '';
        }
        $normalized = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $normalized . 'px';
    }
}
