<?php

declare(strict_types=1);

use App\Core\BlockBackground;
use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\BlockRenderer;

test('Коллаж: размещение хранится ячейками и обрезается по краю полотна', function (): void {
    $data = CollageBlockNormalizer::normalize([
        'columns' => '6',
        'rows' => '4',
        'items' => [
            // Элемент шире остатка строки: обрезаем до края, а не переносим —
            // перенос сдвинул бы соседей и развалил бы всю композицию.
            ['type' => 'photo', 'image' => '/uploads/a.jpg', 'col' => '5', 'col_span' => '6', 'row' => '3', 'row_span' => '5'],
            // Подделанный тип и вылет за левый край.
            ['type' => 'diagonal-magic', 'image' => '/uploads/b.jpg', 'col' => '0', 'row' => '99'],
            // Пустой элемент занимал бы ячейки и ничем их не заполнял.
            ['type' => 'stat', 'value' => '', 'label' => ''],
            ['type' => 'photo', 'image' => ''],
        ],
    ]);

    assert_same(6, $data['columns']);
    assert_same(2, count($data['items']));

    assert_same(5, $data['items'][0]['col']);
    assert_same(2, $data['items'][0]['col_span']);
    assert_same(3, $data['items'][0]['row']);
    assert_same(2, $data['items'][0]['row_span']);

    assert_same('photo', $data['items'][1]['type']);
    assert_same(1, $data['items'][1]['col']);
    assert_same(4, $data['items'][1]['row']);
});

test('Коллаж: узоры берутся из общего набора фонов секции', function (): void {
    assert_same(BlockBackground::PATTERNS, CollageBlockNormalizer::PATTERNS);

    $data = CollageBlockNormalizer::normalize([
        'items' => [['type' => 'pattern', 'pattern' => 'girih']],
    ]);
    // Значение вне набора — подделанная форма, а не «ближайшее допустимое».
    assert_same('dots', $data['items'][0]['pattern']);
});

test('Коллаж: размещение уходит в scoped CSS, а не в инлайн-стиль', function (): void {
    $data = CollageBlockNormalizer::normalize([
        'columns' => '6',
        'rows' => '4',
        'items' => [
            ['type' => 'stat', 'value' => '25K+', 'label' => 'клиентов',
             'col' => '1', 'col_span' => '2', 'row' => '3', 'row_span' => '2', 'bg' => '#1F1F1F'],
            ['type' => 'badge', 'text' => 'Свяжитесь с нами', 'shape' => 'square',
             'col' => '3', 'col_span' => '1', 'row' => '2', 'row_span' => '1'],
        ],
    ]);

    $out = BlockRenderer::render([
        'id' => 77,
        'type' => 'collage',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    assert_contains('--collage-cols:6', $out['css']);
    assert_contains('grid-column:1/span 2', $out['css']);
    assert_contains('grid-row:3/span 2', $out['css']);
    assert_contains('--collage-bg:#1f1f1f', $out['css']);
    // Инлайн-стилей у самих элементов быть не должно. Исключение одно — <img>:
    // точку фокуса печатает переменной сам Media::picture, и своё правило
    // здесь либо проиграло бы ей, либо затёрло бы точку из медиатеки.
    $withoutImages = (string) preg_replace('/<img[^>]*>/', '', $out['html']);
    assert_not_contains('style=', $withoutImages, 'инлайн-стилей в блоке быть не должно');

    // Печать всегда круглая: форма у неё не настройка, а суть — присланное
    // «без скругления» игнорируется.
    assert_contains('collage__item--shape-circle', $out['html']);
    assert_not_contains('collage__item--badge collage__item--shape-square', $out['html']);
    assert_contains('collage__badge', $out['html']);
});

test('Коллаж: в узком месте композиция складывается в столбец', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');

    assert_contains('@media (max-width: 720px)', $css);
    // Колонка конструктора мерит себя сама: ширина экрана про долю колонки
    // ничего не говорит, и композиция 6×4 в половине ряда давала ячейки по
    // полсотни пикселей на любом экране. Медиазапрос остаётся фолбэком.
    assert_contains('@container cms-col (max-width: 560px)', $css);
    // Размещение (grid-column / grid-row) приезжает из scoped CSS блока, вес у
    // него по id. Отменяется оно сменой самого полотна на колонку flex, а не
    // переопределением у каждого элемента: вне grid-контейнера эти свойства не
    // применяются вовсе, и `!important` тут не нужен.
    assert_not_contains('!important;', $css);
    assert_same(2, substr_count($css, 'grid-template-rows: none;'), 'полотно перестаёт быть сеткой в обоих узких контекстах');
    // Диаметр печати — по меньшей стороне ячейки: при aspect-ratio с
    // ограничением по высоте браузер режет только высоту, и круг становится
    // эллипсом.
    assert_contains('min(100cqw, 100cqh)', $css);
    assert_contains('container-type: size', $css);
});
