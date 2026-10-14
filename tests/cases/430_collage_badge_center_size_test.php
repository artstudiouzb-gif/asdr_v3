<?php

declare(strict_types=1);

use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\BlockRenderer;

// Размер центра печати задаётся в процентах её диаметра: герб с мелкими
// деталями в размере темы (46 %) выходил мелким. Пусто — как в теме.
test('Коллаж: размер центра печати настраивается, пусто — размер темы', function (): void {
    $badge = ['type' => 'badge', 'text' => 'Bog‘laning', 'center' => 'image', 'center_image' => '/uploads/public/gerb.svg'];
    $render = static function (array $extra, int $id) use ($badge): string {
        $data = CollageBlockNormalizer::normalize(['layout' => 'free', 'items' => [$badge + $extra]], 'uz');
        return (string) BlockRenderer::render([
            'id' => $id, 'type' => 'collage', 'custom_css' => '', 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ])['css'];
    };

    assert_contains('#block-920 .collage__item--0 .collage__badge-core{inline-size:70%}', $render(['center_size' => '70'], 920));
    assert_not_contains('.collage__badge-core{inline-size', $render([], 921));
    assert_contains('.collage__badge-core{inline-size:30%}', $render(['center_size' => '5'], 922));
    assert_contains('.collage__badge-core{inline-size:90%}', $render(['center_size' => '500'], 923));
});
