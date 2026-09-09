<?php

declare(strict_types=1);

use App\Core\BlockData\CountersBlockNormalizer;

test('Counters normalizer: сохраняет числа, подписи, цвета, ключ Tabler и свою иконку', function (): void {
    $data = CountersBlockNormalizer::normalize([
        'title_field' => ' Наши результаты ',
        'card_bg' => '#AABBCC',
        'text_color' => '#112233',
        'icon_size' => '36',
        'icon_bg' => 'off',
        'icon_position' => 'right',
        'text_align' => 'center',
        'items' => [
            [
                'value' => ' 1 250+ ',
                'suffix' => ' + ',
                'label' => ' реализованных проектов ',
                'icon_svg' => 'chart-bar',
                'icon_image' => '/uploads/custom-icon.svg',
            ],
            ['value' => '', 'label' => '', 'suffix' => '%', 'icon_svg' => 'percentage'],
            'unexpected',
        ],
    ]);

    assert_same('Наши результаты', $data['title']);
    assert_same('#aabbcc', $data['card_bg']);
    assert_same('#112233', $data['text_color']);
    assert_same(36, $data['icon_size']);
    assert_same('off', $data['icon_bg']);
    assert_same('right', $data['icon_position']);
    assert_same('center', $data['text_align']);
    assert_same(1, count($data['items']));
    // Значение сохраняется как набрано: «1 250+» — это разряды и знак, а не
    // мусор вокруг числа. Прежде нормализатор сводил его к 1250 и терял и
    // пробел разряда, и плюс.
    assert_same('1 250+', $data['items'][0]['value']);
    assert_same('+', $data['items'][0]['suffix']);
    assert_same('реализованных проектов', $data['items'][0]['label']);
    assert_same('chart-bar', $data['items'][0]['icon_svg']);
    assert_same('/uploads/custom-icon.svg', $data['items'][0]['icon_image']);
});

test('Counters normalizer: цвета по умолчанию и повреждённые поля не вызывают предупреждений', function (): void {
    $data = CountersBlockNormalizer::normalize([
        'title_field' => [],
        'card_bg' => ['#ffffff'],
        'text_color' => '#ffffff',
        'text_color_off' => '1',
        'items' => [
            [
                'value' => [],
                'label' => ' Сотрудников ',
                'suffix' => [],
                'icon_svg' => [],
            ],
        ],
    ]);

    assert_same('', $data['title']);
    assert_same('', $data['card_bg']);
    assert_same('', $data['text_color']);
    assert_same(28, $data['icon_size']);
    assert_same('on', $data['icon_bg']);
    assert_same('left', $data['icon_position']);
    assert_same('left', $data['text_align']);
    assert_same(1, count($data['items']));
    assert_same('', $data['items'][0]['value']);
    assert_same('', $data['items'][0]['suffix']);
    assert_same('Сотрудников', $data['items'][0]['label']);
    assert_same('', $data['items'][0]['icon_svg']);
});

test('Counters normalizer: значение не сводится к цифрам', function (): void {
    // Раньше из значения вырезались все нецифры: «-12.5» превращалось в 125,
    // «24/7» — в 247, а «№1» — в 1. Показатель бывает именно таким, поэтому
    // строка сохраняется целиком, а анимацию включает уже шаблон.
    $items = CountersBlockNormalizer::normalize([
        'items' => [
            ['value' => '-12.5', 'label' => 'процента'],
            ['value' => 'нет числа', 'label' => 'подпись'],
            ['value' => '24/7', 'label' => 'приём обращений'],
        ],
    ])['items'];

    assert_same('-12.5', $items[0]['value']);
    assert_same('нет числа', $items[1]['value']);
    assert_same('24/7', $items[2]['value']);

    // Длина ограничена: значение стоит в одной строке с подписью.
    $long = CountersBlockNormalizer::normalize([
        'items' => [['value' => str_repeat('9', 40), 'label' => 'много']],
    ])['items'];
    assert_same(24, mb_strlen($long[0]['value']));
});

test('Counters: приставка, примечание, ссылка и вид блока', function (): void {
    $data = CountersBlockNormalizer::normalize([
        'variant' => 'cards',
        'value_size' => 'large',
        'items' => [[
            'prefix' => ' более ',
            'value' => '34',
            'label' => ' статьи ',
            'note' => ' данные ведомства ',
            'link' => '/o-nas',
        ]],
    ]);

    assert_same('cards', $data['variant']);
    assert_same('large', $data['value_size']);
    assert_same('более', $data['items'][0]['prefix']);
    // Примечание проходит типографику наравне с подписью (неразрывные пробелы
    // после коротких предлогов), поэтому здесь сверяется строка без них.
    assert_same('данные ведомства', $data['items'][0]['note']);
    assert_same('/o-nas', $data['items'][0]['link']);

    // Чужие значения вида и размера откатываются к умолчанию, а не уходят в класс.
    $bad = CountersBlockNormalizer::normalize(['variant' => 'evil"', 'value_size' => '<b>']);
    assert_same('row', $bad['variant']);
    assert_same('normal', $bad['value_size']);

    // Опасная ссылка не сохраняется.
    $js = CountersBlockNormalizer::normalize([
        'items' => [['value' => '1', 'label' => 'x', 'link' => 'javascript:alert(1)']],
    ])['items'];
    assert_same('', $js[0]['link']);
});

test('Шаблон показателей: отсчёт только у чистого числа, ссылка — тегом a', function (): void {
    $render = static function (array $items, array $extra = []): string {
        $rendered = \App\Core\BlockRenderer::render([
            'id' => 61,
            'type' => 'counters',
            'data' => json_encode(['items' => $items] + $extra, JSON_UNESCAPED_UNICODE),
        ]);

        return (string) $rendered['html'];
    };

    $html = $render([
        ['value' => '1200', 'label' => 'обращений'],
        ['value' => '24/7', 'label' => 'приём'],
    ]);
    assert_contains('data-counter-target="1200"', $html);
    // «24/7» анимировать нечем: parseInt дал бы 24 и подменил значение.
    assert_not_contains('data-counter-target="24/7"', $html);
    assert_contains('>24/7<', $html);

    // Старые блоки хранят значение целым — они обязаны рисоваться по-прежнему.
    assert_contains('data-counter-target="90"', $render([['value' => 90, 'label' => 'процентов']]));

    // Ссылка меняет тег, её отсутствие оставляет div.
    $linked = $render([['value' => '5', 'label' => 'центров', 'link' => '/projects']]);
    assert_contains('<a class="counter counter--link" href="/projects"', $linked);
    assert_contains('</a>', $linked);
    assert_not_contains('counter--link', $render([['value' => '5', 'label' => 'центров']]));

    // Своя иконка (картинка) выводится вместо Tabler
    $withImg = $render([['value' => '10', 'label' => 'наград', 'icon_svg' => 'trophy', 'icon_image' => '/uploads/trophy.png']]);
    assert_contains('src="/uploads/trophy.png"', $withImg);
    assert_contains('class="counter__icon-img"', $withImg);
    assert_not_contains('<svg', $withImg);

    // Вид и размер приходят классами на блок, без инлайн-стилей.
    $cards = $render([['value' => '5', 'label' => 'центров']], ['variant' => 'cards', 'value_size' => 'large']);
    assert_contains('block-counters--cards', $cards);
    assert_contains('block-counters--size-large', $cards);

    // theme_css() склеивает тему с вынесенными частями: стили счётчиков живут
    // в blocks/counters.css и подключаются только на страницах с блоком.
    $theme = theme_css();
    assert_contains('.block-counters--cards .counter', $theme);
    assert_contains('.block-counters--size-large .counter__value', $theme);
    assert_contains('.counter__note', $theme);
    assert_contains('.counter__prefix', $theme);
    assert_contains('.counter__icon-img', $theme);
});

test('Контроллер делегирует счётчики CountersBlockNormalizer', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');

    assert_contains('CountersBlockNormalizer::normalize($_POST, $locale)', $controller);
    assert_not_contains('// Число хранится как целое', $controller);
});

test('Форма счётчиков показывает настройки иконки, выравнивания и поле своей иконки', function (): void {
    // Поля счётчиков рисует схема, поэтому смотрим на редактор целиком.
    $form = block_editor_markup();

    foreach (['name="icon_size"', 'name="icon_bg"', 'name="icon_position"', 'name="text_align"'] as $field) {
        assert_contains($field, $form);
    }
    assert_contains('Сверху по центру', $form);
    assert_contains('Без подложки', $form);
    assert_contains('icon_image', $form);
    assert_contains('Своя иконка (SVG / PNG / WebP)', $form);
});

test('Counters: подложка блока — настройка, а положения иконки имеют правила', function (): void {
    $data = CountersBlockNormalizer::normalize([
        'panel' => 'none',
        'items' => [['value' => '128', 'label' => 'проектов']],
    ]);
    assert_same('none', $data['panel']);
    // Значение вне набора — подделанная форма, а не «ближайшее допустимое».
    assert_same('card', CountersBlockNormalizer::normalize(['panel' => 'glass'])['panel']);

    // Класс обязан доехать до разметки: без него настройка ничего не меняет.
    $html = \App\Core\BlockRenderer::render([
        'id' => 91,
        'type' => 'counters',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];
    assert_contains('block-counters--panel-none', $html);

    // …и обязан иметь правило. Тот же договор — у каждого положения иконки и
    // каждого выравнивания: пункт списка без правила выглядит сломанным.
    $css = theme_css();
    assert_contains('.block-counters--panel-none', $css);
    foreach (['left', 'right', 'top', 'center'] as $pos) {
        assert_contains('.block-counters--icon-pos-' . $pos . ' ', $css);
    }
    foreach (['left', 'center', 'right'] as $align) {
        assert_contains('.block-counters--text-align-' . $align . ' ', $css);
    }
});
