<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;

/**
 * «Призыв к действию», вариант «Половина с фото» (прежде «Светлый сплит»).
 *
 * Фото шло фоном, и сокращённый `background` в теме обнулял
 * `background-image`: вариант не показал снимка ни разу. Теперь это настоящая
 * картинка на половину блока, у текста — светлая подложка или основной цвет,
 * сторона фото выбирается, кнопок бывает две.
 */

/** @param array<string, mixed> $data */
function cta_split_html(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 396,
        'type' => 'cta',
        'data' => json_encode($data + [
            'variant' => 'media-light', 'title' => 'Открытые данные', 'text' => 'Показатели стратегии.',
            'button_text' => 'Перейти', 'button_url' => '/opendata', 'image' => '/uploads/public/test.jpg',
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'];
}

test('Настройки половины с фото видны только у этого варианта', function (): void {
    $fields = BlockFieldSchema::all()['cta'];
    assert_same('Половина с фото', $fields['variant']->options['media-light']);
    assert_same(['light', 'navy'], array_keys($fields['split_scheme']->options));
    assert_same(['right', 'left'], array_keys($fields['image_side']->options));
    assert_same('right', $fields['image_side']->default, 'умолчание обязано повторять прежнюю раскладку');
    foreach (['split_scheme', 'image_side', 'button2_text', 'button2_url'] as $key) {
        assert_same(['field' => 'variant', 'values' => ['media-light']], $fields[$key]->when, "поле {$key} видно у вариантов, где ни на что не влияет");
    }
});

test('Фото — настоящая картинка, а не фон', function (): void {
    $html = cta_split_html([]);
    assert_contains('block-banner--has-photo', $html);
    assert_contains('<img', $html);
    assert_contains('block-banner__img', $html);
    assert_false(str_contains($html, 'block-banner__photo'), 'вернулся фоновый кадр, который тема обнуляла');
    assert_false(str_contains($html, 'style='), 'инлайн-стиль в блоке');
    // Картинка декоративна: смысл несёт заголовок рядом.
    assert_contains('alt=""', $html);

    $noPhoto = cta_split_html(['image' => '']);
    assert_false(str_contains($noPhoto, 'block-banner--has-photo'));
    assert_false(str_contains($noPhoto, 'block-banner__media'), 'пустая половина вместо текста во всю ширину');
});

test('Схема, сторона и вторая кнопка доходят до разметки', function (): void {
    $html = cta_split_html(['split_scheme' => 'navy', 'image_side' => 'left', 'button2_text' => 'Как пользоваться', 'button2_url' => '/faq']);
    assert_contains('block-banner--scheme-navy', $html);
    assert_contains('block-banner--photo-left', $html);
    assert_contains('block-banner__button--ghost btn-fill" href="/faq">Как пользоваться', $html);

    // Ссылка второй кнопки проверяется так же, как первой: `javascript:` — чужой код.
    $unsafe = cta_split_html(['button2_text' => 'Опасно', 'button2_url' => 'javascript:alert(1)']);
    assert_false(str_contains($unsafe, 'javascript:'));
    assert_false(str_contains($unsafe, 'block-banner__button--ghost'));

    // Прежние блоки без новых ключей выглядят как раньше: светлая подложка, фото справа.
    $legacy = cta_split_html([]);
    assert_contains('block-banner--scheme-light', $legacy);
    assert_contains('block-banner--photo-right', $legacy);
});

test('Кнопка на основном цвете белая, а свой цвет кнопки не красит вторую', function (): void {
    $css = theme_css();
    assert_contains('.block-banner--scheme-navy .block-banner__button { border-color: #fff; background: #fff; color: var(--gov-navy); }', $css);
    assert_contains('.block-banner--custom-btn .block-banner__button:not(.block-banner__button--ghost)', $css);
    assert_contains('.block-banner--photo-left .block-banner__media { order: -1; }', $css);
});
