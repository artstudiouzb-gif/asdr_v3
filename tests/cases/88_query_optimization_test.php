<?php

declare(strict_types=1);

use App\Models\News;

test('Предзагруженная обложка галереи не требует отдельного SQL-запроса', function () {
    assert_same('/uploads/public/news/cover.webp', News::getCoverImage([
        'id' => 42,
        'image' => '',
        'video_url' => '',
        'first_gallery_image' => '/uploads/public/news/cover.webp',
    ]));
});

test('Списки новостей загружают переводы одним пакетным запросом', function () {
    $news = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    $translations = (string) file_get_contents(APP_ROOT . '/app/Models/NewsTranslation.php');

    assert_contains('self::localizePublicRows(', $news);
    assert_contains('NewsTranslation::forNewsIds(', $news);
    assert_contains('WHERE news_id IN ({$placeholders}) AND lang = ?', $translations);
});

test('Списки новостей и меню повторно используются внутри одного запроса', function () {
    $news = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    $menu = (string) file_get_contents(APP_ROOT . '/app/Models/MenuItem.php');

    assert_contains('$publishedRequestCache', $news);
    assert_contains('$activeRequestCache', $menu);
});

test('Страница не предзагружает все локальные шрифты одновременно', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    assert_contains('$fontPreloads', $header);
    assert_contains('array_keys($fontPreloads)', $header);
});

test('Сервер безопасно отдаёт статические бандлы без конфликтов сжатия и MultiViews', function () {
    $htaccess = (string) file_get_contents(APP_ROOT . '/public/.htaccess');
    $rootHtaccess = (string) file_get_contents(APP_ROOT . '/.htaccess');

    // Запрет MultiViews предотвращает ошибочное сопоставление файлов веб-сервером
    assert_contains('Options -Indexes -MultiViews', $htaccess);
    assert_contains('Options -Indexes -MultiViews', $rootHtaccess);

    // Безопасность и MIME-типы
    assert_contains('X-Content-Type-Options "nosniff"', $htaccess);
    assert_contains('AddType text/css .css', $htaccess);
    assert_contains('AddType application/javascript .js', $htaccess);

    // Долговечное кэширование для версионированных ассетов
    assert_contains('Cache-Control "public, max-age=31536000, immutable"', $htaccess);

    // Динамическое сжатие сервером
    assert_contains('BROTLI_COMPRESS', $htaccess);
    assert_contains('DEFLATE', $htaccess);

    // Файлы основных бандлов обязаны существовать в сборке
    foreach (['public/assets/css/public.min.css', 'public/assets/js/public.min.js'] as $bundle) {
        assert_true(
            is_file(APP_ROOT . '/' . $bundle),
            "сборка кладёт {$bundle}"
        );
    }
});

test('Сервер сжимает текст, а hero загружает медиа по приоритету первого экрана', function () {
    $htaccess = (string) file_get_contents(APP_ROOT . '/public/.htaccess');
    assert_contains('BROTLI_COMPRESS', $htaccess);
    assert_contains('DEFLATE', $htaccess);

    $render = static function (array $data): string {
        $block = \App\Core\BlockRenderer::render([
            'id' => 880,
            'type' => 'hero',
            'custom_css' => '',
            'data' => json_encode(array_merge(\App\Core\BlockRenderer::defaultsFor('hero'), $data)),
        ]);

        return (string) $block['html'];
    };

    // Проверяем готовую разметку, а не исходник шаблона: подстроки в PHP-коде
    // ничего не говорят о том, что реально уехало браузеру.
    $image = $render(['bg_type' => 'image', 'image' => '/uploads/public/hero.jpg']);
    assert_contains('loading="eager"', $image);
    assert_contains('fetchpriority="high"', $image);

    // Фон hero — это первый экран, откладывать его нельзя. Смотрим теги <img>:
    // YouTube-подложка подключается по data-src уже после загрузки, и
    // `loading="lazy"` на её <iframe> — ожидаемое поведение, а не отложенная
    // обложка.
    $video = $render(['bg_type' => 'video', 'video_url' => '/uploads/public/hero.mp4', 'image' => '/uploads/public/hero.jpg']);
    assert_contains('preload="metadata"', $video);
    foreach ([$image, $video, $render(['bg_type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'image' => '/uploads/public/hero.jpg'])] as $html) {
        preg_match_all('/<img\b[^>]*>/is', $html, $images);
        foreach ($images[0] as $tag) {
            assert_not_contains('loading="lazy"', $tag, 'обложка hero отложена до прокрутки');
        }
    }
});
