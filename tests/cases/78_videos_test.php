<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Database;
use App\Models\Video;

// Раздел «Видео» + источник «videos» у media_gallery.

test('Video: создание, forHome отдаёт отмеченные, media_gallery источник videos', function () {
    if (!Database::isConnected()) {
        return;
    }
    Database::pdo()->exec('DELETE FROM videos');

    $id = Video::create('Тестовое видео');
    assert_true($id !== null && $id > 0, 'видео создано, id получен (не 0)');
    Video::update($id, 'Тестовое видео', 'опис', '/uploads/public/_v.jpg',
        'https://youtube.com/watch?v=abc', '02:35', true, true, 0);

    $home = Video::forHome(8);
    assert_true(count($home) === 1 && (int) $home[0]['id'] === $id, 'forHome отдаёт отмеченное видео');

    $block = ['id' => 0, 'type' => 'media_gallery', 'data' => json_encode(['source' => 'videos', 'limit' => 8])];
    $html = BlockRenderer::render($block)['html'];
    assert_true(str_contains($html, 'Тестовое видео'), 'заголовок видео отрисован в блоке «Медиа»');
    assert_true(str_contains($html, 'mediacard--video'), 'карточка помечена как видео');

    Video::delete($id);
});
