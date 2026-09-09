<?php

declare(strict_types=1);

use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/*
 * Каждая настройка обложки обязана что-то менять на выводе.
 *
 * За день трижды находилось одно и то же: поле в форме есть, значение в базу
 * доезжает, а на сайте не меняется ничего — схема слайда не красила фон,
 * наложение считалось только затемнением, цвет текста затирался автоподбором.
 * Такие расхождения молчат: форма выглядит рабочей, обложка выглядит нормально.
 *
 * Тест рендерит обложку дважды — с умолчанием и с изменённым значением — и
 * требует, чтобы вывод отличался. Зависимые настройки проверяются в контексте,
 * где они вообще действуют: цвет подложки без включённой подложки не значит
 * ничего.
 *
 * Новая настройка обязана получить пробу здесь, иначе тест падает: это и есть
 * требование «у настройки должен быть потребитель».
 */

/**
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $slide
 */
function hero_probe_output(array $settings, array $slide = []): string
{
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Проба'],
        [
            ['id' => 1, 'hero_id' => 1, 'title' => 'Т', 'sort_order' => 0, 'is_active' => 1,
             'data' => HeroSlideData::withDefaults(array_merge([
                 'title' => 'Заголовок', 'subtitle' => 'Описание', 'eyebrow' => 'Над',
                 'media_type' => 'image', 'image' => '/uploads/public/a.jpg',
             ], $slide))],
            ['id' => 2, 'hero_id' => 1, 'title' => 'Т2', 'sort_order' => 1, 'is_active' => 1,
             'data' => HeroSlideData::withDefaults(['title' => 'Второй', 'media_type' => 'none'])],
        ],
        HeroSettings::withDefaults($settings),
        7
    );

    return $rendered['html'] . "\n===CSS===\n" . ($rendered['css'] ?? '');
}

test('Каждая настройка обложки влияет на вывод', function () {
    $probe = [
    'width' => 'standard', 'height' => 'compact', 'height_value' => '600px',
    'height_mobile' => 'tall', 'height_mobile_value' => '400px',
    'text_position' => 'right', 'text_align_y' => 'bottom', 'text_width' => '500px',
    'title_size' => 'xl', 'subtitle_size' => 's',
    'text_offset_top' => 33,
    'scheme' => 'light', 'scheme_bg' => '#123456', 'scheme_text' => '#654321', 'scheme_accent' => '#abcdef',
    'content_scheme' => 'dark',
    'overlay' => 'solid', 'overlay_color' => '#00ff00', 'overlay_opacity' => 77, 'overlay_direction' => 'to_top',
    'panel' => true, 'panel_color' => '#ff00ff', 'panel_opacity' => 66,
    'nav_arrows' => false, 'nav_arrows_mobile' => false, 'nav_indicator' => 'dots', 'nav_swipe' => false,
    'autoplay' => true, 'autoplay_interval' => 13,
    'transition' => 'kenburns', 'transition_duration' => 1234,
    ];

    // Настройки, которые действуют только вместе с другой.
    $context = [
    'height_value' => ['height' => 'custom'],
    'height_mobile_value' => ['height_mobile' => 'custom'],
    'scheme_bg' => ['scheme' => 'custom'],
    'scheme_text' => ['scheme' => 'custom'],
    'panel_color' => ['panel' => true],
    'panel_opacity' => ['panel' => true],
    'autoplay_interval' => ['autoplay' => true],
    ];

    $mute = [];
    foreach (HeroSettings::defaults() as $key => $default) {
        if (!array_key_exists($key, $probe)) {
            $mute[] = $key . ' — нет пробы в тесте';
            continue;
        }
        $ctx = $context[$key] ?? [];
        if (hero_probe_output(array_merge($ctx, [$key => $probe[$key]])) === hero_probe_output($ctx)) {
            $mute[] = $key . ' — значение ничего не меняет';
        }
    }

    assert_same([], $mute, "настройки обложки без последствий:\n      " . implode("\n      ", $mute));
});

test('Каждое поле слайда влияет на вывод', function () {
    $probe = [
    'eyebrow' => 'Другое над', 'title' => 'Другой заголовок', 'subtitle' => 'Другое описание',
    'watermark' => 'ЗНАК', 'watermark_size' => 44, 'watermark_x' => 'left', 'watermark_y' => 'top',
    'watermark_opacity' => 33,
    'media_type' => 'video', 'image' => '/uploads/public/z.jpg', 'image_mobile' => '/uploads/public/m.jpg',
    'image_position' => 'left-top', 'image_position_mobile' => 'right-bottom', 'image_fit' => 'contain',
    'video_url' => '/uploads/public/v.mp4', 'video_mobile_url' => '/uploads/public/vm.mp4',
    'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ',
    'poster' => '/uploads/public/p.jpg', 'mobile_media' => 'desktop',
    'link_url' => '/куда', 'link_new_tab' => true,
    'scheme' => 'light', 'scheme_bg' => '#123456', 'scheme_text' => '#654321', 'scheme_accent' => '#abcdef',
    'content_scheme' => 'dark',
    'overlay' => 'solid', 'overlay_color' => '#00ff00', 'overlay_opacity' => 77, 'overlay_direction' => 'to_top',
    'panel' => 'on',
    'text_position' => 'right', 'text_align_y' => 'bottom',
    'title_size' => 'xl', 'subtitle_size' => 's',
    'text_offset_top' => 33,
    'cta_enabled' => true, 'cta_text' => 'Кнопка', 'cta_url' => '/cta', 'cta_style' => 'ghost',
    'cta_icon' => 'arrow-right', 'cta_new_tab' => true,
    'cta2_enabled' => true, 'cta2_text' => 'Вторая', 'cta2_url' => '/cta2', 'cta2_style' => 'primary',
    'cta2_icon' => 'arrow-right', 'cta2_new_tab' => true,
    'art_image' => '/uploads/public/art.png', 'art_alt' => 'Описание знака', 'art_position' => 'right',
    'art_size' => 'custom', 'art_width' => 200,
    'duration' => 9, 'css_class' => 'my-slide',
    '_visible_from' => '2020-01-01 00:00', '_visible_to' => '2099-01-01 00:00', '_visible_device' => 'desktop',
    ];

    $context = [
    'watermark_size' => ['watermark' => 'ЗНАК'], 'watermark_x' => ['watermark' => 'ЗНАК'],
    'watermark_y' => ['watermark' => 'ЗНАК'], 'watermark_opacity' => ['watermark' => 'ЗНАК'],
    'video_url' => ['media_type' => 'video'], 'video_mobile_url' => ['media_type' => 'video', 'video_url' => '/uploads/public/v.mp4', 'mobile_media' => 'mobile_video'],
    'youtube_url' => ['media_type' => 'youtube'], 'youtube_id' => ['media_type' => 'youtube'],
    'poster' => ['media_type' => 'video', 'video_url' => '/uploads/public/v.mp4'],
    'mobile_media' => ['media_type' => 'video', 'video_url' => '/uploads/public/v.mp4'],
    'scheme_bg' => ['scheme' => 'custom'], 'scheme_text' => ['scheme' => 'custom'], 'scheme_accent' => ['scheme' => 'custom'],
    'overlay_color' => ['overlay' => 'solid'], 'overlay_opacity' => ['overlay' => 'solid'],
    'overlay_direction' => ['overlay' => 'gradient'],
    'cta_text' => ['cta_enabled' => true, 'cta_url' => '/cta'], 'cta_url' => ['cta_enabled' => true, 'cta_text' => 'К'],
    'cta_style' => ['cta_enabled' => true, 'cta_text' => 'К', 'cta_url' => '/cta'],
    'cta_icon' => ['cta_enabled' => true, 'cta_text' => 'К', 'cta_url' => '/cta'],
    'cta_new_tab' => ['cta_enabled' => true, 'cta_text' => 'К', 'cta_url' => '/cta'],
    'cta2_text' => ['cta2_enabled' => true, 'cta2_url' => '/c'], 'cta2_url' => ['cta2_enabled' => true, 'cta2_text' => 'К'],
    'cta2_style' => ['cta2_enabled' => true, 'cta2_text' => 'К', 'cta2_url' => '/c'],
    'cta2_icon' => ['cta2_enabled' => true, 'cta2_text' => 'К', 'cta2_url' => '/c'],
    'cta2_new_tab' => ['cta2_enabled' => true, 'cta2_text' => 'К', 'cta2_url' => '/c'],
    'art_alt' => ['art_image' => '/uploads/public/art.png'],
    'art_position' => ['art_image' => '/uploads/public/art.png'],
    'art_size' => ['art_image' => '/uploads/public/art.png'],
    'art_width' => ['art_image' => '/uploads/public/art.png', 'art_size' => 'custom'],
    'image_mobile' => ['media_type' => 'image', 'image' => '/uploads/public/a.jpg'],
    'image_position_mobile' => ['media_type' => 'image', 'image' => '/uploads/public/a.jpg'],
    'image_fit' => ['media_type' => 'image', 'image' => '/uploads/public/a.jpg'],
    'media_type' => ['video_url' => '/uploads/public/v.mp4'],
    'youtube_id' => ['media_type' => 'youtube', 'youtube_url' => ''],
    'link_new_tab' => ['link_url' => '/куда'],
    'cta_enabled' => ['cta_text' => 'Кнопка', 'cta_url' => '/cta'],
    'cta2_enabled' => ['cta2_text' => 'Вторая', 'cta2_url' => '/cta2'],
    ];

    // Два исключения, и оба объяснимы: youtube_id пересчитывается из ссылки
    // при нормализации, а окно показа слайда отсекается в HeroSlide::forHero
    // до рендера — сюда такие слайды просто не доходят.
    $derived = ['youtube_id', '_visible_from', '_visible_to'];

    $mute = [];
    foreach (HeroSlideData::defaults() as $key => $default) {
        if (in_array($key, $derived, true)) {
            continue;
        }
        if (!array_key_exists($key, $probe)) {
            $mute[] = $key . ' — нет пробы в тесте';
            continue;
        }
        $ctx = $context[$key] ?? [];
        if (hero_probe_output([], array_merge($ctx, [$key => $probe[$key]])) === hero_probe_output([], $ctx)) {
            $mute[] = $key . ' — значение ничего не меняет';
        }
    }

    assert_same([], $mute, "поля слайда без последствий:\n      " . implode("\n      ", $mute));
});
