<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;

test('Hero normalizer: формирует стабильный JSON-контракт и ограничивает значения', function () {
    $data = HeroBlockNormalizer::normalize([
        'title_field' => '  Заголовок  ',
        'hero_width' => 'standard',
        'hero_height' => 'custom',
        'hero_height_value' => '2500',
        'hero_height_unit' => 'px',
        'eyebrow' => '  Раздел  ',
        'subtitle' => '  Подзаголовок  ',
        'bg_type' => 'none',
        'image' => '/uploads/public/hero.jpg',
        'video_url' => '',
        'youtube_url' => '',
        'overlay_enabled' => '1',
        'overlay_mode' => 'invalid',
        'overlay_direction' => '90deg;background:red',
        'overlay_color' => '#ABCDEF',
        'overlay_opacity' => '150',
        'text_position' => 'outside',
        'text_width_value' => '5',
        'text_width_unit' => '%',
        'text_color' => '#112233',
        'button_color' => '#445566',
        'button_color_off' => '1',
        'bg_color' => 'bad',
        'panel_enabled' => '1',
        'panel_color' => '#AABBCC',
        'panel_opacity' => '-10',
        'button_text' => ' Подробнее ',
        'button_url' => ' javascript:alert(1) ',
        'button2_text' => ' Вторая ',
        'button2_url' => ' /about ',
        'video_button_text' => ' Видео ',
        'video_button_url' => ' https://example.com/video ',
    ]);

    assert_same([
        // Ноль = обложка собрана прямо в блоке (старый способ). Отличная от
        // нуля ссылка означает, что блок только размещает обложку из раздела
        // «Обложки», а поля ниже в выводе не участвуют.
        'hero_id' => 0,
        'title' => 'Заголовок',
        'width' => 'standard',
        'height' => 'custom',
        'custom_height' => '2000px',
        'eyebrow' => 'Раздел',
        'subtitle' => 'Подзаголовок',
        'bg_type' => 'image',
        'image' => '/uploads/public/hero.jpg',
        // Телефон: свой кадр, своя высота и режим фонового видео.
        'image_mobile' => '',
        'video_mobile' => 'poster',
        'height_mobile' => '',
        'custom_height_mobile' => '',
        'image_position' => 'center-center',
        'image_position_mobile' => 'center-center',
        'video_url' => '',
        'youtube_url' => '',
        'overlay_enabled' => true,
        'overlay_mode' => 'gradient',
        'overlay_direction' => 'auto',
        'overlay_color' => '#abcdef',
        'overlay_opacity' => 100,
        'text_position' => 'left',
        'text_align_y' => 'center',
        // Картинка поверх фона: без адреса поле пустое, позиция и размер
        // всегда в контракте — шаблон не должен угадывать умолчания.
        'art_image' => '',
        'art_alt' => '',
        'art_position' => 'above',
        'art_size' => 'medium',
        'text_width' => '10%',
        'text_color' => '#112233',
        'button_color' => '',
        'bg_color' => '',
        'panel_enabled' => true,
        'panel_color' => '#aabbcc',
        'panel_opacity' => 0,
        'button_text' => 'Подробнее',
        'button_url' => '',
        'button_icon' => '',
        'button_icon_image' => '',
        'button2_text' => 'Вторая',
        'button2_url' => '/about',
        'button2_icon' => '',
        'button2_icon_image' => '',
        'video_button_text' => 'Видео',
        'video_button_url' => 'https://example.com/video',
        // Обложка без слайдов остаётся одиночной: пустой список и выключенная
        // автопрокрутка — часть того же контракта.
        'slides' => [],
        'autoplay' => 0,
    ], $data);
});

test('Hero normalizer: наложение и подложка по умолчанию выключены', function () {
    $data = HeroBlockNormalizer::normalize([]);

    assert_same(false, $data['overlay_enabled']);
    assert_same('gradient', $data['overlay_mode']);
    assert_same(35, $data['overlay_opacity']);
    assert_same(false, $data['panel_enabled']);
});

test('Hero normalizer: неверное направление не меняет режим затемнения', function () {
    $data = HeroBlockNormalizer::normalize([
        'overlay_direction' => 'solid',
    ]);

    assert_same('gradient', $data['overlay_mode']);
    assert_same('auto', $data['overlay_direction']);
});

test('Hero normalizer: приоритет фонового медиа не изменился', function () {
    $youtube = HeroBlockNormalizer::normalize([
        'bg_type' => 'none',
        'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        'video_url' => '/uploads/public/hero.mp4',
        'image' => '/uploads/public/hero.jpg',
    ]);
    assert_same('youtube', $youtube['bg_type']);

    $video = HeroBlockNormalizer::normalize([
        'bg_type' => 'none',
        'video_url' => '/uploads/public/hero.mp4',
        'image' => '/uploads/public/hero.jpg',
    ]);
    assert_same('video', $video['bg_type']);

    $image = HeroBlockNormalizer::normalize([
        'bg_type' => 'none',
        'image' => '/uploads/public/hero.jpg',
    ]);
    assert_same('image', $image['bg_type']);
});

test('Hero normalizer: не зависит от глобального POST', function () {
    $originalPost = $_POST;
    $_POST = ['title_field' => 'Глобальное значение'];

    try {
        $data = HeroBlockNormalizer::normalize(['title_field' => 'Явный вход']);
    } finally {
        $_POST = $originalPost;
    }
    assert_same('Явный вход', $data['title']);
});
