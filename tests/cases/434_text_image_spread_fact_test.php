<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\VariantPreview;

/**
 * «Текст с фото»: раскладка «Разворот», факт на фото и вторая ссылка.
 * Факт выводится только там, где у кадра свободен угол; водяной знак
 * «Разворота» — маской абсолютным адресом из scoped CSS.
 */

/** @param array<string, mixed> $data @return array{html:string, css:string} */
function text_image_434(array $data): array
{
    $rendered = BlockRenderer::render([
        'id' => 434,
        'type' => 'text_image',
        'data' => json_encode($data + [
            'title' => 'Стратегия', 'text' => '<p>Лид.</p><p>Текст.</p>', 'image' => '/uploads/public/test.jpg',
            'image_side' => 'right', 'image_ratio' => 'auto', 'image_width' => 50, 'items' => [],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) ($rendered['css'] ?? '')];
}

test('«Разворот» предложен плиткой с рисунком и несёт водяной знак', function (): void {
    $fields = BlockFieldSchema::all()['text_image'];
    assert_true(isset($fields['layout']->options['spread']));
    assert_true(VariantPreview::isKnown('spread'));
    $out = text_image_434(['layout' => 'spread']);
    assert_contains('block-textimage--layout-spread', $out['html']);
    assert_contains('--textimage-emblem:url("', $out['css']);
    assert_false(str_contains(text_image_434(['layout' => 'side'])['css'], '--textimage-emblem'));
});

test('Факт на фото выводится только у раскладок со свободным углом кадра', function (): void {
    $fact = ['fact_value' => '128', 'fact_caption' => 'программ'];
    foreach (['side', 'bleed', 'spread'] as $layout) {
        $html = text_image_434(['layout' => $layout] + $fact)['html'];
        assert_contains('<span class="textimage__fact-value">128</span>', $html);
        assert_contains('block-textimage--has-fact', $html);
    }
    foreach (['overlap', 'wide'] as $layout) {
        assert_false(str_contains(text_image_434(['layout' => $layout] + $fact)['html'], 'textimage__fact'));
    }
    // Без числа подпись не выводится: одна подпись без факта ничего не сообщает.
    assert_false(str_contains(text_image_434(['layout' => 'side', 'fact_caption' => 'программ'])['html'], 'textimage__fact'));
    assert_same(['side', 'bleed', 'spread'], BlockFieldSchema::all()['text_image']['fact_value']->when['values']);
});

test('Вторая ссылка стоит рядом с кнопкой и проверяется на выводе', function (): void {
    $html = text_image_434(['button_text' => 'Подробнее', 'button_url' => '/x', 'link_text' => 'Документы', 'link_url' => '/docs'])['html'];
    assert_contains('<a class="textimage__link" href="/docs">Документы</a>', $html);
    assert_true(strpos($html, 'textimage__button') < strpos($html, 'textimage__link'));
    $bad = text_image_434(['link_text' => 'X', 'link_url' => 'javascript:alert(1)'])['html'];
    assert_false(str_contains($bad, 'javascript:'));
    assert_false(str_contains($bad, 'textimage__actions'));
});

test('Стили «Разворота» и факта живут в полише рядом с остальными раскладками', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');
    foreach (['.block-textimage--layout-spread', '.textimage__fact', 'var(--textimage-emblem)'] as $needle) {
        assert_contains($needle, $css);
    }
});
