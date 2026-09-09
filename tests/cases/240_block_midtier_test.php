<?php

declare(strict_types=1);

use App\Core\BlockData\AdvantagesBlockNormalizer;
use App\Core\BlockRenderer;

// Настройки у блоков, которые умели только показать список в жёсткой сетке:
// преимущества, медиагалерея, этапы и профиль руководителя.

/**
 * @param array<string, mixed> $data
 * @return array{html: string, css: string}
 */
function midtier_block(string $type, array $data, int $id = 800): array
{
    $rendered = BlockRenderer::render([
        'id' => $id,
        'type' => $type,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) $rendered['css']];
}

test('Преимущества: колонки задаются вручную, ноль оставляет автоподбор', function () {
    $items = [];
    for ($i = 1; $i <= 6; $i++) {
        $items[] = ['title' => 'Пункт ' . $i, 'text' => 'Описание'];
    }

    $manual = midtier_block('advantages', ['variant' => 'grid', 'columns' => 3, 'items' => $items], 801);
    assert_contains('--grid-track:3', $manual['css']);

    // Ноль — прежнее поведение: шесть карточек ложатся четвёркой без остатка
    // в последнем ряду не остаётся одинокой карточки.
    $auto = midtier_block('advantages', ['variant' => 'grid', 'columns' => 0, 'items' => $items], 802);
    assert_not_contains('--grid-track:3', $auto['css']);
});

test('Преимущества: карточка со ссылкой кликается целиком, опасный адрес отбрасывается', function () {
    $block = midtier_block('advantages', [
        'variant' => 'grid',
        'all_text' => 'Все направления',
        'all_url' => '/directions',
        'items' => [
            ['title' => 'С ссылкой', 'text' => 'Описание', 'url' => '/directions/one'],
            ['title' => 'Без ссылки', 'text' => 'Описание'],
        ],
    ], 803);

    assert_contains('block-advantages__item--link', $block['html']);
    assert_contains('href="/directions/one"', $block['html']);
    assert_contains('href="/directions"', $block['html'], 'ссылка «Все» выводится общей шапкой');
    // Легаси-классы шапки сохранены: на них висят правила темы.
    assert_contains('block-advantages__head', $block['html']);

    $unsafe = midtier_block('advantages', [
        'variant' => 'grid',
        'items' => [['title' => 'Плохая', 'text' => 'Описание', 'url' => 'javascript:alert(1)']],
    ], 804);
    assert_not_contains('javascript:', $unsafe['html']);
    assert_not_contains('block-advantages__item--link', $unsafe['html']);
});

test('Преимущества: вариант «в одну строку» ставит заголовок рядом с иконкой', function () {
    $items = [['icon_svg' => 'star', 'title' => 'Первое', 'text' => 'Описание.']];

    $inline = midtier_block('advantages', ['variant' => 'inline', 'items' => $items], 805);
    assert_contains('block-advantages--inline', $inline['html']);
    // Заголовок внутри верхней строки, рядом с иконкой и номером.
    assert_true(
        (bool) preg_match('#feature-card__top.*?feature-card__title.*?feature-card__num.*?</div>#s', $inline['html']),
        'заголовок должен стоять в одной строке с иконкой и номером'
    );

    // В обычном варианте заголовок остаётся под верхней строкой.
    $grid = midtier_block('advantages', ['variant' => 'grid', 'items' => $items], 806);
    assert_not_contains('block-advantages--inline', $grid['html']);
    assert_true(
        (bool) preg_match('#feature-card__num.*?</div>.*?feature-card__title#s', $grid['html']),
        'в варианте «карточки» заголовок идёт после верхней строки'
    );
});

test('Преимущества: нормализатор чистит колонки, ссылку блока и ссылку карточки', function () {
    $data = AdvantagesBlockNormalizer::normalize([
        'variant' => 'grid',
        'columns' => '77',
        'all_url' => 'javascript:alert(1)',
        'items' => [['title' => 'Пункт', 'text' => 'Текст', 'url' => 'javascript:alert(1)']],
    ]);

    // Число вне списка колонок — подделанная форма: возвращаемся к умолчанию
    // «автоматически по числу карточек», а не к ближайшему допустимому.
    assert_same(0, $data['columns']);
    assert_same('', $data['all_url']);
    assert_same('', $data['items'][0]['url']);
});

test('Медиагалерея: число плиток в ряду и пропорция настраиваются', function () {
    $items = [
        ['kind' => 'video', 'image' => '/uploads/public/v.jpg', 'title' => 'Видео', 'url' => 'https://example.org'],
        ['kind' => 'photo', 'image' => '/uploads/public/p.jpg', 'title' => 'Фото'],
    ];

    $block = midtier_block('media_gallery', [
        'title' => 'Медиа',
        'description' => 'Съёмки и репортажи',
        'columns' => 3,
        'ratio' => '4-3',
        'items' => $items,
    ], 810);

    assert_contains('--media-desktop-cols:3', $block['css']);
    assert_contains('mediagallery-grid--ratio-4-3', $block['html']);
    assert_contains('Съёмки и репортажи', $block['html']);
    // Вкладки «Видео/Фото» остались в шапке рядом с заголовком.
    assert_contains('section-head block-mediagallery__head', $block['html']);
    assert_contains('data-media-tab="photo"', $block['html']);
});

test('Этапы: колонки, автопрокрутка и ссылка с этапа', function () {
    $items = [
        ['year' => '2024', 'title' => 'Первый', 'status' => 'done', 'url' => '/stages/one'],
        ['year' => '2025', 'title' => 'Второй', 'status' => 'active'],
        ['year' => '2026', 'title' => 'Третий', 'status' => 'planned', 'url' => 'javascript:alert(1)'],
    ];

    $block = midtier_block('stages', ['columns' => 3, 'autoplay' => 8, 'items' => $items], 820);

    assert_contains('--stages-count:3', $block['css']);
    assert_contains('data-carousel-autoplay="8"', $block['html']);
    assert_contains('href="/stages/one"', $block['html']);
    assert_contains('stage__body--link', $block['html']);
    assert_not_contains('javascript:', $block['html']);

    // Ноль — колонок по числу этапов, как было раньше.
    $auto = midtier_block('stages', ['items' => $items], 821);
    assert_contains('--stages-count:3', $auto['css']);
    assert_not_contains('data-carousel-autoplay', $auto['html']);
});

test('Профиль руководителя: сторона фото, соцсети и вторая кнопка', function () {
    $block = midtier_block('person_profile', [
        'name' => 'Иванов И. И.',
        'position' => 'Директор',
        'photo_side' => 'right',
        'telegram' => 'https://t.me/example',
        'instagram' => 'javascript:alert(1)',
        'button_text' => 'Обратиться',
        'button_url' => '/contacts',
        'button2_text' => 'Биография',
        'button2_url' => '/about',
    ], 830);

    assert_contains('block-profile--photo-right', $block['html']);
    assert_contains('href="https://t.me/example"', $block['html']);
    assert_not_contains('javascript:', $block['html'], 'небезопасный адрес соцсети отбрасывается');
    assert_contains('profile__button--secondary', $block['html']);
    assert_contains('href="/about"', $block['html']);

    // По умолчанию фото слева, ряда соцсетей нет.
    $plain = midtier_block('person_profile', ['name' => 'Петров П. П.'], 831);
    assert_contains('block-profile--photo-left', $plain['html']);
    assert_not_contains('profile__socials', $plain['html']);
});
