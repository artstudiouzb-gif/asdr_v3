<?php

declare(strict_types=1);

use App\Core\BlockData\CardsGridBlockNormalizer;
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

test('Карточки: колонки задаются вручную, ноль оставляет автоподбор', function () {
    $items = [];
    for ($i = 1; $i <= 6; $i++) {
        $items[] = ['title' => 'Пункт ' . $i, 'text' => 'Описание'];
    }

    $manual = midtier_block('cards_grid', ['variant' => 'icon', 'columns' => 3, 'items' => $items], 801);
    assert_contains('--cards-cols:3', $manual['css']);

    // Ноль — прежнее поведение «Преимуществ»: шесть карточек ложатся
    // четвёркой, и в последнем ряду не остаётся одинокой карточки.
    $auto = midtier_block('cards_grid', ['variant' => 'icon', 'columns' => 0, 'items' => $items], 802);
    assert_contains('--cards-cols:4', $auto['css']);
});

test('Карточки: карточка со ссылкой кликается целиком, опасный адрес отбрасывается', function () {
    $block = midtier_block('cards_grid', [
        'variant' => 'icon',
        'all_text' => 'Все направления',
        'all_url' => '/directions',
        'items' => [
            ['title' => 'С ссылкой', 'text' => 'Описание', 'url' => '/directions/one'],
            ['title' => 'Без ссылки', 'text' => 'Описание'],
        ],
    ], 803);

    // Карточка со ссылкой — сам элемент <a>, отдельной подписи «Подробнее» у
    // неё нет: она повторяла бы заголовок и съедала высоту.
    assert_contains('<a class="feature-card', $block['html']);
    assert_contains('href="/directions/one"', $block['html']);
    assert_contains('href="/directions"', $block['html'], 'ссылка «Все» выводится общей шапкой');

    $unsafe = midtier_block('cards_grid', [
        'variant' => 'icon',
        'items' => [['title' => 'Плохая', 'text' => 'Описание', 'url' => 'javascript:alert(1)']],
    ], 804);
    assert_not_contains('javascript:', $unsafe['html']);
    assert_not_contains('<a class="feature-card', $unsafe['html']);
});

test('Карточки: иконка в строке с заголовком ставит их на одну линию', function () {
    // У «Преимуществ» это был отдельный вариант блока, хотя меняет он ровно
    // положение иконки — и «слева» у «Карточек» кладёт её иначе: там заголовок
    // уходит во вторую строку, под номер.
    $items = [['icon_svg' => 'star', 'title' => 'Первое', 'text' => 'Описание.']];

    $inline = midtier_block('cards_grid', ['variant' => 'icon', 'icon_position' => 'inline', 'items' => $items], 805);
    assert_contains('block-cards--icon-pos-inline', $inline['html']);
    // Заголовок внутри верхней строки, рядом с иконкой и номером.
    assert_true(
        (bool) preg_match('#feature-card__top.*?feature-card__title.*?feature-card__num.*?</div>#s', $inline['html']),
        'заголовок должен стоять в одной строке с иконкой и номером'
    );

    // При иконке сверху заголовок остаётся под верхней строкой.
    $grid = midtier_block('cards_grid', ['variant' => 'icon', 'items' => $items], 806);
    assert_not_contains('block-cards--icon-pos-inline', $grid['html']);
    assert_true(
        (bool) preg_match('#feature-card__num.*?</div>.*?feature-card__title#s', $grid['html']),
        'в варианте «карточки» заголовок идёт после верхней строки'
    );
});

test('Карточки: нормализатор чистит колонки, ссылку блока и ссылку карточки', function () {
    $data = CardsGridBlockNormalizer::normalize([
        'variant' => 'icon',
        'columns' => '77',
        'all_url' => 'javascript:alert(1)',
        'items' => [['title' => 'Пункт', 'text' => 'Текст', 'url' => 'javascript:alert(1)']],
    ]);

    // Число вне списка колонок — подделанная форма: возвращаемся к умолчанию,
    // а не к ближайшему допустимому.
    assert_same(5, $data['columns']);
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

test('«Преимущества» и «Карточки» — один тип: варианты стали настройками', function (): void {
    // Два блока печатали одну и ту же карточку (`.feature-card` с тем же
    // нутром) из одних и тех же полей — иконка, заголовок, текст, ссылка.
    // Разошлись они настройками, и правка одного до второго не доходила:
    // подложку иконки у «Карточек» давно сменили с синеватой на тон акцента, а
    // у «Преимуществ» она такой и осталась (замерено вычисленными стилями).
    assert_false(\App\Core\BlockTypeRegistry::has('advantages'), 'advantages больше не тип блока');
    assert_false(is_file(APP_ROOT . '/templates/blocks/advantages.php'));
    assert_false(is_file(APP_ROOT . '/app/Core/BlockData/AdvantagesBlockNormalizer.php'));

    $fields = \App\Core\BlockData\BlockFieldSchema::fields('cards_grid');
    // Всё, что было своим у «Преимуществ», стало настройкой приёмника.
    assert_true(isset($fields['description']), 'описание раздела');
    assert_true(isset($fields['numbering']), 'нумерация карточек');
    assert_true(isset($fields['variant']->options['band']), 'компактная полоса');
    assert_true(isset($fields['icon_position']->options['inline']), 'иконка в строке с заголовком');
    assert_true(isset($fields['columns']->options[0]), 'автоподбор колонок');
});

test('Нумерация карточек — настоящая настройка, а не вариант без последствий', function (): void {
    // Номер печатался во всех вариантах: правила, которое его прячет, в
    // публичном CSS не было вовсе — то есть выбор «с нумерацией» / «без»
    // не менял ничего. Настройка обязана менять вывод.
    $items = [['icon_svg' => 'star', 'title' => 'Первое', 'text' => 'Описание']];
    $on = midtier_block('cards_grid', ['variant' => 'icon', 'numbering' => true, 'items' => $items], 810);
    $off = midtier_block('cards_grid', ['variant' => 'icon', 'numbering' => false, 'items' => $items], 811);

    assert_contains('feature-card__num', $on['html']);
    assert_not_contains('feature-card__num', $off['html']);
    assert_contains('block-cards--numbered', $on['html']);
});

test('Блок прежнего типа читается как карточки и до миграции', function (): void {
    $legacy = static fn (array $data): array => \App\Core\BlockTypeRegistry::canonicalData('advantages', $data);

    assert_same('cards_grid', \App\Core\BlockTypeRegistry::canonicalType('advantages'));
    // Нумерация включается всем: номер печатался всегда, и выключенная
    // настройка поменяла бы вид уже собранных страниц.
    assert_true($legacy(['variant' => 'grid'])['numbering']);
    assert_same('icon', $legacy(['variant' => 'indexed'])['variant']);
    // «В одну строку» — это положение иконки, и у «Карточек» оно своё:
    // «слева» кладёт иконку слева от всего текста, а здесь она стоит на одной
    // линии с заголовком.
    assert_same('inline', $legacy(['variant' => 'inline'])['icon_position']);
    assert_same('band', $legacy(['variant' => 'band'])['variant']);
});

test('Ссылка карточки проверяется на выводе, а не только в форме', function (): void {
    // Данные приезжают не одной дорогой: кроме формы есть загруженный файл
    // шаблона страницы и записи, сохранённые до появления проверки.
    $out = midtier_block('cards_grid', [
        'variant' => 'icon',
        'items' => [['title' => 'Плохая', 'text' => 'Описание', 'url' => 'javascript:alert(1)']],
    ], 812);

    assert_not_contains('javascript:', $out['html']);
});
