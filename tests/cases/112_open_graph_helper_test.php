<?php

declare(strict_types=1);

use App\Core\OpenGraphHelper;
use App\Models\Setting;

test('OpenGraph helper формирует карточку для явного внешнего изображения', function (): void {
    $og = OpenGraphHelper::render(
        'https://example.com',
        'Заголовок новости',
        'Описание новости',
        'https://example.com/news/1',
        'ru',
        'https://cdn.example.org/cover.jpg',
        'article',
        '2026-07-25 10:00:00'
    );

    assert_contains('property="og:title"', $og);
    assert_contains('content="https://cdn.example.org/cover.jpg"', $og);
    assert_contains('name="twitter:card" content="summary_large_image"', $og);
    assert_contains('property="article:published_time"', $og);
    assert_not_contains('property="og:image:width"', $og, 'Внешнему файлу нельзя приписывать неизвестный размер');
    assert_not_contains('/>', $og, 'HTML5 meta-теги не используют XHTML-закрытие');
});

test('несуществующий локальный fallback не попадает в OpenGraph', function (): void {
    Setting::set('default_og_image', '');
    Setting::set('og_default_image', '');
    Setting::set('logo_url', '');

    $og = OpenGraphHelper::render(
        'https://example.com',
        'Главная страница',
        'Описание главной',
        'https://example.com/',
        'ru',
        '/assets/img/hero-bg.jpg'
    );

    assert_not_contains('property="og:image"', $og);
    assert_not_contains('name="twitter:image"', $og);
    assert_contains('name="twitter:card" content="summary"', $og);
    assert_not_contains('hero-bg.jpg', $og);
});

test('существующее локальное изображение получает фактические размеры', function (): void {
    $dir = APP_ROOT . '/public/uploads/public';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir . '/open-graph-test.png';
    // Валидный PNG 1×1.
    file_put_contents(
        $file,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
    );

    try {
        $og = OpenGraphHelper::render(
            'https://example.com',
            'Локальная обложка',
            '',
            'https://example.com/page',
            'ru',
            '/uploads/public/open-graph-test.png'
        );

        assert_contains('property="og:image" content="https://example.com/uploads/public/open-graph-test.png"', $og);
        assert_contains('property="og:image:width" content="1"', $og);
        assert_contains('property="og:image:height" content="1"', $og);
        assert_contains('property="og:image:secure_url"', $og);
    } finally {
        @unlink($file);
    }
});
