<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\SectionColors;
use App\Core\VariantPreview;

/**
 * «Текст с фото»: раскладки «Фото до края», «Текст поверх фото» и «Фото во
 * всю ширину». Раскладка — отдельная настройка: сторона фото остаётся
 * осмысленной и у «до края», и у «поверх». Умолчание «Рядом» — прежний вид.
 */

/** @param array<string, mixed> $data @return array{html:string, css:string} */
function text_image_render(array $data): array
{
    $rendered = BlockRenderer::render([
        'id' => 395,
        'type' => 'text_image',
        'data' => json_encode($data + [
            'title' => 'Об Агентстве', 'text' => '<p>Текст.</p>', 'image_side' => 'right',
            'image_ratio' => 'auto', 'image_width' => 40, 'items' => [],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) ($rendered['css'] ?? '')];
}

test('Раскладки «Текста с фото» предложены плитками с рисунком', function (): void {
    $fields = BlockFieldSchema::all()['text_image'];
    $layout = $fields['layout'];
    assert_same(['side', 'bleed', 'overlap', 'wide'], array_keys($layout->options));
    assert_same('side', $layout->default, 'умолчание обязано быть прежним видом');
    foreach (['split', 'bleed', 'photo-card', 'wide'] as $shape) {
        assert_true(VariantPreview::isKnown($shape), "рисунок {$shape} не описан");
    }
    // Ширина кадра — доля ряда, и смысл у неё есть только у «Рядом».
    assert_same(['field' => 'layout', 'values' => ['side']], $fields['image_width']->when);
});

test('Раскладка доходит до разметки, а без фото блок остаётся текстом', function (): void {
    foreach (['side', 'bleed', 'overlap', 'wide'] as $layout) {
        $out = text_image_render(['layout' => $layout, 'image' => '/uploads/public/test.jpg']);
        assert_contains('block-textimage--layout-' . $layout, $out['html']);
    }
    $noImage = text_image_render(['layout' => 'overlap', 'image' => '']);
    assert_contains('block-textimage--layout-side', $noImage['html'], 'раскладка без фото сломала бы колонку текста');
    assert_false(str_contains($noImage['html'], 'textimage__info--card'));

    // Доля ширины уходит в CSS только у «Рядом»: у остальных раскладок сетка своя.
    assert_contains('--textimage-visual:40fr', text_image_render(['layout' => 'side', 'image' => '/uploads/public/test.jpg'])['css']);
    assert_false(str_contains(text_image_render(['layout' => 'bleed', 'image' => '/uploads/public/test.jpg'])['css'], '--textimage-visual'));
});

test('Карточка текста поверх фото — своя поверхность на тёмной секции', function (): void {
    $out = text_image_render(['layout' => 'overlap', 'image' => '/uploads/public/test.jpg']);
    assert_contains('textimage__info textimage__info--card', $out['html']);
    // Без записи в SURFACES белая карточка на navy-секции получила бы белый текст.
    assert_true(in_array('.textimage__info--card', SectionColors::SURFACES, true));
    assert_true(substr_count(theme_css(), '.textimage__info--card') >= 3, 'правила поверхностей темы не знают карточку');
});

test('Стили раскладок считаются по ширине блока, а пропорция видит и голый img', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');
    foreach (['layout-bleed', 'layout-overlap', 'layout-wide'] as $layout) {
        assert_contains('.block-textimage--' . $layout, $css);
    }
    assert_contains('@container text-image (min-width: 901px)', $css);
    // Внутри колонки конструктора фото за край не уходит: блок там не по центру экрана.
    assert_contains('.cms-columns__col .block-textimage--layout-bleed .textimage__visual { margin-inline: 0; }', $css);
    // У фото без уменьшенных копий класс кадра висит на самом <img>, и правило
    // пропорции видело только <picture> — настройка молча не работала.
    foreach (['16-9', '4-3', '1-1'] as $ratio) {
        assert_contains('.block-textimage--ratio-' . $ratio . ' :is(.textimage__media img, img.textimage__media)', theme_css());
    }
});
