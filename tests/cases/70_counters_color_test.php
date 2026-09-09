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
    assert_contains('color: var(--counters-text, var(--gov-title)) !important;', $css);
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
    assert_contains('var(--counter-icon-size, 28px) !important', $css);
    assert_contains('.block-counters--icons-no-bg .counter:hover .counter__icon', $css);
    assert_contains('.block-counters--icon-pos-right .counter', $css);
    assert_contains('.block-counters--text-align-center .counter__body', $css);
});
