<?php

declare(strict_types=1);

use App\Core\InputBag;
use App\Models\PhotoAlbum;

/*
 * Фото альбома правятся одной формой (FUNC-4): подписи, авторы, порядок и
 * удаление сразу у всех снимков. Прежде у снимка была только кнопка
 * «Убрать», а подпись задавалась один раз при добавлении.
 */

test('InputBag::map: id → строка, мусорные ключи и значения выпадают', function (): void {
    $bag = new InputBag(['caption' => ['12' => '  Подпись ', 'x' => 'нет', '0' => 'ноль', '7' => ['вложено'], 8 => 'Ещё']]);
    assert_same([12 => 'Подпись', 8 => 'Ещё'], $bag->map('caption'));
    assert_same([], (new InputBag(['caption' => 'строка']))->map('caption'));
    assert_same([3 => 'abc'], (new InputBag(['c' => ['3' => 'abcdef']]))->map('c', 3));
});

test('Фото альбома: подписи, порядок и удаление одной формой, чужие фото не трогаются (БД)', function (): void {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    $album = (int) PhotoAlbum::create('Альбом для формы');
    $other = (int) PhotoAlbum::create('Чужой альбом');
    $a = (int) PhotoAlbum::addImage($album, '/uploads/public/a.jpg', 'Было');
    $b = (int) PhotoAlbum::addImage($album, '/uploads/public/b.jpg');
    $foreign = (int) PhotoAlbum::addImage($other, '/uploads/public/c.jpg', 'Чужое');

    $changed = PhotoAlbum::saveImages(
        $album,
        [$a => 'Стало', $b => 'Второе', $foreign => 'Взлом'],
        [$a => 'Пресс-служба'],
        [$a => '5', $b => '1'],
        [$foreign]
    );
    assert_same(2, $changed, 'изменились только свои фото');

    $images = PhotoAlbum::images($album);
    assert_same([$b, $a], array_map(static fn (array $i): int => (int) $i['id'], $images), 'порядок по числу');
    assert_same('Стало', $images[1]['caption']);
    assert_same('Пресс-служба', $images[1]['credit']);
    assert_same('Чужое', PhotoAlbum::images($other)[0]['caption'], 'фото чужого альбома не изменилось и не удалено');

    PhotoAlbum::saveImages($album, [], [], [], [$a]);
    assert_same([$b], array_map(static fn (array $i): int => (int) $i['id'], PhotoAlbum::images($album)));
});
