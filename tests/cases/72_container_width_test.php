<?php

declare(strict_types=1);

use App\Core\DesignSettings;

// Ширина контейнера: шкала 1200 / 1440 / 1500 / 1700; full и своя точная ширина сохраняются.

test('DesignSettings::normalizeWidth: валидные и невалидные значения', function () {
    assert_same('1440px', DesignSettings::normalizeWidth('1440px'));
    assert_same('90%', DesignSettings::normalizeWidth('90%'));
    assert_same('72rem', DesignSettings::normalizeWidth('72rem'));
    assert_same('1440px', DesignSettings::normalizeWidth('1440'), 'число трактуется как px');
    assert_same('', DesignSettings::normalizeWidth('300'), 'слишком узко — отбрасывается');
    assert_same('', DesignSettings::normalizeWidth('2500'), 'слишком широко — отбрасывается');
    assert_same('', DesignSettings::normalizeWidth('abc'));
    assert_same('', DesignSettings::normalizeWidth(''));
});

test('DesignSettings::cssVariables: пресеты ultra и full', function () {
    $base = ['container' => 'standard', 'radius' => 'small', 'card_gap' => 'sm', 'density' => 'standard', 'button' => 'rounded', 'card_style' => 'soft'];

    $ultra = DesignSettings::cssVariables(['container' => 'ultra'] + $base);
    assert_true(str_contains($ultra, '--container-max:1700px'), 'ultra = 1700px');

    $standard = DesignSettings::cssVariables($base);
    assert_true(str_contains($standard, '--container-max:1440px'), 'standard = 1440px');
    $wide = DesignSettings::cssVariables(['container' => 'wide'] + $base);
    assert_true(str_contains($wide, '--container-max:1500px'), 'wide = 1500px');

    $full = DesignSettings::cssVariables(['container' => 'full'] + $base);
    assert_true(str_contains($full, '--container-max:none'), 'full = none (во всю ширину)');
});
