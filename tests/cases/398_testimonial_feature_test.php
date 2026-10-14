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

test('Размер и форма портрета слева доходят до вывода; умолчание вида не меняет', function (): void {
    $fields = BlockFieldSchema::all()['testimonials'];
    assert_same(['circle', 'rounded', 'square', 'arch', 'oval'], array_keys($fields['portrait_shape']->options));
    assert_same(144, $fields['portrait_size']->default);

    $default = BlockRenderer::render([
        'id' => 398, 'type' => 'testimonials', 'custom_css' => '',
        'data' => json_encode(['variant' => 'feature', 'portrait' => 'round', 'items' => [['quote' => 'Мысль', 'photo' => '/uploads/public/test.jpg']]]),
    ]);
    assert_false(str_contains((string) $default['html'], 'tquote--shape-'), 'круг — умолчание без класса');
    assert_false(str_contains((string) ($default['css'] ?? ''), '--tquote-photo'), 'размер по умолчанию в scoped CSS не печатается');

    $custom = BlockRenderer::render([
        'id' => 398, 'type' => 'testimonials', 'custom_css' => '',
        'data' => json_encode(['variant' => 'feature', 'portrait' => 'round', 'portrait_size' => 220, 'portrait_shape' => 'arch', 'items' => [['quote' => 'Мысль', 'photo' => '/uploads/public/test.jpg']]]),
    ]);
    assert_contains('tquote--shape-arch', (string) $custom['html']);
    assert_contains('--tquote-photo:220px', json_encode($custom, JSON_UNESCAPED_SLASHES) ?: '');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');
    foreach (['rounded', 'square', 'arch', 'oval'] as $shape) {
        assert_contains('.tquote--shape-' . $shape . ' .tquote__photo', $css, 'у формы «' . $shape . '» нет правила');
    }
});

test('Размер текста цитаты доходит до вывода; «средний» — прежний вид без класса', function (): void {
    $fields = BlockFieldSchema::all()['testimonials'];
    assert_same(['s', 'm', 'l', 'xl'], array_keys($fields['quote_size']->options));
    assert_same('m', $fields['quote_size']->default);

    assert_false(str_contains(testimonial_feature_html(['portrait' => 'round']), 'tquote--size-'));
    assert_contains('tquote--size-xl', testimonial_feature_html(['portrait' => 'round', 'quote_size' => 'xl']));

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');
    foreach (['s', 'l', 'xl'] as $size) {
        assert_contains('.tquote--size-' . $size . ' .tquote__text', $css, 'у размера «' . $size . '» нет правила');
    }
});

test('Положение фото и выравнивание сверху/снизу доходят до вывода; «слева» — прежний вид', function (): void {
    $fields = BlockFieldSchema::all()['testimonials'];
    assert_same(['left', 'right', 'top', 'bottom'], array_keys($fields['portrait_side']->options));
    assert_same(['field' => 'portrait_side', 'values' => ['top', 'bottom']], $fields['portrait_align']->when, 'выравнивание видно только при фото сверху или снизу');

    assert_false(str_contains(testimonial_feature_html(['portrait' => 'round']), 'tquote--photo-'));
    assert_contains('tquote--photo-right', testimonial_feature_html(['portrait' => 'round', 'portrait_side' => 'right']));
    $top = testimonial_feature_html(['portrait' => 'round', 'portrait_side' => 'top', 'portrait_align' => 'center']);
    assert_contains('tquote--photo-top', $top);
    assert_contains('tquote--photo-align-center', $top);
    // Выравнивание без положения сверху/снизу смысла не имеет и не печатается.
    assert_false(str_contains(testimonial_feature_html(['portrait' => 'round', 'portrait_side' => 'right', 'portrait_align' => 'center']), 'photo-align'));
    // У маленького портрета место одно — у подписи.
    assert_false(str_contains(testimonial_feature_html(['portrait' => 'mini', 'portrait_side' => 'top']), 'tquote--photo-'));

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');
    foreach (['.tquote--photo-right .tquote__photo', '.tquote--photo-bottom .tquote__photo', '.tquote--photo-align-center .tquote__photo', '.tquote--photo-align-right .tquote__photo', '.tquote--photo-align-center .tquote__body{text-align:center}'] as $selector) {
        assert_contains($selector, $css);
    }
});
