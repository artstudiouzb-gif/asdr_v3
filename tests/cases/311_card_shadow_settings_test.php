<?php

declare(strict_types=1);

use App\Core\DesignSettings;
use App\Models\Setting;

/*
 * Цвет и сила тени карточек.
 *
 * «Стиль карточек» выбирал форму тени, а сам цвет был зашит тремя строками
 * `rgba(16,24,40,…)`: на тёплой или тёмной палитре холодная серо-синяя тень
 * читается грязным пятном, и поменять её было нечем. Теперь форма приходит
 * из «Стиля карточек», а цвет и сила — из своих настроек.
 */

test('Умолчания повторяют прежнюю зашитую тень', function () {
    Setting::overrideInMemory('design_shadow_color', '');
    Setting::overrideInMemory('design_shadow_strength', '');

    assert_same('none', DesignSettings::cardShadow('flat'));
    assert_same(
        '0 1px 3px rgba(16,24,40,0.06), 0 6px 18px rgba(16,24,40,0.05)',
        DesignSettings::cardShadow('soft'),
        'мягкая тень разошлась с прежним значением'
    );
    assert_same('0 10px 30px rgba(16,24,40,0.12)', DesignSettings::cardShadow('elevated'));
});

test('Цвет и сила тени доходят до переменной --card-shadow', function () {
    Setting::overrideInMemory('design_shadow_color', '#7a4b00');
    Setting::overrideInMemory('design_shadow_strength', '180');

    $shadow = DesignSettings::cardShadow('soft');
    assert_contains('rgba(122,75,0,', $shadow, 'цвет тени не применился');
    assert_contains('0.108', $shadow, 'сила тени не умножила прозрачность');

    $css = DesignSettings::cssVariables(['card_style' => 'soft']);
    assert_contains('--card-shadow:0 1px 3px rgba(122,75,0,', $css);

    // Ноль — это «тени нет вовсе», а не прозрачная тень в разметке.
    Setting::overrideInMemory('design_shadow_strength', '0');
    assert_same('none', DesignSettings::cardShadow('elevated'));

    Setting::overrideInMemory('design_shadow_color', '');
    Setting::overrideInMemory('design_shadow_strength', '');
});

test('Мусор в настройках тени откатывается к умолчанию', function () {
    Setting::overrideInMemory('design_shadow_color', 'красный');
    assert_same(DesignSettings::SHADOW_COLOR_DEFAULT, DesignSettings::shadowColor());

    Setting::overrideInMemory('design_shadow_strength', 'много');
    assert_same(100, DesignSettings::shadowStrength(), 'нечисловая сила — прежнее поведение');

    // Предел сверху: дальше тень перестаёт быть тенью и становится заливкой.
    Setting::overrideInMemory('design_shadow_strength', '9000');
    assert_same(300, DesignSettings::shadowStrength());

    Setting::overrideInMemory('design_shadow_strength', '-40');
    assert_same(0, DesignSettings::shadowStrength());

    Setting::overrideInMemory('design_shadow_color', '');
    Setting::overrideInMemory('design_shadow_strength', '');
});

test('Обе настройки есть в форме «Дизайна» и в живом превью', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/design/index.php');
    assert_contains('name="shadow_color"', $view);
    assert_contains('name="shadow_strength"', $view);

    // Живое превью примеряет значения без сохранения — иначе редактор видит
    // прежнюю тень и думает, что настройка не работает.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DesignController.php');
    assert_contains("'design_shadow_color'", $controller);
    assert_contains("'design_shadow_strength'", $controller);
});
