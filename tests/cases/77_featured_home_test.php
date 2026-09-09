<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Database;
use App\Models\PhotoAlbum;
use App\Models\Project;

// «Показать на главном»: блоки cards_grid/media_gallery с автоисточником
// собирают карточки из отмеченных записей (проекты / фотоальбомы).

test('Project::forHome отдаёт отмеченные «на главной», иначе — откат на последние', function () {
    if (!Database::isConnected()) {
        return;
    }
    $pdo = Database::pdo();
    // Чистая площадка: снимаем все отметки, затем проверяем откат.
    $pdo->exec("UPDATE pages SET is_featured = 0 WHERE entity_type = 'project'");

    $slug = '_fh_' . bin2hex(random_bytes(3));
    $id = Project::create([
        'title' => 'Избранный проект',
        'slug' => $slug,
        'description' => null,
        'cover_image' => '/uploads/public/_fh.jpg',
        'status' => 'published',
        'is_featured' => true,
        'sort_order' => 0,
    ]);

    $featured = Project::forHome(6);
    $ids = array_map(static fn ($r) => (int) $r['id'], $featured);
    assert_true(in_array($id, $ids, true), 'отмеченный проект попадает в forHome');
    assert_true(count($featured) >= 1, 'есть хотя бы один отмеченный');

    // Снимаем отметку — forHome должен вернуть откат (последние опубликованные),
    // блок не пустой.
    $pdo->exec("UPDATE pages SET is_featured = 0 WHERE entity_type = 'project'");
    $fallback = Project::forHome(6);
    assert_true(count($fallback) >= 1, 'откат на последние опубликованные не пуст');

    Project::forceDelete($id);
});

test('cards_grid с источником «projects» рендерит карточки проектов', function () {
    if (!Database::isConnected()) {
        return;
    }
    $slug = '_fh_' . bin2hex(random_bytes(3));
    $id = Project::create([
        'title' => 'Проект для главной',
        'slug' => $slug,
        'description' => null,
        'cover_image' => '/uploads/public/_fh.jpg',
        'status' => 'published',
        'is_featured' => true,
        'sort_order' => 0,
    ]);

    $block = ['id' => 0, 'type' => 'cards_grid', 'data' => json_encode(['variant' => 'image', 'source' => 'projects', 'limit' => 6])];
    $html = BlockRenderer::render($block)['html'];

    assert_true(str_contains($html, 'Проект для главной'), 'заголовок проекта отрисован');
    assert_true(str_contains($html, '/projects/' . $slug), 'ссылка ведёт на страницу проекта');

    Project::forceDelete($id);
});

test('media_gallery с источником «albums» рендерит карточки фотоальбомов', function () {
    if (!Database::isConnected()) {
        return;
    }
    $albumId = PhotoAlbum::create('Альбом для главной', '', '/uploads/public/_fh_cover.jpg', true);
    assert_true($albumId !== null, 'альбом создан');
    PhotoAlbum::update($albumId, 'Альбом для главной', '', '/uploads/public/_fh_cover.jpg', true, true);

    $block = ['id' => 0, 'type' => 'media_gallery', 'data' => json_encode(['source' => 'albums', 'limit' => 8])];
    $html = BlockRenderer::render($block)['html'];

    assert_true(str_contains($html, 'Альбом для главной'), 'заголовок альбома отрисован');
    assert_true(str_contains($html, '/albums/'), 'ссылка ведёт на страницу альбома');

    PhotoAlbum::delete($albumId);
});

test('Ручной источник (manual) по-прежнему использует список items', function () {
    if (!Database::isConnected()) {
        return;
    }
    $block = ['id' => 0, 'type' => 'cards_grid', 'data' => json_encode([
        'variant' => 'image',
        'source' => 'manual',
        'items' => [['image' => '/x.jpg', 'title' => 'Ручная карточка', 'url' => '']],
    ])];
    $html = BlockRenderer::render($block)['html'];

    assert_true(str_contains($html, 'Ручная карточка'), 'ручной список отрисован');
});
