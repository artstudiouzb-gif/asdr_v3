<?php

declare(strict_types=1);

use App\Core\Hero\HeroSlideData;

test('Hero: custom artwork size is normalized', function (): void {
    $data = HeroSlideData::normalize(['art_size' => 'custom', 'art_width' => '777']);
    assert_same('custom', $data['art_size']);
    assert_same(777, $data['art_width']);
});

test('Hero editor: one sizing system and translated action URLs', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/Hero/HeroRenderer.php');
    $layoutCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero-art-layout.css');
    $baseCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    $translations = (string) file_get_contents(APP_ROOT . '/app/Models/HeroSlideTranslation.php');
    $slide = (string) file_get_contents(APP_ROOT . '/app/Models/HeroSlide.php');
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_17_hero_translation_links.sql');

    assert_contains("'custom' => 'Свой размер'", $form, 'logo custom size');
    assert_contains('name="art_width"', $form, 'logo width field');
    assert_not_contains('Как у обложки', $form, 'ambiguous legacy wording removed');
    assert_contains('Использовать общую настройку обложки', $form, 'inheritance wording is explicit');

    assert_contains("'custom' => (int) \$d['art_width']", $renderer, 'renderer uses custom logo width');
    assert_not_contains('.hero__art--large img { max-height:', $baseCss, 'legacy max-height artwork sizing is removed');
    assert_not_contains('.hero__art--large img', $layoutCss, 'side-layout CSS does not duplicate artwork width presets');
    assert_contains("'large' => 360", $renderer, 'large artwork width has one renderer source');

    assert_contains('-- @post-schema', $migration, 'translation link migration runs after canonical schema');
    foreach (['cta_url', 'cta2_url', 'link_url'] as $field) {
        assert_contains("[{$field}]", $form, "translation UI includes {$field}");
        assert_contains("'{$field}'", $translations, "translation model stores {$field}");
        assert_contains("'{$field}' => '{$field}'", $slide, "display applies {$field}");
        assert_contains("ADD COLUMN {$field}", $migration, "migration adds {$field}");
    }
});
