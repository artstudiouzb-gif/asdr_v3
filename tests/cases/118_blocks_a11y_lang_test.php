<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockSamples;

test('Заголовок первого уровня на странице ровно один', function () {
    // Экранный диктор по h1 понимает, о чём страница: второй h1 ломает эту
    // опору. Обложка и профиль персоны претендуют на него — h1 получает
    // первый, остальные переходят на h2.
    $blocks = [
        ['id' => 1, 'type' => 'hero', 'custom_css' => '', 'data' => json_encode(['title' => 'Обложка'])],
        ['id' => 2, 'type' => 'person_profile', 'custom_css' => '', 'data' => json_encode(['name' => 'Фамилия Имя'])],
        ['id' => 3, 'type' => 'cta', 'custom_css' => '', 'data' => json_encode(['variant' => 'media-light', 'title' => 'Баннер'])],
    ];
    $html = BlockRenderer::renderPage($blocks)['html'];
    assert_same(1, substr_count($html, '<h1'), 'h1 должен быть один');
    assert_contains('<h1 class="block-hero__title"', $html);
    assert_contains('<h2 class="profile__name"', $html);

    // Порядок решает: первым идёт профиль — h1 достаётся ему.
    $reordered = BlockRenderer::renderPage(array_reverse($blocks))['html'];
    assert_same(1, substr_count($reordered, '<h1'));
    assert_contains('<h1 class="profile__name"', $reordered);

    // Баннер — рекламная врезка, заголовком страницы не бывает никогда.
    $bannerOnly = BlockRenderer::renderPage([$blocks[2]])['html'];
    assert_same(0, substr_count($bannerOnly, '<h1'));
    assert_contains('<h2 class="block-banner__title"', $bannerOnly);
});

test('Счётчик h1 не протекает между страницами', function () {
    $hero = [['id' => 1, 'type' => 'hero', 'custom_css' => '', 'data' => json_encode(['title' => 'Обложка'])]];
    assert_same(1, substr_count(BlockRenderer::renderPage($hero)['html'], '<h1'));
    assert_same(1, substr_count(BlockRenderer::renderPage($hero)['html'], '<h1'), 'второй рендер тоже должен дать h1');
});

test('Прокручиваемые полосы доступны с клавиатуры и подписаны', function () {
    $testimonials = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/blocks/testimonials.php');
    assert_contains('tabindex="0"', $testimonials, 'до полосы отзывов нельзя добраться с клавиатуры');
    assert_contains('role="group"', $testimonials);
    assert_contains('aria-label', $testimonials);

    $cards = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/blocks/cards_grid.php');
    assert_contains('tabindex="0"', $cards);

    // Подписи полос переводятся: UZ-версия не должна показывать русский текст.
    $uz = require dirname(__DIR__, 2) . '/app/Core/lang/uz.php';
    assert_true(isset($uz['Отзывы — прокрутка вбок']), 'нет перевода подписи отзывов');
    assert_true(isset($uz['Карточки — прокрутка вбок']), 'нет перевода подписи карточек');
    assert_true(isset($uz['Этапы — прокрутка вбок']), 'нет перевода подписи этапов');
    assert_true(isset($uz['Партнёры — прокрутка вбок']), 'нет перевода подписи партнёров');
});

test('Образцы: ссылки ведут в раздел на языке блока', function () {
    // UZ-блок со ссылкой на русскую версию уводит посетителя из его языка.
    $ru = BlockSamples::for('cta', 'ru');
    $uz = BlockSamples::for('cta', 'uz');
    assert_same('/news', $ru['button_url']);
    assert_same('/uz/news', $uz['button_url']);

    // Ни в одном образце не должно остаться захардкоженного адреса.
    foreach (BlockSamples::all('uz') as $type => $sample) {
        $json = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        assert_false(
            str_contains((string) $json, '"/news"'),
            "{$type}: ссылка образца не учитывает язык блока"
        );
    }

    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/BlockController.php');
    assert_contains('BlockSamples::for($type, $lang)', $src, 'контроллер не передаёт язык блока');
});
