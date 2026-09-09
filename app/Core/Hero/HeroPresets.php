<?php

declare(strict_types=1);

namespace App\Core\Hero;

/**
 * Готовые композиции обложки.
 *
 * Пресет — это разовое действие «поставить набор настроек», а не режим:
 * применённые значения попадают в те же поля формы и правятся дальше руками.
 * Ничего не блокируется — редактор берёт близкий вариант и доводит его, а не
 * собирает два десятка настроек с нуля.
 */
final class HeroPresets
{
    /** @var array<string, array{label: string, hint: string, settings: array<string, mixed>}> */
    public const PRESETS = [
        'editorial' => [
            'label' => 'Editorial',
            'hint' => 'Крупный заголовок слева, тёмное затемнение по диагонали — под фоторепортаж.',
            'settings' => [
                'width' => 'full',
                'height' => 'tall',
                'text_position' => 'left',
                'text_align_y' => 'bottom',
                'title_size' => 'xl',
                'subtitle_size' => 'm',
                'scheme' => 'dark',
                'content_scheme' => 'light',
                'overlay' => 'gradient',
                'overlay_color' => '#0b1220',
                'overlay_opacity' => 60,
                'overlay_direction' => 'to_top',
                'panel' => false,
                'nav_indicator' => 'counter_progress',
                'transition' => 'fade_slide',
            ],
        ],
        'government' => [
            'label' => 'Government',
            'hint' => 'Сдержанная официальная шапка: светлый фон, текст слева, без фотографии.',
            'settings' => [
                'width' => 'standard',
                'height' => 'compact',
                'text_position' => 'left',
                'text_align_y' => 'center',
                'title_size' => 'l',
                'subtitle_size' => 'm',
                'scheme' => 'light',
                'content_scheme' => 'dark',
                'overlay' => 'none',
                'panel' => false,
                'nav_arrows' => true,
                'nav_indicator' => 'dots',
                'transition' => 'fade',
                'autoplay' => false,
            ],
        ],
        'navy' => [
            'label' => 'Navy',
            'hint' => 'Фирменный тёмно-синий фон, светлый текст — базовый вариант главной.',
            'settings' => [
                'width' => 'full',
                'height' => 'regular',
                'text_position' => 'left',
                'text_align_y' => 'center',
                'title_size' => 'l',
                'subtitle_size' => 'm',
                'scheme' => 'navy',
                'content_scheme' => 'light',
                'overlay' => 'gradient',
                'overlay_color' => '#0b1a30',
                'overlay_opacity' => 45,
                'overlay_direction' => 'auto',
                'nav_indicator' => 'counter_progress',
                'transition' => 'fade_slide',
            ],
        ],
        'full_image' => [
            'label' => 'Full Image',
            'hint' => 'Фотография во весь экран, текст по центру, медленный наезд Ken Burns.',
            'settings' => [
                'width' => 'full',
                'height' => 'full',
                'text_position' => 'center',
                'text_align_y' => 'center',
                'title_size' => 'xl',
                'subtitle_size' => 'l',
                'scheme' => 'dark',
                'content_scheme' => 'light',
                'overlay' => 'solid',
                'overlay_color' => '#0b1220',
                'overlay_opacity' => 40,
                'panel' => false,
                'nav_indicator' => 'counter_progress',
                'transition' => 'kenburns',
                'transition_duration' => 900,
                'autoplay' => true,
                'autoplay_interval' => 7,
            ],
        ],
        'video' => [
            'label' => 'Video',
            'hint' => 'Фоновое видео с плотным затемнением; на телефоне вместо него постер.',
            'settings' => [
                'width' => 'full',
                'height' => 'tall',
                'text_position' => 'left',
                'text_align_y' => 'bottom',
                'title_size' => 'xl',
                'subtitle_size' => 'm',
                'scheme' => 'dark',
                'content_scheme' => 'light',
                'overlay' => 'gradient',
                'overlay_color' => '#080f1c',
                'overlay_opacity' => 55,
                'overlay_direction' => 'to_top',
                'nav_indicator' => 'counter',
                'transition' => 'fade',
                'autoplay' => false,
            ],
        ],
        'minimal' => [
            'label' => 'Minimal',
            'hint' => 'Только заголовок и подзаголовок, невысокая секция, точки вместо счётчика.',
            'settings' => [
                'width' => 'standard',
                'height' => 'compact',
                'text_position' => 'left',
                'text_align_y' => 'center',
                'title_size' => 'm',
                'subtitle_size' => 's',
                'scheme' => 'light',
                'content_scheme' => 'auto',
                'overlay' => 'none',
                'panel' => false,
                'nav_arrows' => false,
                'nav_indicator' => 'dots',
                'transition' => 'fade',
                'transition_duration' => 400,
                'autoplay' => false,
            ],
        ],
    ];

    /** @return array<string, string> ключ → подпись для списка */
    public static function labels(): array
    {
        return array_map(
            static fn (array $preset): string => (string) $preset['label'],
            self::PRESETS
        );
    }

    public static function has(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    public static function hint(string $key): string
    {
        return (string) (self::PRESETS[$key]['hint'] ?? '');
    }

    /**
     * Настройки обложки после применения пресета.
     *
     * Пресет перекрывает только те ключи, которые задаёт сам. Настройки, к
     * которым он не относится (высота на телефоне, ширина текстовой колонки,
     * пауза при наведении, свайп), остаются как были — иначе выбор пресета
     * «ради цвета» сбрасывал бы половину уже сделанной работы.
     *
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function apply(string $key, array $current): array
    {
        if (!self::has($key)) {
            return HeroSettings::withDefaults($current);
        }

        return HeroSettings::withDefaults(array_merge($current, self::PRESETS[$key]['settings']));
    }
}
