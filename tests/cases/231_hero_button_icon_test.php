<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Левый отступ кнопки hero укорочен под зону иконки (46px). Без иконки его
// нужно вернуть, иначе текст кнопки прилипает к левому краю.

/** Рендерит hero с заданными полями кнопок. */
function hero_buttons_html(array $data): string
{
    $result = BlockRenderer::render([
        'id' => 810,
        'type' => 'hero',
        'data' => json_encode($data + [
            'title' => 'Заголовок',
            'button_url' => '/news',
            'button2_url' => '/news',
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $result['html'];
}

test('Hero: модификатор ширины отступа ставится только у кнопки с иконкой', function () {
    $html = hero_buttons_html([
        'button_text' => 'Без иконки',
        'button2_text' => 'С иконкой',
        'button2_icon' => 'file-text',
    ]);

    assert_contains('Без иконки', $html);
    assert_contains('С иконкой', $html);

    // У кнопки с иконкой — модификатор, у кнопки без иконки его быть не должно.
    preg_match_all('/<a class="(block-hero__button[^"]*)"[^>]*>(.*?)<\/a>/s', $html, $m, PREG_SET_ORDER);
    assert_true(count($m) >= 2, 'обе кнопки отрендерились');

    foreach ($m as $match) {
        $classes = $match[1];
        $hasIcon = str_contains($match[2], 'block-hero__button-icon');
        $hasModifier = str_contains($classes, 'block-hero__button--with-icon');
        assert_same($hasIcon, $hasModifier, 'модификатор должен совпадать с наличием иконки: ' . $classes);
    }
});

test('Hero: у кнопки без иконки отступы симметричные', function () {
    $css = theme_css();

    // Базовое правило кнопки — симметричное; узкий левый отступ живёт только
    // в модификаторе, иначе текст без иконки прижимается к краю.
    assert_not_contains('padding: 2px 24px 2px 4px !important;', $css);
    assert_contains('.block-hero__button--with-icon { padding-left: 4px !important; }', $css);
});
