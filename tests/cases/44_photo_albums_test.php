<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\PhotoAlbum;

/** Таблицы альбомов (идемпотентно — миграция с IF NOT EXISTS). */
function ensure_albums_tables(): void
{
    ensure_test_db();
    Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_photo_albums.sql'));
}

test('PhotoAlbum: создание с авто-slug и разрешением коллизий (БД)', function () {
    ensure_albums_tables();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM photo_albums');

    $id1 = PhotoAlbum::create('Итоги года');
    $id2 = PhotoAlbum::create('Итоги года');
    assert_true($id1 !== null && $id2 !== null);
    assert_same('itogi-goda', (string) PhotoAlbum::findById($id1)['slug']);
    assert_same('itogi-goda-2', (string) PhotoAlbum::findById($id2)['slug']);
    assert_true(PhotoAlbum::create('   ') === null, 'пустое название — отказ');

    $pdo->exec('DELETE FROM photo_albums');
});

test('PhotoAlbum: фото, обложка-фолбэк, публикация, каскадное удаление (БД)', function () {
    ensure_albums_tables();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM photo_albums');

    $id = PhotoAlbum::create('Галерея');
    assert_true(PhotoAlbum::addImage($id, '/uploads/public/a.jpg', 'Первое') !== null);
    assert_true(PhotoAlbum::addImage($id, '/uploads/public/b.jpg') !== null);
    assert_true(PhotoAlbum::addImage(999999, '/x.jpg') === null, 'чужой альбом — отказ');
    assert_true(PhotoAlbum::addImage($id, '  ') === null, 'пустой URL — отказ');

    $images = PhotoAlbum::images($id);
    assert_same(2, count($images));
    assert_same('Первое', (string) $images[0]['caption']);

    // Обложка: без cover_url берётся первое фото; с cover_url — он сам.
    $album = PhotoAlbum::findById($id);
    assert_same('/uploads/public/a.jpg', PhotoAlbum::coverFor($album));
    PhotoAlbum::update($id, 'Галерея', '', '/uploads/public/cover.jpg', true);
    assert_same('/uploads/public/cover.jpg', PhotoAlbum::coverFor(PhotoAlbum::findById($id)));

    // Снятие с публикации прячет с сайта.
    PhotoAlbum::update($id, 'Галерея', '', '', false);
    $slug = (string) PhotoAlbum::findById($id)['slug'];
    assert_true(PhotoAlbum::findPublishedBySlug($slug) === null);
    assert_same(0, count(PhotoAlbum::all(true)));
    assert_same(1, count(PhotoAlbum::all()));

    // Удаление альбома каскадно чистит фото.
    PhotoAlbum::delete($id);
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM photo_album_images')->fetchColumn());

    $pdo->exec('DELETE FROM photo_albums');
});

test('PhotoAlbum::deleteImage убирает одно фото; счётчик в all() (БД)', function () {
    ensure_albums_tables();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM photo_albums');

    $id = PhotoAlbum::create('Счётчик');
    $img1 = PhotoAlbum::addImage($id, '/1.jpg');
    PhotoAlbum::addImage($id, '/2.jpg');

    $list = PhotoAlbum::all();
    assert_same(2, (int) $list[0]['images_count']);

    PhotoAlbum::deleteImage((int) $img1);
    assert_same(1, count(PhotoAlbum::images($id)));

    $pdo->exec('DELETE FROM photo_albums');
});
