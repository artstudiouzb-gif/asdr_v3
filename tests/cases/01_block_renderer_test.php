<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Рендер блока с ПОВРЕЖДЁННЫМ JSON не должен приводить к фаталу и обязан
// вернуть корректную обёртку <section id="block-...">.
test('BlockRenderer: битый JSON не роняет рендер', function () {
    $result = BlockRenderer::render([
        'id' => 7,
        'type' => 'text',
        'data' => '{ это не валидный json',
        'custom_css' => '',
    ]);
    assert_true(is_array($result));
    assert_contains('id="block-7"', $result['html']);
    assert_contains('cms-block--text', $result['html']);
});

test('BlockRenderer: пустой data подставляет дефолты', function () {
    $result = BlockRenderer::render([
        'id' => 3,
        'type' => 'text',
        'data' => '',
        'custom_css' => '',
    ]);
    assert_contains('id="block-3"', $result['html']);
    // Пресет отступов по умолчанию — premium.
    assert_contains('cms-block--space-premium', $result['html']);
});

test('BlockRenderer: выводит пользовательский якорь и сохраняет технический id', function () {
    $result = BlockRenderer::render([
        'id' => 31,
        'type' => 'text',
        'data' => json_encode(['title' => 'Форма', 'content' => '<p>x</p>', '_anchor' => 'forma']),
        'custom_css' => '',
    ]);

    assert_contains('id="block-31"', $result['html']);
    assert_contains('<span id="forma" class="cms-block__anchor" aria-hidden="true"></span>', $result['html']);
});

test('BlockRenderer: неизвестный тип не роняет рендер', function () {
    $result = BlockRenderer::render([
        'id' => 1,
        'type' => 'no_such_type_xyz',
        'data' => '{}',
        'custom_css' => '',
    ]);
    assert_true(is_array($result));
    assert_contains('id="block-1"', $result['html']);
});

test('BlockRenderer: пресет отступов и reveal попадают в классы/атрибуты', function () {
    $result = BlockRenderer::render([
        'id' => 5,
        'type' => 'text',
        'data' => json_encode(['title' => 'T', 'content' => '<p>x</p>', '_spacing' => 'max', '_reveal' => true]),
        'custom_css' => '',
    ]);
    assert_contains('cms-block--space-max', $result['html']);
    assert_contains('data-reveal', $result['html']);
});

test('BlockRenderer: cards_grid без выбранного стиля рендерится классическим', function () {
    $result = BlockRenderer::render([
        'id' => 11,
        'type' => 'cards_grid',
        'data' => json_encode([
            'variant' => 'icon',
            'columns' => 3,
            'items' => [[
                'icon_svg' => 'target',
                'title' => 'Направление',
                'text' => 'Описание',
                'url' => '/o-nas',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'custom_css' => '',
    ]);

    assert_contains('id="block-11"', $result['html']);
    // Нумерация включена по умолчанию: номер печатался всегда, и выключить его
    // было нечем — настройка появилась в том же состоянии, чтобы вид собранных
    // страниц не менялся.
    assert_contains('class="block-cards block-cards--icon-pos-top block-cards--text-align-left block-cards--numbered"', $result['html']);
    assert_contains('feature-card__icon', $result['html']);
    assert_contains('#block-11 .feature-card__icon', $result['css']);
    assert_contains('--feature-card-icon-size:22px', $result['css']);
    assert_not_contains('block-cards--icons-no-bg', $result['html']);
});

test('BlockRenderer: cards_grid применяет размер и отключение фона иконок', function () {
    $result = BlockRenderer::render([
        'id' => 12,
        'type' => 'cards_grid',
        'data' => json_encode([
            'variant' => 'icon',
            'icon_size' => 38,
            'icon_bg' => 'off',
            'icon_position' => 'right',
            'text_align' => 'center',
            'card_style' => 'new',
            'card_gap' => 44,
            'items' => [['icon_svg' => 'target', 'title' => 'Направление']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'custom_css' => '',
    ]);

    assert_contains('block-cards--icons-no-bg', $result['html']);
    assert_contains('block-cards--icon-pos-right', $result['html']);
    assert_contains('block-cards--text-align-center', $result['html']);
    assert_contains('feature-card feature-card--has-icon', $result['html']);
    assert_contains('feature-card__content', $result['html']);
    assert_contains('--feature-card-icon-size:38px', $result['css']);
    assert_contains('.block-cards--style-new .cards-grid{gap:44px;}', $result['css']);
    assert_contains('font-size:var(--font-size-meta,.75rem)', $result['css']);
    assert_contains('letter-spacing:var(--meta-letter-spacing,.12em)', $result['css']);
    assert_contains('font-size:var(--font-size-h3,clamp(18px,1.35vw,21px))', $result['css']);
    assert_not_contains('.feature-card__icon svg{width:38px', $result['css']);

    $theme = theme_css();
    assert_contains('var(--feature-card-icon-size, 22px) !important', $theme);
    assert_contains('.block-cards--icons-no-bg .feature-card:hover .feature-card__icon', $theme);
    assert_contains('.block-cards--icon-pos-right .feature-card--has-icon .feature-card__icon', $theme);
    assert_contains('.block-cards--text-align-center .feature-card__content', $theme);
});
