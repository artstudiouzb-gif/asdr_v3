<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Блок hero: титул, подзаголовок, фон-фото, безопасная кнопка', function () {
    $out = BlockRenderer::render(['id' => 20, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode([
        // bg_type задаётся явно: после редизайна отсутствие типа равно «Без
        // фона», и оставшееся в поле фото фон не включает — контракт зафиксирован
        // в 113_hero_bg_type_test.
        'title' => 'Пресс-центр', 'subtitle' => 'Оперативная информация',
        'bg_type' => 'image',
        'image' => '/uploads/public/x.jpg', 'button_text' => 'Все новости', 'button_url' => '/news',
    ])])['html'];
    assert_contains('cms-block cms-block--hero', $out);
    assert_contains('block-hero--media', $out);
    assert_contains('Пресс-центр', $out);
    assert_contains('<picture class="block-hero__media media-position--center-center', $out);
    assert_contains('src="/uploads/public/x.jpg"', $out);
    assert_contains('fetchpriority="high"', $out);
    assert_contains('href="/news"', $out);

    $bad = BlockRenderer::render(['id' => 21, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode([
        'title' => 'T', 'button_text' => 'X', 'button_url' => 'javascript:alert(1)',
    ])])['html'];
    assert_true(!str_contains($bad, 'block-hero__button'), 'javascript: кнопка не рендерится');
});

test('Компактные карточки: плитки, первая активна', function () {
    $out = BlockRenderer::render(['id' => 22, 'type' => 'cards_grid', 'custom_css' => null, 'data' => json_encode([
        'variant' => 'compact', 'title' => 'Категории', 'items' => [
            ['icon_svg' => 'news', 'title' => 'Новости', 'url' => '/news'],
            ['icon_svg' => '', 'title' => 'Видео', 'url' => ''],
        ],
    ])])['html'];
    assert_contains('cms-block--cards_grid', $out);
    assert_contains('cat-tile is-active', $out);
    assert_contains('href="/news"', $out);
    assert_contains('<span class="cat-tile"', $out);
});

test('Список документов-ссылок: элементы и заглушка', function () {
    $out = BlockRenderer::render(['id' => 23, 'type' => 'docs_list', 'custom_css' => null, 'data' => json_encode([
        'variant' => 'links', 'title' => 'Материалы', 'items' => [['title' => 'Фотоальбомы', 'url' => '/albums']],
    ])])['html'];
    assert_contains('cms-block--docs_list', $out);
    assert_contains('doc-card--compact', $out);
    assert_contains('Фотоальбомы', $out);

    $empty = BlockRenderer::render(['id' => 24, 'type' => 'docs_list', 'custom_css' => null, 'data' => json_encode(['title' => '', 'items' => []])])['html'];
    assert_contains('block-docslist__empty', $empty);
});

test('Блок hero: видео-фон и надзаголовок; безопасность кнопок', function () {
    $out = BlockRenderer::render(['id' => 25, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Строим будущее', 'eyebrow' => 'Стратегия', 'image' => '/uploads/public/p.jpg',
        'bg_type' => 'video',
        'video_url' => '/uploads/public/hero.mp4',
        'button_text' => 'Об агентстве', 'button_url' => '/o-nas',
        'button2_text' => 'Стратегия', 'button2_url' => 'javascript:alert(1)',
        'video_button_text' => 'Смотреть видео', 'video_button_url' => '/news',
    ])])['html'];
    assert_contains('block-hero--video', $out);
    assert_contains('<video', $out);
    assert_contains('/uploads/public/hero.mp4', $out);
    assert_contains('block-hero__eyebrow', $out);
    assert_contains('block-hero__play', $out);
    assert_true(!str_contains($out, 'block-hero__button--ghost'), 'javascript: вторая кнопка отсеяна');
});

test('Варианты cards_grid и media_gallery: обёртки и содержимое', function () {
    $cards = BlockRenderer::render(['id' => 26, 'type' => 'cards_grid', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Направления', 'all_text' => 'Все', 'all_url' => '/news', 'columns' => 5,
        'items' => [['icon_svg' => 'trending-up', 'title' => 'Рост', 'text' => 'описание', 'url' => '/news']],
    ])])['html'];
    assert_contains('cms-block--cards_grid', $cards);
    assert_contains('feature-card', $cards);
    assert_contains('section-head__all', $cards);

    $imgs = BlockRenderer::render(['id' => 27, 'type' => 'cards_grid', 'custom_css' => null, 'data' => json_encode([
        'variant' => 'image', 'title' => 'Проекты', 'items' => [['image' => '/uploads/public/p.jpg', 'title' => 'Проект', 'url' => '/news']],
    ])])['html'];
    assert_contains('imgcard', $imgs);
    assert_contains('/uploads/public/p.jpg', $imgs);

    $media = BlockRenderer::render(['id' => 28, 'type' => 'media_gallery', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Медиа', 'items' => [['image' => '/x.jpg', 'title' => 'Видео', 'meta' => '02:35', 'text' => '20 мая', 'url' => '/news']],
    ])])['html'];
    assert_contains('mediacard', $media);
    assert_contains('mediacard__duration', $media);
    assert_contains('02:35', $media);
});

test('Блок news_feature: обёртка, заголовок секции и ссылка «Все» (лента из БД)', function () {
    ensure_test_db();
    $out = \App\Core\BlockRenderer::render(['id' => 30, 'type' => 'news_feature', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Новости и аналитика', 'all_text' => 'Все новости', 'all_url' => '/news', 'limit' => 6,
    ])])['html'];
    assert_contains('cms-block--news_feature', $out);
    assert_contains('block-newsfeat', $out);
    assert_contains('section-head__all', $out);
    assert_contains('Новости и аналитика', $out);
});

test('Блок media_gallery: переключатели видео/фото при смешанном наборе', function () {
    $out = \App\Core\BlockRenderer::render(['id' => 31, 'type' => 'media_gallery', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Медиа', 'items' => [
            ['image' => '/x.jpg', 'title' => 'Видео', 'meta' => '02:35', 'kind' => 'video', 'url' => '/n'],
            ['image' => '/y.jpg', 'title' => 'Фото', 'meta' => '', 'kind' => 'photo', 'url' => '/a'],
        ],
    ])])['html'];
    assert_contains('media-tabs', $out);
    assert_contains('section-head block-mediagallery__head', $out);
    assert_contains('media-tabs__indicator', $out);
    assert_contains('media-tabs__tab-text', $out);
    assert_contains('data-media-kind="video"', $out);
    assert_contains('data-media-kind="photo"', $out);
    assert_contains('data-media-grid', $out);
    assert_contains('mediagallery-grid--cols-1', $out);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/media-gallery.css');
    assert_contains('.media-tabs__indicator', $css);
    assert_contains('.mediagallery-grid--desktop-4', $css);
    assert_not_contains('mask:', $css);
    assert_not_contains('media-tab-wing-offset', $css);

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains("grid.classList.add('mediagallery-grid--cols-'", $js);
});

test('Блок media_gallery: фото образуют равномерную сетку без растянутых карточек', function () {
    $rendered = \App\Core\BlockRenderer::render(['id' => 32, 'type' => 'media_gallery', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Фото', 'items' => [
            ['image' => '/a.jpg', 'title' => 'Фото 1', 'kind' => 'photo'],
            ['image' => '/b.jpg', 'title' => 'Фото 2', 'kind' => 'photo'],
            ['image' => '/c.jpg', 'title' => 'Фото 3', 'kind' => 'photo'],
        ],
    ])]);

    assert_contains('mediagallery-grid--cols-3', (string) ($rendered['html'] ?? ''));
    assert_not_contains('nth-last-child', (string) ($rendered['css'] ?? ''));
});
