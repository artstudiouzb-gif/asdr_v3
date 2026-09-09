<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockRenderer;

/**
 * Иконки кнопок обложки: ключ Tabler из библиотеки или своя картинка/SVG.
 */
function hero_with(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 910,
        'type' => 'hero',
        'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('hero'), array_merge([
            'title' => 'Обложка',
            'button_text' => 'Об агентстве',
            'button_url' => '/o-nas',
            'button2_text' => 'Стратегия',
            'button2_url' => '/strategiya',
        ], $data))),
    ]);

    return (string) $rendered['html'];
}

test('Обложка: иконка кнопки из библиотеки рисуется рядом с текстом', function () {
    $html = hero_with(['button_icon' => 'building-bank', 'button2_icon' => 'target-arrow']);

    assert_contains('block-hero__button-icon', $html);
    assert_contains('#tabler-building-bank', $html);
    assert_contains('#tabler-target-arrow', $html);
});

test('Обложка: своя иконка важнее выбранной из библиотеки', function () {
    $html = hero_with([
        'button_icon' => 'building-bank',
        'button_icon_image' => '/uploads/public/my-icon.svg',
    ]);

    assert_contains('<img class="block-hero__button-icon" src="/uploads/public/my-icon.svg"', $html);
    assert_not_contains('#tabler-building-bank', $html, 'при своей иконке ключ библиотеки не выводится');
});

test('Обложка: без иконок кнопки остаются прежними', function () {
    $html = hero_with([]);

    assert_not_contains('block-hero__button-icon', $html);
    assert_contains('Об агентстве', $html);
});

test('Обложка: небезопасный адрес иконки отбрасывается при сохранении', function () {
    $normalized = HeroBlockNormalizer::normalize([
        'button_text' => 'Кнопка',
        'button_url' => '/ok',
        'button_icon' => '../../etc/passwd',
        'button_icon_image' => 'javascript:alert(1)',
        'button2_icon_image' => '/uploads/public/icon.svg',
    ]);

    assert_same('', $normalized['button_icon_image'], 'javascript: не должен сохраняться');
    assert_same('', $normalized['button_icon'], 'ключ иконки чистится до безопасного вида');
    // Обычный медиа-адрес проходит: иконку можно держать и на CDN.
    assert_same('/uploads/public/icon.svg', $normalized['button2_icon_image']);
});

test('Обложка: поля иконок доступны в форме блока', function () {
    $form = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/block_form.php');

    foreach (['button_icon', 'button_icon_image', 'button2_icon', 'button2_icon_image'] as $field) {
        assert_contains("'{$field}'", $form, "нет поля {$field} в форме обложки");
    }
});
