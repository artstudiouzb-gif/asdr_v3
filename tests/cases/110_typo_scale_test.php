<?php

declare(strict_types=1);

use App\Core\DesignSettings;
use App\Models\Setting;

test('Шкала типографики: по умолчанию не вмешивается в тему', function () {
    Setting::overrideInMemory('design_typo_scale', '');
    // На работающем сайте включение шкалы поменяло бы все заголовки разом —
    // поэтому «как в теме» остаётся значением по умолчанию.
    assert_same('theme', DesignSettings::typoScale());
    assert_same([], DesignSettings::scaleSizes());
    assert_same('', DesignSettings::typographyCss(), 'без шкалы CSS-правил быть не должно');
});

test('Шкала типографики: иерархия строго убывает при любом коэффициенте', function () {
    Setting::overrideInMemory('design_font_size_custom', '16px');

    foreach (['compact', 'classic', 'expressive'] as $scale) {
        Setting::overrideInMemory('design_typo_scale', $scale);
        $sizes = DesignSettings::scaleSizes();

        $order = ['fs_h1', 'fs_h2', 'fs_h3', 'fs_h4', 'fs_h5', 'fs_h6'];
        $previous = PHP_INT_MAX;
        foreach ($order as $key) {
            $value = (int) rtrim($sizes[$key], 'px');
            assert_true($value > 0, "{$scale}: пустой размер {$key}");
            assert_true($value < $previous, "{$scale}: {$key} не мельче предыдущего — иерархия сломана");
            $previous = $value;
        }
        // Даже самая младшая ступень остаётся крупнее основного текста.
        assert_true((int) rtrim($sizes['fs_h6'], 'px') > 16, "{$scale}: H6 не крупнее основного текста");
        // Дробных размеров быть не должно: они и создают ощущение хаоса.
        foreach ($sizes as $key => $value) {
            assert_false(str_contains($value, '.'), "{$scale}: дробный размер у {$key} — {$value}");
        }
    }
});

test('Шкала типографики: считается от базового размера текста', function () {
    Setting::overrideInMemory('design_typo_scale', 'classic');

    Setting::overrideInMemory('design_font_size_custom', '16px');
    $small = (int) rtrim(DesignSettings::scaleSizes()['fs_h1'], 'px');
    Setting::overrideInMemory('design_font_size_custom', '20px');
    $large = (int) rtrim(DesignSettings::scaleSizes()['fs_h1'], 'px');

    assert_true($large > $small, 'крупнее базовый текст — крупнее заголовки');
});

test('Шкала типографики: ручной размер важнее шкалы', function () {
    Setting::overrideInMemory('design_typo_scale', 'classic');
    Setting::overrideInMemory('design_font_size_custom', '16px');
    Setting::overrideInMemory('design_fs_h1', '64px');

    $sizes = DesignSettings::typographySizes();
    assert_same('64px', $sizes['fs_h1'], 'заданное вручную значение не должно перебиваться шкалой');
    // Остальные ступени по-прежнему из шкалы.
    assert_same('39px', $sizes['fs_h2']);
    // В форме админки видно именно переопределение, а не итог.
    assert_same('64px', DesignSettings::typographyOverrides()['fs_h1']);
    assert_same('', DesignSettings::typographyOverrides()['fs_h2']);

    Setting::overrideInMemory('design_fs_h1', '');
});

test('Раздел дизайна: шкала и честные подписи шрифтов на месте', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/design/index.php');
    assert_contains('Шкала заголовков', $view);
    assert_contains('typo-preview', $view, 'наглядный предпросмотр ступеней');
    assert_contains('Точные размеры', $view, 'ручные значения остаются, но свёрнуты');
    // Редактор должен видеть, что выбранные шрифты локализуются на сервере.
    assert_contains('Локальные — без внешних запросов', $view);
    assert_contains('Каталог Google Fonts — локальные файлы', $view);
    assert_contains('Ўў, Ғғ, Ққ, Ҳҳ', $view);
    assert_not_contains('fonts.googleapis.com', $view);
});
