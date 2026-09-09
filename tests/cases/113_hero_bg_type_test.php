<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockData\HeroBlockNormalizer;

/**
 * Загруженное в обложку фото не должно теряться из-за того, что список
 * «Фон секции» остался на «Без фона»: у блока из готовой сборки фона нет,
 * форма показывает «Без фона», и сохранение записывало none поверх снимка.
 */
test('Обложка: фото при типе «Без фона» включает фон-изображение при сохранении', function () {
    $save = static function (array $post): string {
        return (string) HeroBlockNormalizer::normalize($post)['bg_type'];
    };

    assert_same('image', $save(['bg_type' => 'none', 'image' => '/uploads/public/cover.jpg']));
    // Без фото «Без фона» остаётся выбором редактора.
    assert_same('none', $save(['bg_type' => 'none', 'image' => '']));
    assert_same('none', $save(['bg_type' => 'none', 'image' => '   ']));
    assert_same('youtube', $save(['bg_type' => 'none', 'youtube_url' => 'https://www.youtube.com/watch?v=s_lKTkRGKc8']));
    assert_same('video', $save(['bg_type' => 'none', 'video_url' => '/uploads/public/hero.mp4']));
    // Видео-режимы не перебиваются постером.
    assert_same('video', $save(['bg_type' => 'video', 'image' => '/uploads/public/poster.jpg']));
    assert_same('youtube', $save(['bg_type' => 'youtube', 'image' => '/uploads/public/poster.jpg']));

    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/BlockData/HeroBlockNormalizer.php');
    assert_contains("Video::youtubeId(\$youtubeUrl) !== null", $src);
    assert_contains("elseif (\$bgType === 'none' && \$image !== '')", $src);

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
    assert_contains("bgSelect.value = 'youtube'", $js);
    assert_contains("target.matches('[name=\"youtube_url\"]')", $js);
});

test('Обложка: с фото рендерится медиа-вариант, без фото — заголовочная зона', function () {
    $withPhoto = BlockRenderer::render([
        'id' => 960, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'bg_type' => 'image', 'image' => '/uploads/public/cover.jpg']),
    ]);
    assert_contains('cover.jpg', $withPhoto['html'], 'фото должно попасть в разметку');
    assert_contains('block-hero--media', $withPhoto['html']);

    // Явный выбор «Без фона» шаблон уважает, даже если в поле осталось фото:
    // иначе у тех, кто сознательно убрал фон, снимок вернулся бы сам собой.
    // Создать такое состояние заново больше нельзя — сохранение переключает
    // тип на «Фото» (см. тест выше); блоки, сохранённые до правки, чинятся
    // повторным сохранением.
    $explicitNone = BlockRenderer::render([
        'id' => 961, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'bg_type' => 'none', 'image' => '/uploads/public/cover.jpg']),
    ]);
    assert_not_contains('cover.jpg', $explicitNone['html']);

    // На чистой схеме отсутствующий тип равен «Без фона»: скрытое поле
    // изображения не должно неожиданно менять внешний вид.
    $withoutType = BlockRenderer::render([
        'id' => 962, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'image' => '/uploads/public/cover.jpg']),
    ]);
    assert_not_contains('cover.jpg', $withoutType['html']);
    assert_not_contains('block-hero--media', $withoutType['html']);
});
