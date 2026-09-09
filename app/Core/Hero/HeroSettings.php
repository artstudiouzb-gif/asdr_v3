<?php

declare(strict_types=1);

namespace App\Core\Hero;

use App\Core\BlockData\BlockDataInput;

/**
 * Общие настройки обложки: то, что одинаково для всех её слайдов —
 * геометрия, цветовая схема, навигация, автопрокрутка и переход.
 *
 * Настройки хранятся одним JSON в `heroes.settings`. Список ключей объявлен
 * здесь единственный раз: `defaults()` — контракт для чтения (в том числе
 * старых записей, где ключа ещё нет), `normalize()` — для записи из формы.
 * Значения, которых нет в списке, до шаблона не доходят.
 */
final class HeroSettings
{
    public const SCHEMES = ['light', 'dark', 'navy', 'custom'];
    public const CONTENT_SCHEMES = ['auto', 'light', 'dark'];
    public const HEIGHTS = ['compact', 'regular', 'tall', 'full', 'custom'];
    public const INDICATORS = ['none', 'dots', 'counter', 'progress', 'counter_progress', 'thumbs'];
    public const TRANSITIONS = ['fade', 'slide', 'fade_slide', 'kenburns'];
    public const OVERLAYS = ['none', 'solid', 'gradient'];
    public const OVERLAY_DIRECTIONS = [
        'auto', 'to_right', 'to_left', 'to_bottom', 'to_top',
        'to_bottom_right', 'to_bottom_left', 'to_top_right', 'to_top_left',
    ];

    /**
     * Значения по умолчанию — они же описание всех допустимых ключей.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // --- Геометрия ---
            'width' => 'full',
            'height' => 'regular',
            'height_value' => '720px',
            'height_mobile' => '',
            'height_mobile_value' => '',

            // --- Раскладка контента ---
            'text_position' => 'left',
            'text_align_y' => 'center',
            'text_width' => '',
            'title_size' => 'l',
            'subtitle_size' => 'm',

            // Отступ всего текстового блока сверху, в пикселях. Промежутки
            // между заголовком, описанием и кнопками настройкой не являются —
            // это типографика, она задана переменными --hero-gap-* в
            // blocks/hero.css. На телефоне значение ограничивается высотой
            // окна (см. HeroRenderer::rootCss), поэтому отдельной мобильной
            // настройки нет.
            'text_offset_top' => 0,


            // --- Цвет ---
            // Схема обложки задаёт фон и цвет её собственного текста. Цвет
            // вложенных компонентов она НЕ навязывает: у белой карточки внутри
            // navy-обложки текст остаётся тёмным (см. content_scheme).
            'scheme' => 'navy',
            'scheme_bg' => '#0b1a30',
            'scheme_text' => '#ffffff',
            'scheme_accent' => '',
            'content_scheme' => 'auto',

            // --- Затемнение и подложка ---
            'overlay' => 'gradient',
            'overlay_color' => '#0b1a30',
            'overlay_opacity' => 45,
            'overlay_direction' => 'auto',
            'panel' => false,
            'panel_color' => '#0b1a30',
            'panel_opacity' => 40,

            // --- Навигация ---
            'nav_arrows' => true,
            'nav_arrows_mobile' => true,
            'nav_indicator' => 'counter_progress',
            'nav_swipe' => true,

            // --- Автопрокрутка ---
            'autoplay' => false,
            'autoplay_interval' => 6,

            // --- Переход ---
            'transition' => 'fade_slide',
            'transition_duration' => 700,
        ];
    }

    /**
     * Сохранённые настройки, дополненные умолчаниями и приведённые к
     * допустимым значениям. Читать `heroes.settings` мимо этого метода нельзя:
     * записи, сделанные до появления ключа, иначе дадут null в шаблоне.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function withDefaults(array $raw): array
    {
        $defaults = self::defaults();

        return self::coerce(array_merge($defaults, array_intersect_key($raw, $defaults)));
    }

    /**
     * Настройки из формы редактора.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $height = BlockDataInput::enum($input, 'height', self::HEIGHTS, 'regular');
        $heightMobile = BlockDataInput::enum($input, 'height_mobile', self::HEIGHTS, '');

        return self::coerce([
            'width' => BlockDataInput::enum($input, 'width', ['full', 'standard'], 'full'),
            'height' => $height,
            'height_value' => $height === 'custom'
                ? self::length($input, 'height_value', 'height_unit', ['px', 'vh', 'dvh', 'rem'])
                : '',
            'height_mobile' => $heightMobile,
            'height_mobile_value' => $heightMobile === 'custom'
                ? self::length($input, 'height_mobile_value', 'height_mobile_unit', ['px', 'vh', 'dvh', 'rem'])
                : '',

            'text_position' => BlockDataInput::enum($input, 'text_position', ['left', 'center', 'right'], 'left'),
            'text_align_y' => BlockDataInput::enum($input, 'text_align_y', ['top', 'center', 'bottom'], 'center'),
            'text_width' => self::length($input, 'text_width_value', 'text_width_unit', ['px', '%', 'vw']),
            'title_size' => BlockDataInput::enum($input, 'title_size', ['s', 'm', 'l', 'xl'], 'l'),
            'subtitle_size' => BlockDataInput::enum($input, 'subtitle_size', ['s', 'm', 'l'], 'm'),
            'text_offset_top' => self::gap($input['text_offset_top'] ?? null, 0),

            'scheme' => BlockDataInput::enum($input, 'scheme', self::SCHEMES, 'navy'),
            'scheme_bg' => self::hex($input['scheme_bg'] ?? '', '#0b1a30'),
            'scheme_text' => self::hex($input['scheme_text'] ?? '', '#ffffff'),
            'scheme_accent' => BlockDataInput::optionalColor($input, 'scheme_accent'),
            'content_scheme' => BlockDataInput::enum($input, 'content_scheme', self::CONTENT_SCHEMES, 'auto'),

            'overlay' => BlockDataInput::enum($input, 'overlay', self::OVERLAYS, 'gradient'),
            'overlay_color' => self::hex($input['overlay_color'] ?? '', '#0b1a30'),
            'overlay_opacity' => BlockDataInput::int($input, 'overlay_opacity', 0, 100, 45),
            'overlay_direction' => BlockDataInput::enum($input, 'overlay_direction', self::OVERLAY_DIRECTIONS, 'auto'),
            'panel' => !empty($input['panel']),
            'panel_color' => self::hex($input['panel_color'] ?? '', '#0b1a30'),
            'panel_opacity' => BlockDataInput::int($input, 'panel_opacity', 0, 100, 40),

            'nav_arrows' => !empty($input['nav_arrows']),
            'nav_arrows_mobile' => !empty($input['nav_arrows_mobile']),
            'nav_indicator' => BlockDataInput::enum($input, 'nav_indicator', self::INDICATORS, 'counter_progress'),
            'nav_swipe' => !empty($input['nav_swipe']),

            'autoplay' => !empty($input['autoplay']),
            'autoplay_interval' => BlockDataInput::int($input, 'autoplay_interval', 3, 30, 6),

            'transition' => BlockDataInput::enum($input, 'transition', self::TRANSITIONS, 'fade_slide'),
            'transition_duration' => BlockDataInput::int($input, 'transition_duration', 150, 2000, 700),
        ]);
    }

    /**
     * Приведение типов и границ. Вызывается и при чтении, и при записи: JSON
     * могли поправить руками или перенести со старой версии.
     *
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function coerce(array $s): array
    {
        $enum = static function (mixed $value, array $allowed, string $default): string {
            $value = is_scalar($value) ? (string) $value : '';

            return in_array($value, $allowed, true) ? $value : $default;
        };

        $s['width'] = $enum($s['width'] ?? '', ['full', 'standard'], 'full');
        $s['height'] = $enum($s['height'] ?? '', self::HEIGHTS, 'regular');
        $s['height_value'] = self::cssLength($s['height_value'] ?? '', ['px', 'vh', 'dvh', 'rem']);
        $s['height_mobile'] = $enum($s['height_mobile'] ?? '', self::HEIGHTS, '');
        $s['height_mobile_value'] = self::cssLength($s['height_mobile_value'] ?? '', ['px', 'vh', 'dvh', 'rem']);

        $s['text_position'] = $enum($s['text_position'] ?? '', ['left', 'center', 'right'], 'left');
        $s['text_align_y'] = $enum($s['text_align_y'] ?? '', ['top', 'center', 'bottom'], 'center');
        $s['text_width'] = self::cssLength($s['text_width'] ?? '', ['px', '%', 'vw']);
        $s['title_size'] = $enum($s['title_size'] ?? '', ['s', 'm', 'l', 'xl'], 'l');
        $s['subtitle_size'] = $enum($s['subtitle_size'] ?? '', ['s', 'm', 'l'], 'm');
        $s['text_offset_top'] = self::gap($s['text_offset_top'] ?? null, 0);

        $s['scheme'] = $enum($s['scheme'] ?? '', self::SCHEMES, 'navy');
        $s['scheme_bg'] = self::hex($s['scheme_bg'] ?? '', '#0b1a30');
        $s['scheme_text'] = self::hex($s['scheme_text'] ?? '', '#ffffff');
        $s['scheme_accent'] = self::hex($s['scheme_accent'] ?? '', '');
        $s['content_scheme'] = $enum($s['content_scheme'] ?? '', self::CONTENT_SCHEMES, 'auto');

        $s['overlay'] = $enum($s['overlay'] ?? '', self::OVERLAYS, 'gradient');
        $s['overlay_color'] = self::hex($s['overlay_color'] ?? '', '#0b1a30');
        $s['overlay_opacity'] = self::clamp($s['overlay_opacity'] ?? null, 0, 100, 45);
        $s['overlay_direction'] = $enum($s['overlay_direction'] ?? '', self::OVERLAY_DIRECTIONS, 'auto');
        $s['panel'] = !empty($s['panel']);
        $s['panel_color'] = self::hex($s['panel_color'] ?? '', '#0b1a30');
        $s['panel_opacity'] = self::clamp($s['panel_opacity'] ?? null, 0, 100, 40);

        $s['nav_arrows'] = !empty($s['nav_arrows']);
        $s['nav_arrows_mobile'] = !empty($s['nav_arrows_mobile']);
        $s['nav_indicator'] = $enum($s['nav_indicator'] ?? '', self::INDICATORS, 'counter_progress');
        $s['nav_swipe'] = !empty($s['nav_swipe']);

        $s['autoplay'] = !empty($s['autoplay']);
        $s['autoplay_interval'] = self::clamp($s['autoplay_interval'] ?? null, 3, 30, 6);

        $s['transition'] = $enum($s['transition'] ?? '', self::TRANSITIONS, 'fade_slide');
        $s['transition_duration'] = self::clamp($s['transition_duration'] ?? null, 150, 2000, 700);

        return $s;
    }

    /**
     * Пара «число + единица» из формы в готовую CSS-длину. Пустое или нулевое
     * значение — это «без своей длины», а не «ноль пикселей».
     *
     * @param array<string, mixed> $input
     * @param list<string> $units
     */
    private static function length(array $input, string $valueField, string $unitField, array $units): string
    {
        if (!isset($input[$valueField]) || !is_scalar($input[$valueField]) || trim((string) $input[$valueField]) === '') {
            return '';
        }
        $value = (float) $input[$valueField];
        if ($value <= 0) {
            return '';
        }

        $unit = is_scalar($input[$unitField] ?? null) ? (string) $input[$unitField] : '';
        $unit = in_array($unit, $units, true) ? $unit : $units[0];
        $limits = self::limitsFor($unit);

        return self::number(max($limits[0], min($limits[1], $value))) . $unit;
    }

    private static function gap(mixed $value, int $default): int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }

        return max(0, min(200, (int) $value));
    }

    /** @param list<string> $units */
    private static function cssLength(mixed $value, array $units): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value === '' || preg_match('/^(\d+(?:\.\d+)?)(px|vh|dvh|rem|%|vw)$/', $value, $m) !== 1) {
            return '';
        }
        if (!in_array($m[2], $units, true)) {
            return '';
        }
        $limits = self::limitsFor($m[2]);

        return self::number(max($limits[0], min($limits[1], (float) $m[1]))) . $m[2];
    }

    /** @return array{float, float} */
    private static function limitsFor(string $unit): array
    {
        return match ($unit) {
            'px' => [120.0, 2400.0],
            'rem' => [8.0, 140.0],
            default => [5.0, 150.0],
        };
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function hex(mixed $value, string $default): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $default;
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
