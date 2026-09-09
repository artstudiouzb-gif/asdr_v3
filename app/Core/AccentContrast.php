<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Контрастные производные акцентного цвета.
 *
 * Акцент выбирает администратор, и «красивый» цвет почти всегда слишком
 * светлый для мелкого текста: бирюзовый #17999b на белом даёт 3.1:1 при норме
 * WCAG AA 4.5:1. Раньше «текстовый» вариант был прописан в CSS константой —
 * то есть при смене акцента в админке он оставался бирюзовым и переставал
 * иметь отношение к теме.
 *
 * Здесь из выбранного акцента считаются два варианта: для светлого фона
 * (затемняем) и для тёмного (осветляем) — ровно до порога контраста, не
 * дальше, чтобы цвет остался узнаваемым.
 */
final class AccentContrast
{
    /** Порог WCAG AA для обычного текста. */
    public const AA_NORMAL = 4.5;

    /** @return array{0:int,1:int,2:int} */
    public static function toRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [23, 153, 155]; // запасной бирюзовый
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(
            static fn (int $v): int => max(0, min(255, $v)),
            $rgb
        ));
    }

    /** Относительная яркость по WCAG. */
    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::toRgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Коэффициент контраста двух цветов (1..21). */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);

        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    /**
     * Вариант акцента для текста на светлом фоне: затемняем шагами, пока не
     * наберём нужный контраст. Если цвет уже проходит — возвращаем как есть.
     */
    public static function onLight(string $accent, string $background = '#ffffff', float $target = self::AA_NORMAL): string
    {
        return self::adjust($accent, $background, $target, false);
    }

    /** Вариант для тёмного фона: наоборот, осветляем. */
    public static function onDark(string $accent, string $background = '#173a63', float $target = self::AA_NORMAL): string
    {
        return self::adjust($accent, $background, $target, true);
    }

    /**
     * Цвет текста поверх заливки акцентом (кнопка, вкладка, плашка). Белый
     * читается не на всяком акценте: на бирюзовом #00a0a6 он даёт 3.19:1 при
     * норме 4.5:1 для мелкого текста. Берём тот из двух, что контрастнее.
     */
    public static function onFill(string $accent, string $light = '#ffffff', string $dark = '#0b1a30'): string
    {
        return self::ratio($light, $accent) >= self::ratio($dark, $accent) ? $light : $dark;
    }

    /**
     * Подгонка яркости шагами по 4%: цвет тянется к чёрному или белому ровно
     * до порога контраста. Пошагово, а не сразу к краю, чтобы акцент не
     * выцвел до неузнаваемости.
     */
    private static function adjust(string $accent, string $background, float $target, bool $lighten): string
    {
        $current = self::toHex(self::toRgb($accent));
        if (self::ratio($current, $background) >= $target) {
            return $current;
        }

        [$r, $g, $b] = self::toRgb($current);
        for ($step = 0; $step < 25; $step++) {
            if ($lighten) {
                $r = (int) round($r + (255 - $r) * 0.12);
                $g = (int) round($g + (255 - $g) * 0.12);
                $b = (int) round($b + (255 - $b) * 0.12);
            } else {
                $r = (int) round($r * 0.88);
                $g = (int) round($g * 0.88);
                $b = (int) round($b * 0.88);
            }
            $candidate = self::toHex([$r, $g, $b]);
            if (self::ratio($candidate, $background) >= $target) {
                return $candidate;
            }
        }

        // Крайний случай (например, акцент на почти таком же фоне): берём
        // гарантированно контрастный чёрный/белый, чем оставить нечитаемое.
        return $lighten ? '#ffffff' : '#000000';
    }
}
