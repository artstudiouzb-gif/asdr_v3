<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;

test('Новости колонками: число колонок и лимит сохраняются независимо', function () {
    foreach ([1, 2, 3, 4] as $columns) {
        $saved = BlockFieldSchema::normalize('news_feature', [
            'variant' => 'columns', 'columns' => (string) $columns, 'limit' => '9',
        ], 'ru');
        $loaded = BlockFieldSchema::apply('news_feature', $saved);
        assert_same('columns', $loaded['variant']);
        assert_same($columns, $loaded['columns']);
        assert_same(9, $loaded['limit']);
    }
    $invalid = BlockFieldSchema::normalize('news_feature', [
        'variant' => 'columns', 'columns' => '99', 'limit' => '999',
    ], 'ru');
    assert_same(3, $invalid['columns']);
    assert_same(12, $invalid['limit']);
    assert_same('cards', BlockFieldSchema::apply('news_feature', [])['variant']);
    assert_same('mosaic', BlockFieldSchema::apply('news_feature', ['variant' => 'mosaic'])['variant']);
});

test('Новости колонками: неполный ряд, длинный заголовок и отсутствие фото', function () {
    $data = BlockFieldSchema::apply('news_feature', ['variant' => 'columns', 'columns' => 3]);
    $data['news'] = [];
    for ($i = 0; $i < 5; $i++) {
        $data['news'][] = [
            'title' => 'Новость <script>alert(1)</script> ' . str_repeat('длинный заголовок ', 15),
            'url' => '/news/item-' . $i,
            'cover' => '', 'published_at' => '', 'category' => 'Аналитика & исследования',
        ];
    }
    ob_start();
    require APP_ROOT . '/templates/blocks/news_feature.php';
    $html = (string) ob_get_clean();
    assert_contains('news-columns--3', $html);
    assert_same(5, substr_count($html, 'class="news-column"'));
    assert_same(5, substr_count($html, 'class="news-column__empty"'));
    assert_contains('&lt;script&gt;', $html);
    assert_not_contains('<script>', $html);
    assert_contains(str_repeat('длинный заголовок ', 15), $html);
    assert_contains('Аналитика &amp; исследования', $html);
});

test('Новости колонками: пустая лента не создаёт пустые карточки', function () {
    $data = BlockFieldSchema::apply('news_feature', ['variant' => 'columns']);
    $data['news'] = [];
    ob_start();
    require APP_ROOT . '/templates/blocks/news_feature.php';
    $html = (string) ob_get_clean();
    assert_contains('block-newsfeat__empty', $html);
    assert_not_contains('class="news-column"', $html);
});
