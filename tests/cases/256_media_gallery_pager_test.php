<?php

declare(strict_types=1);

use App\Core\BlockPager;
use App\Core\BlockRenderer;
use App\Core\Cache;
use App\Core\Database;
use App\Core\PageBlocks;
use App\Models\Block;
use App\Models\Video;

// Постраничный вывод блока «Медиа». Без него блок оставался витриной: сколько
// ни импортируй роликов с канала, читатель видел первые записи и не мог дойти
// до остальных — отдельного публичного раздела «Видео» на сайте нет.

test('BlockPager: номер страницы, разбивка и безопасный адрес', function () {
    $saved = $_GET;
    $savedUri = $_SERVER['REQUEST_URI'] ?? null;

    $_GET = [];
    assert_same(1, BlockPager::current(), 'без параметра — первая страница');

    $_GET[BlockPager::PARAM] = '3';
    assert_same(3, BlockPager::current());

    $_GET[BlockPager::PARAM] = 'абв';
    assert_same(1, BlockPager::current(), 'мусор в параметре — первая страница');

    $_GET[BlockPager::PARAM] = '-5';
    assert_same(1, BlockPager::current(), 'отрицательный номер не уводит в минус');

    // Номер участвует в решении о кэше страницы: обходчик с «?mpage=999999»
    // не должен превращаться в бесконечную работу.
    $_GET[BlockPager::PARAM] = '999999';
    assert_same(BlockPager::MAX_PAGE, BlockPager::current(), 'номер ограничен сверху');

    $_GET[BlockPager::PARAM] = ['1'];
    assert_same(1, BlockPager::current(), 'массив вместо номера не ломает разбор');

    // Разбивка: 5 записей по 2 — три страницы, вторая начинается с третьей.
    $_GET[BlockPager::PARAM] = '2';
    $slice = BlockPager::slice(5, 2);
    assert_same(2, $slice['page']);
    assert_same(3, $slice['pages']);
    assert_same(2, $slice['offset']);

    // Запрос за пределами списка возвращает последнюю страницу, а не пустоту.
    $_GET[BlockPager::PARAM] = '99';
    $slice = BlockPager::slice(5, 2);
    assert_same(3, $slice['page']);
    assert_same(4, $slice['offset']);

    $slice = BlockPager::slice(0, 8);
    assert_same(1, $slice['pages'], 'пустой список — одна страница');

    // Адрес: свой путь, номер и якорь блока — переход не выбрасывает читателя
    // в начало длинной страницы.
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/media?utm_source=tg';
    assert_same('/media?mpage=2#block-7', BlockPager::url(2, 7));
    assert_same('/media#block-7', BlockPager::url(1, 7), 'первая страница — адрес без параметра');

    // Путь берётся из запроса, поэтому чужой домен в нём не должен превращать
    // полосу страниц в ссылку наружу: разбор адреса отбрасывает хост, и остаётся
    // собственный путь.
    $_SERVER['REQUEST_URI'] = '//evil.example/media';
    assert_same('/media?mpage=2#block-7', BlockPager::url(2, 7), 'чужой домен в пути не проходит');

    // Обратный слэш браузеры трактуют как разделитель пути — такой адрес
    // отбрасываем целиком.
    $_SERVER['REQUEST_URI'] = '/\\evil.example/media';
    assert_same('/?mpage=2#block-7', BlockPager::url(2, 7), 'подозрительный путь заменяется корнем');

    $_GET = $saved;
    if ($savedUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $savedUri;
    }
});

test('Блок «Медиа»: полоса страниц листает весь список видео (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM videos');

    $ids = [];
    foreach (['Видео A', 'Видео B', 'Видео C', 'Видео D', 'Видео E'] as $i => $title) {
        $id = (int) Video::create($title);
        // Порядок задаём явно: список листается, и «страница 2» должна быть
        // предсказуемой, а не зависеть от совпадения дат создания.
        Video::update($id, $title, '', '/uploads/public/v.jpg', 'https://youtu.be/abcdefghij' . $i, '', true, false, $i);
        $ids[] = $id;
    }

    $block = static fn (array $extra = []): array => [
        'id' => 42,
        'type' => 'media_gallery',
        'data' => json_encode(array_merge(['source' => 'videos', 'limit' => 2, 'paginate' => true], $extra)),
    ];

    $saved = $_GET;
    $savedUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/media';

    $_GET = [];
    $html = BlockRenderer::render($block())['html'];
    assert_contains('Видео A', $html);
    assert_contains('Видео B', $html);
    assert_true(!str_contains($html, 'Видео C'), 'на первой странице только два ролика');
    assert_contains('listing-pager', $html, 'полоса страниц выведена');
    assert_contains('mpage=2', $html, 'есть ссылка на вторую страницу');
    assert_contains('#block-42', $html, 'ссылка ведёт к самому блоку');

    $_GET[BlockPager::PARAM] = '2';
    $html = BlockRenderer::render($block())['html'];
    assert_contains('Видео C', $html);
    assert_contains('Видео D', $html);
    assert_true(!str_contains($html, 'Видео A'), 'вторая страница не повторяет первую');

    // Последняя страница неполная — это нормально, и запрос дальше неё
    // возвращает её же, а не пустой блок.
    $_GET[BlockPager::PARAM] = '77';
    $html = BlockRenderer::render($block())['html'];
    assert_contains('Видео E', $html);

    // Без галочки поведение прежнее: витрина «на главной» и никакой полосы.
    $_GET = [];
    $html = BlockRenderer::render($block(['paginate' => false]))['html'];
    assert_true(!str_contains($html, 'listing-pager'), 'без постраничного вывода полосы нет');
    assert_contains('Видео A', $html);

    $_GET = $saved;
    if ($savedUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $savedUri;
    }

    foreach ($ids as $id) {
        Video::delete($id);
    }
});

test('Кэш страницы не подменяется второй страницей блока (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM videos');

    $ids = [];
    // Четыре ролика и страница по два: «сколько показывать» — поле формы с
    // минимумом в две карточки, и схема держит этот минимум и на выводе.
    foreach (['Кэш A', 'Кэш B', 'Кэш C', 'Кэш D'] as $i => $title) {
        $id = (int) Video::create($title);
        Video::update($id, $title, '', '/uploads/public/v.jpg', 'https://youtu.be/cachedvide' . $i, '', true, false, $i);
        $ids[] = $id;
    }

    $slug = 'media-pager-' . bin2hex(random_bytes(3));
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('Медиа', '{$slug}', 'published', NOW())");
    $pageId = (int) $pdo->lastInsertId();
    Block::create($pageId, 'ru', 'media_gallery', 'Медиатека', [
        'source' => 'videos',
        'limit' => 2,
        'paginate' => true,
    ], '');

    $saved = $_GET;
    $savedUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/' . $slug;
    Cache::forgetPrefix('page:');

    $_GET = [];
    $first = PageBlocks::compile($pageId, 'ru')['html'];
    assert_contains('Кэш A', $first);

    $_GET[BlockPager::PARAM] = '2';
    $second = PageBlocks::compile($pageId, 'ru')['html'];
    assert_contains('Кэш C', $second, 'вторая страница блока собирается заново');

    // Главное: в общем кэше страницы осталась первая страница. Иначе один
    // читатель, пролиставший галерею, менял бы содержимое страницы всем.
    $cached = Cache::get('page:' . $pageId . ':ru');
    assert_true(is_array($cached), 'первая страница попала в кэш');
    assert_contains('Кэш A', (string) $cached['html']);
    assert_true(!str_contains((string) $cached['html'], 'Кэш C'), 'вторая страница в общий кэш не попала');

    $_GET = $saved;
    if ($savedUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $savedUri;
    }
    Cache::forgetPrefix('page:');
    $pdo->prepare('DELETE FROM blocks WHERE page_id = ?')->execute([$pageId]);
    $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$pageId]);
    foreach ($ids as $id) {
        Video::delete($id);
    }
});
