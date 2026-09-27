<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Models\Setting;

/**
 * «Отзывы», вариант «Одна цитата» — слово руководителя крупным кеглем с
 * портретом одного из трёх видов. Выводится первый отзыв списка.
 */

/** @param array<string, mixed> $data */
function testimonial_feature_html(array $data, array $item = []): string
{
    $rendered = BlockRenderer::render([
        'id' => 398,
        'type' => 'testimonials',
        'data' => json_encode($data + [
            'variant' => 'feature',
            'items' => [$item + ['quote' => 'Одна мысль крупным кеглем.', 'name' => 'Имя Фамилия', 'role' => 'Должность', 'photo' => '/uploads/public/test.jpg'], ['quote' => 'Второй отзыв', 'name' => 'Другой']],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'];
}

test('«Одна цитата» предложена плиткой, вид портрета виден только у неё', function (): void {
    $fields = BlockFieldSchema::all()['testimonials'];
    assert_true(isset($fields['variant']->options['feature']));
    assert_same(['round', 'tall', 'mini'], array_keys($fields['portrait']->options));
    assert_same('round', $fields['portrait']->default);
    assert_same(['field' => 'variant', 'values' => ['feature']], $fields['portrait']->when);
});

test('Выводится первый отзыв, а не лента: одна цитата, одна разметка Review', function (): void {
    $html = testimonial_feature_html(['portrait' => 'round']);
    assert_contains('tquote tquote--round tquote--has-photo', $html);
    assert_contains('Одна мысль крупным кеглем.', $html);
    assert_false(str_contains($html, 'Второй отзыв'), 'в «Одной цитате» оказался второй отзыв');
    assert_same(1, substr_count($html, 'schema.org/Review'));
    assert_false(str_contains($html, 'data-carousel'), 'одной цитате не нужна карусель');
    // Точку фокуса кадра печатает сам Media::picture инлайновой переменной —
    // это единственный разрешённый инлайн-стиль; своих у шаблона нет.
    assert_false(str_contains(preg_replace('/ style="--media-object-position:[^"]*"/', '', $html) ?? '', 'style='), 'инлайн-стиль в блоке');

    // Без текста цитаты блоку нечего показать — пустой рамки не остаётся.
    assert_false(str_contains(testimonial_feature_html([], ['quote' => '']), 'tquote__figure'));
});

test('Вид портрета доходит до разметки; мелкий портрет стоит у подписи', function (): void {
    assert_contains('tquote--tall', testimonial_feature_html(['portrait' => 'tall']));
    $mini = testimonial_feature_html(['portrait' => 'mini']);
    assert_contains('tquote__mini', $mini);
    assert_false(str_contains($mini, 'tquote__photo'), 'крупный портрет рядом с мелким');
});

test('Росчерк выводится только при выбранном рукописном шрифте', function (): void {
    // Пустое значение — «шрифт не выбран»: такого слуга нет в каталоге.
    Setting::set('design_font_script', '');
    assert_false(str_contains(testimonial_feature_html(['portrait' => 'mini']), 'tquote__sign'), 'без рукописного шрифта росчерк — это повтор имени обычным набором');
    Setting::set('design_font_script', 'caveat');
    try {
        $html = testimonial_feature_html(['portrait' => 'mini']);
    } finally {
        Setting::set('design_font_script', '');
    }
    assert_contains('class="tquote__sign" aria-hidden="true">Имя Фамилия', $html);
});
