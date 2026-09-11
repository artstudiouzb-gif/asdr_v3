<?php

declare(strict_types=1);

use App\Core\Hero\HeroMediaRenderer;
use App\Core\Hero\HeroNavigation;
use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

test('Hero media: only the first responsive poster has high fetch priority', function (): void {
    $data = HeroSlideData::withDefaults([
        'media_type' => 'image', 'image' => '/uploads/public/hero.jpg',
        'image_mobile' => '/uploads/public/hero-mobile.jpg',
    ]);
    $first = HeroMediaRenderer::render($data, true);
    $later = HeroMediaRenderer::render($data, false);
    assert_contains('fetchpriority="high"', $first);
    assert_contains('hero-mobile.jpg', $first);
    assert_not_contains('loading="lazy"', $first);
    assert_contains('loading="lazy"', $later);
    assert_not_contains('fetchpriority="high"', $later);
});

test('Hero media: videos cannot fetch before runtime selects the visible source', function (): void {
    $html = HeroMediaRenderer::render(HeroSlideData::withDefaults([
        'media_type' => 'video', 'video_url' => '/uploads/public/desktop.mp4',
        'video_mobile_url' => '/uploads/public/mobile.mp4', 'image' => '/uploads/public/poster.jpg',
    ]), true);
    assert_contains('<source data-hero-src="/uploads/public/desktop.mp4"', $html);
    assert_not_contains('<source src=', $html);
    assert_contains('data-hero-video-mobile="/uploads/public/mobile.mp4"', $html);
    assert_contains('preload="none"', $html);
    assert_contains('hero__fallback', $html);
});

test('Hero navigation: a single video has a pause control but no slide arrows', function (): void {
    $slides = [['data' => HeroSlideData::withDefaults(['media_type' => 'video', 'video_url' => '/v.mp4'])]];
    $html = HeroNavigation::render($slides, HeroSettings::defaults(), 1);
    assert_contains('data-hero-toggle', $html);
    assert_not_contains('data-hero-prev', $html);
    assert_not_contains('data-hero-next', $html);
    assert_not_contains('aria-pressed', $html, 'changing action labels use command buttons');
});

test('Hero renderer: pause precedes slide links and enhancement owns inert state', function (): void {
    $slides = [
        ['data' => HeroSlideData::withDefaults([
            'title' => 'One', 'link_url' => '/one',
            'cta_enabled' => true, 'cta_text' => 'Open', 'cta_url' => '/open', 'cta_icon' => 'arrow-right',
        ])],
        ['data' => HeroSlideData::withDefaults(['title' => 'Two', 'link_url' => '/two'])],
    ];
    $html = HeroRenderer::render(['name' => 'Demo'], $slides, HeroSettings::withDefaults(['autoplay' => true]), 15)['html'];
    assert_true(strpos($html, 'data-hero-toggle') < strpos($html, 'href="/one"'));
    assert_not_contains(' inert', $html);
    assert_contains('aria-atomic="true"', $html);
    assert_contains('hero__cta hero__cta--primary hero__cta--with-icon', $html);
    assert_contains('hero__cta-icon', $html);
    assert_contains('width="46" height="46"', $html, 'рендерер не должен возвращать отдельный мелкий размер иконки');
});

test('Hero navigation: static image needs no motion control; Ken Burns does', function (): void {
    $slides = [['data' => HeroSlideData::withDefaults(['title' => 'Still', 'image' => '/still.jpg'])]];
    assert_same('', HeroNavigation::render($slides, HeroSettings::defaults(), 1));
    $html = HeroNavigation::render($slides, HeroSettings::withDefaults(['transition' => 'kenburns']), 1);
    assert_contains('data-hero-toggle', $html);
});

test('Hero editor: explicit color background wins over previously uploaded media', function (): void {
    $data = HeroSlideData::normalize([
        'media_type' => 'none', 'title' => 'Color only',
        'image' => '/image.jpg', 'video_url' => '/video.mp4', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);
    assert_same('none', $data['media_type']);
    assert_same('', HeroSlideData::fallbackImage($data));
    assert_same('', HeroMediaRenderer::render($data, true));
    assert_same('image', HeroSlideData::normalize(['image' => '/image.jpg'])['media_type']);
});

test('Hero editor: switching to an image cannot reuse the old video poster', function (): void {
    $data = HeroSlideData::normalize(['media_type' => 'image', 'image' => '/new.jpg', 'poster' => '/old.jpg']);
    assert_same('/new.jpg', HeroSlideData::fallbackImage($data));
});
