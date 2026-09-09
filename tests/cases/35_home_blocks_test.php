<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Блок partners рендерит логотипы и отбрасывает небезопасные ссылки', function () {
    $out = BlockRenderer::render([
        'id' => 1, 'type' => 'partners', 'custom_css' => '',
        'data' => json_encode(['title' => 'Партнёры', 'items' => [
            ['logo' => '/l1.png', 'name' => 'Alpha', 'url' => 'https://a.example'],
            ['logo' => '/l2.png', 'name' => 'Beta', 'url' => 'javascript:alert(1)'],
        ]]),
    ]);
    assert_contains('block-partners', $out['html']);
    assert_contains('/l1.png', $out['html']);
    assert_contains('href="https://a.example"', $out['html']);
    assert_not_contains('javascript:', $out['html']);
});

test('CTA с медиа рендерит фон, текст и кнопку; чистит опасный URL', function () {
    $ok = BlockRenderer::render([
        'id' => 2, 'type' => 'cta', 'custom_css' => '',
        'data' => json_encode(['variant' => 'media-dark', 'title' => 'Приём', 'text' => 'Онлайн', 'image' => '/bg.jpg', 'button_text' => 'Подать', 'button_url' => '/catalog/documenty']),
    ]);
    assert_contains('block-banner--image', $ok['html']);
    assert_contains("url('/bg.jpg')", $ok['css']);
    assert_not_contains(' style="', $ok['html']);
    assert_contains('href="/catalog/documenty"', $ok['html']);

    $bad = BlockRenderer::render([
        'id' => 3, 'type' => 'cta', 'custom_css' => '',
        'data' => json_encode(['variant' => 'media-light', 'title' => 'X', 'button_text' => 'Go', 'button_url' => 'javascript:alert(1)']),
    ]);
    assert_not_contains('javascript:', $bad['html']);
    assert_not_contains('block-banner__button', $bad['html']); // кнопка не выводится без валидного URL
});

test('BlockRenderer::defaultsFor знает новые типы блоков', function () {
    assert_true(isset(BlockRenderer::defaultsFor('partners')['items']));
    assert_true(isset(BlockRenderer::defaultsFor('cta')['image']));
    assert_true(isset(BlockRenderer::defaultsFor('news_latest')['limit']));
});
