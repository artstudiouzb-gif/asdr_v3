<?php

declare(strict_types=1);

use App\Core\InstagramFeed;

/*
 * Лента Instagram: посты забирает воркер, страница показывает сохранённую
 * копию. Здесь проверяется то, что не требует сети: разбор ответа Graph API,
 * выбор API по виду токена и разметка блока и виджета.
 */

function instagram_sample_posts(): array
{
    return [
        ['id' => '1', 'type' => 'IMAGE', 'caption' => 'Fransiya taraqqiyot agentligi bilan uchrashuv', 'permalink' => 'https://www.instagram.com/p/AAA/', 'image' => '/uploads/public/instagram-1.jpg', 'timestamp' => ''],
        ['id' => '2', 'type' => 'VIDEO', 'caption' => '', 'permalink' => 'https://www.instagram.com/reel/BBB/', 'image' => '/uploads/public/instagram-2.jpg', 'timestamp' => ''],
    ];
}

test('Instagram: разбор ответа Graph API берёт кадр ролика из обложки и отсеивает чужие ссылки', function () {
    $json = json_encode(['data' => [
        ['id' => '17900001', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/a.jpg', 'permalink' => 'https://www.instagram.com/p/AAA/', 'caption' => 'Matn', 'timestamp' => '2026-09-21T10:00:00+0000'],
        ['id' => '17900002', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example/v.mp4', 'thumbnail_url' => 'https://cdn.example/v.jpg', 'permalink' => 'https://www.instagram.com/reel/BBB/'],
        ['id' => '17900003', 'media_type' => 'CAROUSEL_ALBUM', 'media_url' => 'https://cdn.example/c.jpg', 'permalink' => 'https://www.instagram.com/p/CCC/'],
        // Ссылка поста уходит в href: адрес не на Instagram не пропускается.
        ['id' => '17900004', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/d.jpg', 'permalink' => 'javascript:alert(1)'],
        // Без кадра показать пост нечем.
        ['id' => '17900005', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/EEE/'],
    ]]);

    $items = InstagramFeed::parseMedia((string) $json);
    assert_same(['17900001', '17900002', '17900003'], array_column($items, 'id'));
    assert_same('https://cdn.example/v.jpg', $items[1]['remote'], 'у ролика кадр — обложка, а не файл видео');
    assert_same('CAROUSEL_ALBUM', $items[2]['type']);
    assert_same([], InstagramFeed::parseMedia('{"error":{"message":"x"}}'));
});

test('Instagram: API выбирается по виду токена', function () {
    assert_true(InstagramFeed::isInstagramLogin('IGAAexample'), 'токен Instagram Login читает graph.instagram.com');
    assert_false(InstagramFeed::isInstagramLogin('EAAexample'), 'токен Страницы Facebook читает graph.facebook.com по IG User ID');
    assert_true(\App\Models\Setting::isSecret('instagram_feed_token'), 'токен ленты шифруется как остальные секреты');
});

test('Instagram: блок выводит кадры ссылками в Instagram с понятным именем', function () {
    $html = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => [
            'title' => 'Мы в Instagram', 'limit' => 8, 'layout' => 'grid', 'columns' => 4,
            'captions' => true, 'all_text' => 'Подписаться', 'all_url' => '',
            'posts' => instagram_sample_posts(), 'profile_url' => 'https://www.instagram.com/asdr_uz/',
        ],
    ]);

    assert_contains('href="https://www.instagram.com/p/AAA/"', $html);
    assert_contains('rel="noopener noreferrer"', $html);
    assert_contains('aria-label="Fransiya taraqqiyot agentligi bilan uchrashuv', $html, 'имя ссылки — начало подписи поста');
    assert_contains('href="https://www.instagram.com/asdr_uz/"', $html, 'ссылка на профиль из настроек ленты');
    assert_contains('ig-feed__kind', $html, 'ролик отмечен значком');
    assert_contains('ig-feed__caption', $html);
});

test('Instagram: пустая лента не выводит ни блока, ни виджета', function () {
    $block = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => ['title' => 'Instagram', 'layout' => 'grid', 'columns' => 4, 'captions' => false, 'all_text' => '', 'posts' => []],
    ]);
    assert_same('', trim($block));

    $widget = render_view('templates/widgets/instagram_feed.php', ['data' => ['posts' => []], 'lang' => 'uz']);
    assert_same('', trim($widget));

    $filled = render_view('templates/widgets/instagram_feed.php', [
        'data' => ['posts' => instagram_sample_posts(), 'profile_url' => 'https://www.instagram.com/asdr_uz/'],
        'lang' => 'ru',
    ]);
    assert_contains('widget-instagram', $filled);
    assert_not_contains('ig-feed__caption', $filled, 'в сайдбаре подписей нет — только кадры');
});
