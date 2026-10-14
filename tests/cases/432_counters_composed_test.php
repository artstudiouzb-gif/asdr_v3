<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\CounterFormat;

/*
 * «Показатели»: раскладки, где число главное — «Табло», «Главная цифра»,
 * «Мозаика», «Линейка». Разметка показателя та же, что у остальных видов;
 * подложку они рисуют сами, а полосу пути к цели показывают у каждого
 * показателя, где цель задана.
 */

function counters_composed_render(string $variant): array
{
    return BlockRenderer::render(['id' => 432, 'type' => 'counters', 'custom_css' => null, 'data' => json_encode([
        'title' => 'В цифрах', 'variant' => $variant, 'count_up' => false, 'panel' => 'card',
        'items' => [
            ['value' => '128', 'label' => 'Программ', 'target' => '200', 'delta' => '+14', 'delta_tone' => 'good'],
            ['value' => '2 400', 'suffix' => '+', 'label' => 'Экспертов'],
        ],
    ])]);
}

test('Показатели: четыре раскладки выбираются и выводят полосу к цели', function (): void {
    $form = \App\Core\BlockData\BlockFieldSchema::formHtml('counters', ['variant' => 'board']);
    foreach (['Табло', 'Главная цифра', 'Мозаика', 'Линейка'] as $label) {
        assert_contains($label, $form);
    }
    foreach (CounterFormat::COMPOSED as $variant) {
        $r = counters_composed_render($variant);
        $html = $r['html'];
        assert_contains('block-counters--' . $variant, $html, $variant);
        assert_contains('block-counters--composed', $html, $variant);
        assert_contains('block-counters--panel-none', $html, 'подложку раскладка рисует сама: ' . $variant);
        assert_contains('counter__track', $html, 'полоса к цели у показателя с целью: ' . $variant);
        assert_same(1, substr_count($html, 'counter__track'), 'показатель без цели остаётся числом: ' . $variant);
        assert_not_contains(' style="', $html, $variant);
    }
    assert_contains('counter--lead', counters_composed_render('feature')['html'], 'главная цифра — первый показатель');
    assert_contains('--counters-emblem:url(', counters_composed_render('board')['css'], 'эмблема табло — абсолютным адресом');
});

test('Показатели: стили раскладок лежат в файле блока', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    foreach (CounterFormat::COMPOSED as $variant) {
        assert_contains('.block-counters--' . $variant, $css, $variant);
    }
    assert_contains('min(var(--cnum), 27cqi)', $css, 'размер числа — от ширины колонки');
});
