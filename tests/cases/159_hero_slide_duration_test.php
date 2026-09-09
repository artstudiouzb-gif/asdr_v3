<?php

declare(strict_types=1);

use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

test('Hero: длительность слайда имеет приоритет над общей', function () {
    $settings = HeroSettings::withDefaults([
        'autoplay' => true,
        'autoplay_interval' => 6,
    ]);

    $slides = [
        ['data' => HeroSlideData::withDefaults([
            'title' => 'Первый',
            'duration' => 12,
        ])],
        ['data' => HeroSlideData::withDefaults([
            'title' => 'Второй',
            'duration' => 0,
        ])],
    ];

    $result = HeroRenderer::render(['name' => 'Тест'], $slides, $settings, 501);

    assert_contains('data-hero-autoplay="6000"', $result['html']);
    assert_contains('data-hero-slide-duration="12000"', $result['html']);
    assert_not_contains('data-hero-slide-duration="6000"', $result['html']);

    $heroJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/hero.js');
    assert_contains("return own > 0 ? own : interval;", $heroJs);
});
