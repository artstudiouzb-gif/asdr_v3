<?php

declare(strict_types=1);

use App\Core\AssetCollector;
use App\Core\WidgetRenderer;
use App\Models\Widget;

/*
 * Виджет «Фотокарусель»: набор снимков без подписей и ссылок, порядок
 * случайный. Разметка и стили общие с блоком «Слайдер» — поведение у них
 * одно, и второй набор правил разъехался бы с первым.
 *
 * Перемешивает браузер, а не сервер: страницы кэшируются общим ключом, и
 * порядок, выбранный при сборке, застыл бы для всех до сброса кэша.
 */

/** @param array<string, mixed> $data */
function widget_slider_html(array $data): string
{
    AssetCollector::reset();

    return WidgetRenderer::render([
        'id' => 77,
        'type' => 'photo_slider',
        'title' => '',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ], 'ru');
}

test('Фотокарусель: тип зарегистрирован и у него есть шаблон', function () {
    assert_true(in_array('photo_slider', Widget::TYPES, true), 'тип виджета не зарегистрирован');
    assert_true(isset(Widget::TYPE_LABELS['photo_slider']), 'у типа нет подписи в админке');
    assert_true(
        is_file(APP_ROOT . '/templates/widgets/photo_slider.php'),
        'без шаблона WidgetRenderer молча отдаёт пустую строку'
    );
});

test('Фотокарусель: кадры, разметка слайдера и признак перемешивания', function () {
    $html = widget_slider_html([
        'slides' => [
            ['image' => '/uploads/public/a.jpg', 'alt' => 'Первая цель'],
            ['image' => '/uploads/public/b.jpg', 'alt' => ''],
        ],
        'shuffle' => true,
        'ratio' => '4-3',
        'autoplay' => 5,
    ]);

    assert_contains('block-slider--ratio-4-3', $html, 'соотношение сторон не доехало до разметки');
    assert_contains('data-slider-shuffle', $html, 'признак случайного порядка потерян');
    assert_contains('data-autoplay="5"', $html, 'автопрокрутка не доехала до разметки');
    assert_contains('/uploads/public/a.jpg', $html);
    assert_contains('/uploads/public/b.jpg', $html);
    assert_same(2, substr_count($html, 'block-slider__slide'), 'кадров в карусели должно быть два');
    assert_contains('alt="Первая цель"', $html, 'описание для диктора не доехало');

    // Текста у виджета нет по определению: ни подписей, ни ссылок со слайда.
    assert_true(strpos($html, 'block-slider__caption') === false, 'у фотокарусели не бывает подписей');
    assert_true(strpos($html, 'block-slider__link') === false, 'у фотокарусели не бывает ссылок со слайда');

    // Скрипт слайдера просит сам шаблон: виджет сайдбара собирается на каждый
    // запрос и в список ассетов блоков не попадает.
    assert_contains('/assets/js/blocks/slider', AssetCollector::renderScripts(), 'скрипт карусели не подключён');
});

test('Фотокарусель: выключенное перемешивание и один кадр не зовут навигацию', function () {
    $one = widget_slider_html([
        'slides' => [['image' => '/uploads/public/a.jpg', 'alt' => 'Цель']],
        'shuffle' => false,
    ]);
    assert_true(strpos($one, 'data-slider-shuffle') === false, 'перемешивание должно выключаться');
    assert_true(strpos($one, 'block-slider__dots') === false, 'у одного кадра точек и стрелок не бывает');

    // Умолчания важнее пустого JSON: у старой записи без ключей шаблон обязан
    // собраться, а не упасть на несуществующем ключе.
    $bare = widget_slider_html([]);
    assert_contains('widget-empty', $bare, 'пустая карусель должна честно говорить, что фотографий нет');
    assert_true(strpos($bare, 'block-slider') === false, 'пустой карусели в разметке быть не должно');
});

test('Фотокарусель: опасный адрес кадра не доезжает до разметки', function () {
    $html = widget_slider_html([
        'slides' => [
            ['image' => 'javascript:alert(1)', 'alt' => ''],
            ['image' => '/uploads/public/ok.jpg', 'alt' => ''],
        ],
    ]);

    assert_true(strpos($html, 'javascript:') === false, 'адрес с исполняемой схемой попал в разметку');
    assert_contains('/uploads/public/ok.jpg', $html, 'годный кадр должен остаться');
    assert_same(1, substr_count($html, 'block-slider__slide'), 'в карусели должен остаться один кадр');
});

test('Фотокарусель: скрипт перемешивает в браузере, а не на сервере', function () {
    // Порядок, выбранный на сервере, ушёл бы в кэш страницы и стал бы общим
    // для всех посетителей — «случайным» он был бы ровно один раз.
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/WidgetRenderer.php');
    $template = (string) file_get_contents(APP_ROOT . '/templates/widgets/photo_slider.php');
    foreach ([$renderer, $template] as $php) {
        assert_true(strpos($php, 'shuffle(') === false, 'порядок кадров перемешивается на сервере');
        assert_true(strpos($php, 'array_rand') === false, 'порядок кадров перемешивается на сервере');
    }

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/slider.js');
    assert_contains('data-slider-shuffle', $js, 'скрипт не знает про случайный порядок');
    assert_contains('Math.random()', $js, 'перемешивания в скрипте нет');

    // Виджет внутри блока приезжает из кэша, и шаблон тогда не выполняется —
    // ключ скрипта обязан быть виден по готовому HTML.
    $blockRenderer = (string) file_get_contents(APP_ROOT . '/app/Core/BlockRenderer.php');
    assert_contains('widget--photo_slider', $blockRenderer, 'у виджета в блоке не подключится скрипт из кэша');
});
