<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockRenderer;

// У старой обложки (собранной в блоке) кегль заголовка задаётся числом. Пусто —
// размер темы, и своего CSS у блока от этого не появляется.
test('Герой: размер заголовка числом, пусто — как в теме', function (): void {
    $render = static function (array $input, int $id): string {
        $data = HeroBlockNormalizer::normalize(['title_field' => 'Агентство'] + $input, 'ru');
        return (string) BlockRenderer::render([
            'id' => $id, 'type' => 'hero', 'custom_css' => '',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ])['css'];
    };

    assert_contains('#block-910 .block-hero__title{font-size:clamp(min(var(--step-6),72px),6vw,72px)}', $render(['title_size' => '72'], 910));
    assert_not_contains('.block-hero__title{font-size', $render([], 911));
    assert_contains('160px)}', $render(['title_size' => '999'], 912));
});
