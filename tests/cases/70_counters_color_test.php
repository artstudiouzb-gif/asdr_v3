<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Блок счётчиков: настраиваемый цвет карточки и текста через scoped CSS.

test('Counters: цвет карточки и текста отдаются переменными', function () {
    $rendered = BlockRenderer::render(['id' => 1, 'type' => 'counters', 'custom_css' => null, 'data' => json_encode([
        'card_bg' => '#0b1a30', 'text_color' => '#ffffff',
        'items' => [['value' => 100, 'suffix' => '+', 'label' => 'проектов', 'icon_svg' => '']],
    ])]);
    assert_not_contains(' style="', $rendered['html']);
    assert_contains('--counters-bg:#0b1a30', $rendered['css'], 'переменная фона карточки');
    assert_contains('--counters-text:#ffffff', $rendered['css'], 'переменная цвета текста');
});

test('Counters: без цветов — без инлайн-стиля (значения по умолчанию)', function () {
    $rendered = BlockRenderer::render(['id' => 2, 'type' => 'counters', 'custom_css' => null, 'data' => json_encode([
        'items' => [['value' => 5, 'suffix' => '', 'label' => 'X', 'icon_svg' => '']],
    ])]);
    assert_not_contains(' style="', $rendered['html']);
    assert_not_contains('--counters-bg', $rendered['css'], 'без переменной фона');
    assert_not_contains('--counters-text', $rendered['css'], 'без переменной текста');
});

test('Counters: цифры сплошного цвета, а не градиентом по тексту', function () {
    $css = theme_css();

    // Градиент по тексту (`background-clip: text` + прозрачная заливка)
    // перекрывал «Цвет текста» блока: что бы редактор ни выбрал, цифры
    // оставались прежними.
    $rules = [];
    if (preg_match_all('/\.counter__value[^{]*\{([^}]*)\}/s', $css, $matches) > 0) {
        $rules = $matches[1];
    }
    assert_true($rules !== [], 'правила для цифр счётчика должны быть в теме');
    foreach ($rules as $rule) {
        assert_not_contains('background-clip: text', $rule, 'цифры красятся цветом, а не градиентом');
        assert_not_contains('text-fill-color: transparent', $rule);
    }
    // Цвет чисел — одна переменная блока: «Цвет текста» главнее, без него —
    // цвет темы, а без подложки — цвет секции (тест 401). Флаг приоритета
    // снят: он перебивал правило тёмной темы, которое затирало «Цвет текста»
    // блока, — правила больше нет, и спорить не с кем.
    assert_contains('color: var(--counters-fg);', $css);
    assert_true(
        (bool) preg_match('/\.counter__value,\s*\.counter__suffix\s*\{[^}]*color: var\(--counters-fg\);/', $css),
        'цвет числа объявлен у самого числа'
    );
    assert_contains('--counters-fg: var(--counters-text, var(--gov-title));', $css);
});

test('Counters: размер, фон, положение и выравнивание иконок настраиваются', function () {
    $rendered = BlockRenderer::render(['id' => 3, 'type' => 'counters', 'custom_css' => null, 'data' => json_encode([
        'icon_size' => 40,
        'icon_bg' => 'off',
        'icon_position' => 'right',
        'text_align' => 'center',
        'items' => [['value' => 12, 'label' => 'проектов', 'icon_svg' => 'chart-bar']],
    ])]);

    assert_contains('--counter-icon-size:40px', $rendered['css']);
    assert_contains('block-counters--icons-no-bg', $rendered['html']);
    assert_contains('block-counters--icon-pos-right', $rendered['html']);
    assert_contains('block-counters--text-align-center', $rendered['html']);

    $css = theme_css();
    assert_contains('width: var(--counter-icon-size, 28px);', $css);
    // Наведение — только у показателя-ссылки: у обычного подсветка иконки
    // обещала переход, которого нет.
    assert_contains('.block-counters--icons-no-bg .counter--link:hover .counter__icon', $css);
    assert_not_contains('.counter:hover .counter__icon', $css);
    assert_contains('.block-counters--icon-pos-right .counter', $css);
    assert_contains('.block-counters--text-align-center .counter__body', $css);
});
