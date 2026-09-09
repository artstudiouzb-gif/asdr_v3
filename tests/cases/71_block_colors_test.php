<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Настраиваемые цвета блоков CTA, баннер, направления, CTA-полоса:
// CSS-переменные публикуются в scoped CSS, а не в style-атрибутах.

function render_color_block(string $type, array $data): string
{
    $rendered = BlockRenderer::render(['id' => 1, 'type' => $type, 'custom_css' => null, 'data' => json_encode($data)]);
    assert_not_contains(' style="', $rendered['html'], "{$type}: CSS-переменные не должны попадать в HTML");

    return $rendered['html'] . "\n" . $rendered['css'];
}

test('CTA: цвета фона/текста/кнопки отдаются переменными', function () {
    $h = render_color_block('cta', ['title' => 'T', 'button_text' => 'X', 'button_url' => '/o-nas', 'bg_color' => '#111111', 'text_color' => '#eeeeee', 'button_color' => '#00aa88']);
    assert_true(str_contains($h, '--cta-bg:#111111'), 'фон CTA');
    assert_true(str_contains($h, '--cta-text:#eeeeee'), 'текст CTA');
    assert_true(str_contains($h, '--cta-btn:#00aa88'), 'кнопка CTA');
});

test('CTA с медиа: цвета отдаются переменными в обоих вариантах', function () {
    $dark = render_color_block('cta', ['variant' => 'media-dark', 'title' => 'T', 'bg_color' => '#0a0a0a', 'text_color' => '#ffffff']);
    assert_true(str_contains($dark, '--banner-bg:#0a0a0a') && str_contains($dark, '--banner-text:#ffffff'), 'тёмный баннер');
    $light = render_color_block('cta', ['variant' => 'media-light', 'title' => 'T', 'button_color' => '#123456', 'button_text' => 'Go', 'button_url' => '/x']);
    assert_true(str_contains($light, '--banner-btn:#123456'), 'светлый баннер кнопка');
});

test('Направления: цвет карточек и текста отдаются переменными', function () {
    $h = render_color_block('cards_grid', ['title' => 'Направления', 'card_bg' => '#0b1a30', 'text_color' => '#ffffff', 'items' => [['title' => 'A', 'text' => 'b', 'icon_svg' => '', 'url' => '']]]);
    assert_true(str_contains($h, '--card-bg:#0b1a30'), 'фон карточек');
    assert_true(str_contains($h, '--cards-text:#ffffff'), 'текст карточек');
});

test('CTA-полоса: цвета отдаются переменными', function () {
    $h = render_color_block('cta', ['variant' => 'band', 'title' => 'T', 'bg_color' => '#222222', 'text_color' => '#fafafa', 'button_color' => '#ffcc00', 'button_text' => 'X', 'button_url' => '/x']);
    assert_true(str_contains($h, '--ctaband-bg:#222222') && str_contains($h, '--ctaband-text:#fafafa') && str_contains($h, '--ctaband-btn:#ffcc00'), 'цвета полосы');
});

test('Блоки без выбранных цветов не добавляют переменные', function () {
    $h = render_color_block('cta', ['title' => 'T']);
    assert_true(!str_contains($h, '--cta-bg') && !str_contains($h, '--cta-text') && !str_contains($h, '--cta-btn'), 'нет лишних переменных');
});

test('Настройка фона кнопок имеет приоритет над государственной темой', function () {
    $css = theme_css();
    assert_contains('.block-hero--media .block-hero__button:not(.block-hero__button--ghost)', $css);
    assert_contains('background: var(--hero-btn, #16406e) !important;', $css);
    assert_contains('.block-hero__button--ghost { background: transparent !important;', $css);
    assert_contains('background: var(--cta-btn) !important;', $css);

    // Поля CTA рисует схема, поэтому смотрим на редактор целиком.
    $form = block_editor_markup();
    assert_contains('Цвет фона основной кнопки', $form);
    assert_contains('Цвет фона кнопки', $form);
});
