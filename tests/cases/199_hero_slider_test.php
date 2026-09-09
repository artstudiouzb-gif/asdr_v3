<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockRenderer;

/**
 * Обложка со слайдами. Оформление (высота, затемнение, подложка, цвета)
 * остаётся общим для блока, у слайда своё — содержимое, картинка, кнопки,
 * ссылка и окно показа.
 */

function hero_slider_html(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 920,
        'type' => 'hero',
        'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('hero'), $data)),
    ]);

    return (string) $rendered['html'];
}

test('Обложка: без слайдов разметка прежняя', function () {
    $html = hero_slider_html(['title' => 'Обычная обложка']);

    assert_not_contains('data-hero-slider', $html);
    assert_not_contains('block-hero__slide', $html);
    assert_contains('block-hero__title', $html);
});

test('Обложка: слайды дают карусель со стрелками и точками', function () {
    $html = hero_slider_html([
        'title' => 'Обложка',
        'autoplay' => 7,
        'slides' => [
            ['title' => 'Первый', 'image' => '/uploads/public/a.jpg'],
            ['title' => 'Второй', 'image' => '/uploads/public/b.jpg'],
        ],
    ]);

    assert_contains('data-hero-slider', $html);
    assert_contains('data-autoplay="7"', $html);
    assert_same(2, substr_count($html, 'block-hero__slide '), 'по слайду на запись');
    assert_contains('data-hero-prev', $html);
    assert_contains('data-hero-next', $html);
    assert_same(2, substr_count($html, 'data-slide-index'), 'точка на каждый слайд');
    // Доступность: роль карусели, подписи слайдов и живой статус.
    assert_contains('aria-roledescription', $html);
    assert_contains('aria-live="polite"', $html);
    assert_contains('aria-hidden="true"', $html);
});

test('Обложка: один слайд обходится без навигации', function () {
    $html = hero_slider_html(['slides' => [['title' => 'Единственный', 'image' => '/uploads/public/a.jpg']]]);

    assert_contains('block-hero__slide', $html);
    assert_not_contains('data-hero-next', $html);
    assert_not_contains('data-slide-index', $html);
});

test('Обложка: у слайда может быть свой видеофон', function () {
    $html = hero_slider_html([
        'slides' => [
            ['title' => 'Картинка', 'image' => '/uploads/public/a.jpg'],
            ['title' => 'Ролик', 'video_url' => '/uploads/public/hero.mp4', 'image' => '/uploads/public/poster.jpg'],
            ['title' => 'YouTube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ],
    ]);

    assert_contains('data-hero-background-video', $html);
    assert_contains('/uploads/public/hero.mp4', $html);
    // Картинка слайда остаётся заставкой ролика.
    assert_contains('poster="/uploads/public/poster.jpg"', $html);
    assert_contains('data-hero-youtube-background', $html);
    assert_contains('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
    // Скрытый слайд не тянет медиа заранее: ни автозапуска, ни src у iframe.
    assert_not_contains('autoplay preload', $html);
    assert_contains('preload="none"', $html);
    assert_not_contains('<iframe data-hero-youtube-background src=', $html);
});

test('Обложка: первый слайд грузится сразу, остальные — отложенно', function () {
    $html = hero_slider_html([
        'slides' => [
            ['title' => 'Первый', 'image' => '/uploads/public/a.jpg'],
            ['title' => 'Второй', 'image' => '/uploads/public/b.jpg'],
        ],
    ]);

    assert_contains('loading="eager"', $html, 'первый слайд — это первый экран');
    assert_contains('loading="lazy"', $html, 'остальные слайды ждут своей очереди');
});

test('Обложка: битая ссылка на YouTube не подменяет фон слайда', function () {
    $normalized = HeroBlockNormalizer::normalize([
        'slides' => [[
            'title' => 'Слайд',
            'image' => '/uploads/public/a.jpg',
            'youtube_url' => 'https://example.com/watch?v=нет',
        ]],
    ]);

    // Введённое редактором не теряем, но фоном остаётся картинка.
    assert_same('image', $normalized['slides'][0]['media_type']);
    assert_same('https://example.com/watch?v=нет', $normalized['slides'][0]['youtube_url']);

    $video = HeroBlockNormalizer::normalize(['slides' => [
        ['video_url' => '/uploads/public/a.mp4'],
        ['title' => 'Опасный', 'video_url' => 'javascript:alert(1)'],
    ]]);
    assert_same('video', $video['slides'][0]['media_type'], 'слайд из одного ролика — тоже слайд');
    assert_same('', $video['slides'][1]['video_url'], 'небезопасный адрес ролика отбрасывается');
});

test('Фон карусели: скрытый слайд не играет и не грузит YouTube', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');

    assert_contains('.block-hero__slide', $js);
    assert_contains('MutationObserver', $js);
    // Пауза и возврат к началу при уходе слайда.
    assert_contains('video.pause()', $js);
    assert_contains("command('pauseVideo')", $js);
});

test('Обложка: слайд вне окна показа не выводится', function () {
    $html = hero_slider_html([
        'slides' => [
            ['title' => 'Действующий', 'image' => '/uploads/public/a.jpg'],
            ['title' => 'Просроченный', 'image' => '/uploads/public/b.jpg', '_visible_to' => '2020-01-01T00:00'],
            ['title' => 'Будущий', 'image' => '/uploads/public/c.jpg', '_visible_from' => '2999-01-01T00:00'],
        ],
    ]);

    assert_contains('Действующий', $html);
    assert_not_contains('Просроченный', $html);
    assert_not_contains('Будущий', $html);
});

test('Обложка: ссылка со слайда не оборачивает кнопки', function () {
    $html = hero_slider_html([
        'slides' => [[
            'title' => 'Слайд со ссылкой',
            'image' => '/uploads/public/a.jpg',
            'link_url' => '/news',
            'button_text' => 'Кнопка',
            'button_url' => '/o-nas',
        ]],
    ]);

    // Ссылка-подложка лежит отдельным элементом: вложенные ссылки недопустимы.
    assert_contains('class="block-hero__slide-cover" href="/news"', $html);
    assert_contains('class="block-hero__button" href="/o-nas"', $html);
    assert_contains('block-hero__title-link', $html);
});

test('Обложка: небезопасные адреса слайда отбрасываются при сохранении', function () {
    $normalized = HeroBlockNormalizer::normalize([
        'slides' => [
            ['title' => 'Слайд', 'link_url' => 'javascript:alert(1)', 'button_url' => 'javascript:alert(2)', 'image' => '../../etc/passwd'],
            ['title' => '', 'subtitle' => '', 'image' => ''],
        ],
        'autoplay' => 999,
    ]);

    assert_same(1, count($normalized['slides']), 'пустая строка репитера в данные не попадает');
    assert_same('', $normalized['slides'][0]['link_url']);
    assert_same('', $normalized['slides'][0]['button_url']);
    assert_same('', $normalized['slides'][0]['image']);
    // Пауза автопрокрутки ограничена разумным диапазоном.
    assert_same(30, $normalized['autoplay']);
    assert_same(0, HeroBlockNormalizer::normalize(['autoplay' => 0])['autoplay']);
});

test('Обложка: скрипт слайдера подключается только при слайдах', function () {
    $withSlides = BlockRenderer::renderPage([[
        'id' => 921, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('hero'), [
            'slides' => [['title' => 'Раз', 'image' => '/uploads/public/a.jpg'], ['title' => 'Два', 'image' => '/uploads/public/b.jpg']],
        ])),
    ]]);
    assert_true(in_array('slider', $withSlides['assets'], true), 'обложке со слайдами нужен скрипт слайдера');

    $plain = BlockRenderer::renderPage([[
        'id' => 922, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('hero'), ['title' => 'Обычная'])),
    ]]);
    assert_false(in_array('slider', $plain['assets'], true), 'обычной обложке скрипт слайдера ни к чему');
});

test('Обложка: расписание слайда размораживает кэш страницы', function () {
    $future = date('Y-m-d\TH:i', time() + 3600);
    $rendered = BlockRenderer::renderPage([[
        'id' => 923, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('hero'), [
            'slides' => [['title' => 'Баннер', 'image' => '/uploads/public/a.jpg', '_visible_to' => $future]],
        ])),
    ]]);

    // Иначе слайд «до 18:00» остался бы в кэше страницы и после 18:00.
    assert_true(is_int($rendered['expires_at']), 'граница показа слайда должна попасть в expires_at');
});

test('Слайдер: общий скрипт умеет автопрокрутку с паузой', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/blocks/slider.js');

    assert_contains("getAttribute('data-autoplay')", $js);
    // Движение не навязываем: пауза под курсором, при фокусе и в фоне вкладки.
    assert_contains("prefers-reduced-motion: reduce", $js);
    assert_contains("addEventListener('mouseenter', stopAuto)", $js);
    assert_contains("addEventListener('focusin', stopAuto)", $js);
    assert_contains('visibilitychange', $js);
    // Оба слайдера ходят через одну реализацию.
    assert_contains(".block-slider__slide", $js);
    assert_contains(".block-hero__slide", $js);
});
