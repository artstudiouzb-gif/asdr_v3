<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;

/** @param array<string, mixed> $input */
function image_block_html(array $input, int $id = 61): string
{
    return BlockRenderer::render([
        'id' => $id,
        'type' => 'image',
        'data' => json_encode(BlockFieldSchema::normalize('image', $input, 'ru'), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];
}

test('Изображение: подпись и источник рисует общий компонент .media-caption', function (): void {
    $html = image_block_html([
        'image' => '/uploads/public/demo-agency-hero.jpg',
        'alt' => 'Совещание',
        'caption' => 'Заседание коллегии',
        'credit' => 'Пресс-служба',
    ]);

    assert_contains('block-image__img', $html);
    assert_contains('alt="Совещание"', $html);
    // Третий набор правил под ту же подпись разъехался бы с галереей новости
    // и альбомом при первой правке.
    assert_contains('class="media-caption"', $html);
    assert_contains('Заседание коллегии', $html);
    assert_contains('media-caption__credit', $html);
});

test('Изображение: клик делает что-то одно — ссылка важнее увеличения', function (): void {
    // Ссылка задана: увеличение выключается, иначе на один клик приходится два
    // действия и посетитель не угадает, какое получит.
    $withLink = image_block_html([
        'image' => '/uploads/public/demo-agency-hero.jpg',
        'link' => '/about',
        'zoom' => '1',
    ]);
    assert_contains('href="/about"', $withLink);
    assert_not_contains('block-image--zoomable', $withLink);

    // Только увеличение: лайтбокс общий, он ищет ссылку на файл внутри
    // известного контейнера — поэтому увеличение это ссылка на сам снимок.
    $zoomOnly = image_block_html([
        'image' => '/uploads/public/demo-agency-hero.jpg',
        'zoom' => '1',
    ], 62);
    assert_contains('block-image--zoomable', $zoomOnly);
    assert_contains('href="/uploads/public/demo-agency-hero.jpg"', $zoomOnly);

    // Ни того, ни другого — рамка не притворяется ссылкой.
    $plain = image_block_html(['image' => '/uploads/public/demo-agency-hero.jpg'], 63);
    assert_not_contains('<a class="block-image__frame"', $plain);
    assert_contains('<span class="block-image__frame"', $plain);
});

test('Изображение: без файла блок не рисует пустую рамку', function (): void {
    $html = image_block_html(['image' => '', 'caption' => 'Подпись без снимка'], 64);

    assert_not_contains('block-image__frame', $html);
    assert_not_contains('Подпись без снимка', $html);
});

test('Изображение: опасный адрес файла отбрасывается', function (): void {
    $data = BlockFieldSchema::normalize('image', [
        'image' => 'javascript:alert(1)',
        'link' => 'javascript:alert(2)',
    ], 'ru');

    assert_same('', $data['image']);
    assert_same('', $data['link']);
});

test('Изображение: у каждой ширины и пропорции есть правило', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/image.css');

    assert_contains('.block-image--w-reading', $css);
    // Колонка чтения берётся из общего токена, а не своим числом.
    assert_contains('var(--editorial-reading', $css);
    foreach (['16-9', '3-2', '4-3', '1-1'] as $ratio) {
        assert_contains('.block-image--ratio-' . $ratio, $css);
    }
    assert_contains('.block-image--zoomable', $css);
});
