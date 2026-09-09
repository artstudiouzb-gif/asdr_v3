<?php

declare(strict_types=1);

use App\Core\BlockPager;
use App\Core\BlockRenderer;
use App\Core\Database;
use App\Models\PhotoAlbum;
use App\Models\Video;

/*
 * Своя полоса страниц у каждой вкладки блока «Медиа».
 *
 * У смешанного источника два списка разной длины, а полоса была одна и
 * считалась по длинному: на вкладке «Фото» стояли страницы видео, переход по
 * ним не менял ни одной карточки, а номера вели на страницы, которых у фото
 * нет. Номер страницы принадлежит вкладке, поэтому в адресе вместе с ним едет
 * её имя (`?mtab=photo&mpage=2`), а вторая вкладка остаётся на своём начале —
 * иначе переключение открывало бы её пустой серединой.
 */

test('BlockPager: вкладка в адресе и разбивка с явным номером', function () {
    $saved = $_GET;
    $savedUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/media';

    $_GET = [];
    assert_same('video', BlockPager::currentTab(['video', 'photo']), 'без параметра — первая вкладка');
    assert_false(BlockPager::tabRequested(), 'вкладка не запрошена');

    $_GET[BlockPager::TAB_PARAM] = 'photo';
    assert_same('photo', BlockPager::currentTab(['video', 'photo']));
    assert_true(BlockPager::tabRequested(), 'вкладка названа в адресе');

    // Подделанный адрес не должен открывать раздел, которого у блока нет.
    assert_same('video', BlockPager::currentTab(['video']), 'недоступная вкладка заменяется первой');

    $_GET[BlockPager::TAB_PARAM] = 'подделка';
    assert_same('video', BlockPager::currentTab(['video', 'photo']), 'мусор в параметре не проходит');

    $_GET[BlockPager::TAB_PARAM] = ['photo'];
    assert_same('video', BlockPager::currentTab(['video', 'photo']), 'массив вместо имени не ломает разбор');

    // Адрес: имя вкладки едет и на первую страницу, иначе переход «Фото → 1»
    // возвращал бы читателя на «Видео».
    $_GET = [];
    assert_same('/media?mtab=photo&mpage=2#block-7', BlockPager::url(2, 7, 'photo'));
    assert_same('/media?mtab=photo#block-7', BlockPager::url(1, 7, 'photo'));
    assert_same('/media?mpage=2#block-7', BlockPager::url(2, 7), 'без вкладок адрес прежний');

    // Неактивная вкладка листается со своего начала, а не с чужого номера.
    $_GET[BlockPager::PARAM] = '3';
    $active = BlockPager::slice(10, 2);
    assert_same(3, $active['page']);
    assert_same(4, $active['offset']);
    $idle = BlockPager::slice(10, 2, 1);
    assert_same(1, $idle['page'], 'вторая вкладка остаётся на первой странице');
    assert_same(0, $idle['offset']);

    $_GET = $saved;
    if ($savedUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $savedUri;
    }
});

test('Блок «Медиа»: вкладки листаются раздельно (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM videos');
    $pdo->exec('DELETE FROM photo_albums');

    $videoIds = [];
    foreach (['Ролик A', 'Ролик B', 'Ролик C', 'Ролик D'] as $i => $title) {
        $id = (int) Video::create($title);
        // Порядок задаём явно: страницы должны быть предсказуемыми, а не
        // зависеть от совпадения дат создания.
        Video::update($id, $title, '', '/uploads/public/v.jpg', 'https://youtu.be/tabpager0' . $i, '', true, false, $i);
        $videoIds[] = $id;
    }

    // Списки намеренно разной длины: ровно из-за этого общая полоса и врала —
    // у видео две страницы, у фото две, но записей в них разное число.
    // Альбомы отдаются от свежего к старому, поэтому «Альбом A» окажется
    // последним.
    $albumIds = [];
    foreach (['Альбом A', 'Альбом B', 'Альбом C'] as $title) {
        $albumIds[] = (int) PhotoAlbum::create($title, '', '/uploads/public/a.jpg', true);
    }

    // «Сколько показывать» — размер страницы, и схема полей держит минимум в
    // две карточки: меньшее значение всё равно поднимется до двух.
    $block = [
        'id' => 51,
        'type' => 'media_gallery',
        'data' => json_encode(['source' => 'media', 'limit' => 2, 'paginate' => true]),
    ];

    $saved = $_GET;
    $savedUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/media';

    // Вкладка «Видео» открыта по умолчанию: у видео четыре страницы, у фото —
    // две, и это две разные полосы, а не одна общая.
    $_GET = [];
    $html = BlockRenderer::render($block)['html'];
    assert_contains('data-media-active="video"', $html);
    assert_contains('data-media-pager="video"', $html);
    assert_contains('data-media-pager="photo"', $html);
    assert_contains('mtab=video', $html, 'ссылки полосы видео помнят свою вкладку');
    assert_contains('mtab=photo', $html, 'у фото своя полоса со своей вкладкой');
    // Полоса фото скрыта до переключения — иначе на экране две полосы сразу.
    assert_true(
        (bool) preg_match('#data-media-pager="photo"[^>]*\shidden#', $html),
        'полоса неактивной вкладки скрыта'
    );
    // Обе вкладки показывают своё начало.
    assert_contains('Ролик A', $html);
    assert_contains('Ролик B', $html);
    assert_true(!str_contains($html, 'Ролик C'), 'на странице своя порция видео');
    assert_contains('Альбом C', $html, 'у фото видна первая страница её собственного списка');
    assert_true(!str_contains($html, 'Альбом A'), 'последний альбом — уже на второй странице фото');

    // Переход по полосе «Фото»: листается фото, вкладка остаётся открытой,
    // видео возвращается к своему началу.
    $_GET = [BlockPager::TAB_PARAM => 'photo', BlockPager::PARAM => '2'];
    $html = BlockRenderer::render($block)['html'];
    assert_contains('data-media-active="photo"', $html);
    assert_contains('Альбом A', $html, 'вторая страница фото');
    assert_true(!str_contains($html, 'Альбом C'), 'вторая страница не повторяет первую');
    assert_contains('Ролик A', $html, 'видео осталось на своём начале');
    assert_true(!str_contains($html, 'Ролик C'), 'номер страницы принадлежит только открытой вкладке');
    assert_true(
        (bool) preg_match('#data-media-pager="video"[^>]*\shidden#', $html),
        'теперь скрыта полоса видео'
    );

    // Номер, которого у фото нет, отдаёт её последнюю страницу, а не пустоту
    // и не страницу видео.
    $_GET = [BlockPager::TAB_PARAM => 'photo', BlockPager::PARAM => '4'];
    $html = BlockRenderer::render($block)['html'];
    assert_contains('Альбом A', $html);

    $_GET = $saved;
    if ($savedUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $savedUri;
    }

    foreach ($videoIds as $id) {
        Video::delete($id);
    }
    foreach ($albumIds as $id) {
        PhotoAlbum::delete($id);
    }
});

test('Открытую вкладку выбирает сервер, а не скрипт', function () {
    $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/frontend.js');
    assert_contains("gallery.getAttribute('data-media-active')", $js);
    assert_not_contains("apply('video');", $js, 'скрипт больше не сбрасывает вкладку на «Видео»');
    assert_contains("p.getAttribute('data-media-pager') !== kind", $js, 'полосы переключаются вместе с карточками');

    // Вкладка меняет содержимое ответа уже на первой странице, поэтому такой
    // запрос не берётся из общего кэша страницы.
    $pageBlocks = (string) file_get_contents(__DIR__ . '/../../app/Core/PageBlocks.php');
    assert_contains('BlockPager::tabRequested()', $pageBlocks);
});
