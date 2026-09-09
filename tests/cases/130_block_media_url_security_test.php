<?php

declare(strict_types=1);

use App\Controllers\Admin\BlockController;
use App\Core\BlockRenderer;
use App\Core\Media;

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function collect_media_gallery_data(array $post): array
{
    $previousPost = $_POST;
    try {
        $_POST = $post;
        $method = new ReflectionMethod(BlockController::class, 'collectData');

        /** @var array<string,mixed> $data */
        $data = $method->invoke(new BlockController(), 'media_gallery', 'ru');

        return $data;
    } finally {
        $_POST = $previousPost;
    }
}

test('Медиа-галерея: при сохранении отбрасывает опасные схемы медиа-URL', function () {
    $data = collect_media_gallery_data([
        'title_field' => 'Галерея',
        'items' => [
            ['image' => 'javascript:alert(1)', 'title' => 'JS', 'kind' => 'photo'],
            ['image' => 'data:image/svg+xml,<svg></svg>', 'title' => 'Data', 'kind' => 'photo'],
            ['image' => 'mailto:editor@example.com', 'title' => 'Mail', 'kind' => 'photo'],
            ['image' => '/uploads/public/local.jpg', 'title' => 'Local', 'kind' => 'photo'],
            ['image' => 'https://cdn.example.com/remote.jpg', 'title' => 'Remote', 'kind' => 'photo'],
        ],
    ]);

    assert_same('', $data['items'][0]['image']);
    assert_same('', $data['items'][1]['image']);
    assert_same('', $data['items'][2]['image']);
    assert_same('/uploads/public/local.jpg', $data['items'][3]['image']);
    assert_same('https://cdn.example.com/remote.jpg', $data['items'][4]['image']);
});

test('Медиа-галерея: опасные JSON-данные не попадают в публичную разметку', function () {
    $rendered = BlockRenderer::render([
        'id' => 1300,
        'type' => 'media_gallery',
        'custom_css' => '',
        'data' => json_encode([
            'title' => 'Галерея',
            'items' => [
                ['image' => 'javascript:alert(1)', 'title' => 'JS', 'kind' => 'photo'],
                ['image' => '/uploads/public/safe.jpg', 'title' => 'Safe', 'kind' => 'photo'],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);

    assert_not_contains('javascript:', $rendered['html']);
    assert_contains('href="/uploads/public/safe.jpg"', $rendered['html']);
    assert_contains('src="/uploads/public/safe.jpg"', $rendered['html']);
});

test('Media: центральный рендерер не выводит URL опасных схем', function () {
    assert_same('', Media::picture('javascript:alert(1)', 'Bad'));
    assert_same('', Media::picture('data:image/svg+xml,<svg></svg>', 'Bad'));
    assert_same('', Media::preloadLink('mailto:editor@example.com'));
});
