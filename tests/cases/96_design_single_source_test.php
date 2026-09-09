<?php

declare(strict_types=1);

use App\Core\DesignSettings;

test('цвета и шрифты редактируются только в разделе дизайна', function (): void {
    $settingsView = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/settings/index.php');
    $designView = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/design/index.php');

    assert_true(is_string($settingsView));
    assert_true(is_string($designView));
    assert_not_contains('name="color_primary"', $settingsView);
    assert_not_contains('name="color_accent"', $settingsView);
    assert_not_contains('name="font_family"', $settingsView);
    assert_not_contains('name="default_theme"', $settingsView);
    assert_contains('Открыть управление дизайном', $settingsView);

    assert_contains('name="color_primary"', $designView);
    assert_contains('name="color_accent"', $designView);
    assert_contains("'bg_primary'", $designView);
    assert_contains("'bg_surface'", $designView);
    assert_contains("'text_main'", $designView);
    assert_contains("'text_muted'", $designView);
    assert_contains("'border_color'", $designView);
    assert_contains("'space_small'", $designView);
    assert_contains("'space_premium'", $designView);
    assert_contains("'space_max'", $designView);
    assert_contains('name="font_family"', $designView);
    assert_contains('name="font_face_name"', $designView);
    assert_contains('name="font_url"', $designView);
    assert_contains('name="default_theme"', $designView);
});

test('ручное оформление сохраняется отдельно и возвращается после пресета', function (): void {
    ensure_test_db();

    DesignSettings::save([
        'palette' => 'custom',
        'font_style' => 'custom',
        'color_primary' => '#102030',
        'color_accent' => '#40a0b0',
        'font_family' => "'Brand Sans', system-ui, sans-serif",
        'default_theme' => 'auto',
        'bg_primary' => '#fafafa',
        'bg_surface' => '#f0f2f5',
        'text_main' => '#202124',
        'text_muted' => '#62666d',
        'border_color' => '#d8dce2',
        'space_small' => '15px',
        'space_premium' => '30px',
        'space_max' => '60px',
    ]);
    assert_same('#102030', \App\Models\Setting::get('color_primary'));
    assert_same('#40a0b0', \App\Models\Setting::get('color_accent'));
    assert_contains('Brand Sans', (string) \App\Models\Setting::get('font_family'));
    assert_same('auto', \App\Models\Setting::get('default_theme'));
    assert_same('#fafafa', DesignSettings::semanticColors()['bg_primary']);
    assert_same('#f0f2f5', DesignSettings::semanticColors()['bg_surface']);
    assert_same('#202124', DesignSettings::semanticColors()['text_main']);
    assert_same('#62666d', DesignSettings::semanticColors()['text_muted']);
    assert_same('#d8dce2', DesignSettings::semanticColors()['border_color']);
    assert_same('15px', DesignSettings::semanticSpacings()['space_small']);
    assert_same('30px', DesignSettings::semanticSpacings()['space_premium']);
    assert_same('60px', DesignSettings::semanticSpacings()['space_max']);

    DesignSettings::save(['palette' => 'gov_blue', 'font_style' => 'system']);
    // Ожидание из определения палитры: проверяется перенос цвета в настройки,
    // а не конкретный оттенок, который дизайн вправе менять.
    assert_same(DesignSettings::PALETTES['gov_blue'][1], \App\Models\Setting::get('color_primary'));

    DesignSettings::save(['palette' => 'custom', 'font_style' => 'custom']);
    assert_same('#102030', \App\Models\Setting::get('color_primary'));
    assert_same('#40a0b0', \App\Models\Setting::get('color_accent'));
    assert_contains('Brand Sans', (string) \App\Models\Setting::get('font_family'));
    assert_same('#fafafa', DesignSettings::semanticColors()['bg_primary']);
});

test('семантические цвета выводятся как переменные общей и государственной темы', function (): void {
    $themeCss = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/SiteThemeCss.php');
    foreach (['--bg-primary', '--bg-surface', '--text-main', '--text-muted', '--border-color'] as $variable) {
        assert_contains($variable, $themeCss);
    }
    foreach (['--space-small', '--space-premium', '--space-max'] as $variable) {
        assert_contains($variable, $themeCss);
    }
    assert_contains("'--gov-bg' => 'var(--bg-primary)'", $themeCss);
    assert_contains("'--gov-surface' => 'var(--bg-surface)'", $themeCss);
    assert_contains("'--gov-ink' => 'var(--text-main)'", $themeCss);
    assert_contains('SiteThemeCss::build', (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Views/site/_header.php'
    ));
});
