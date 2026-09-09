<?php

declare(strict_types=1);

use App\Core\DesignSettings;
use App\Core\HeaderConfig;

/**
 * Оформление «из коробки»: то, что видит владелец сразу после установки,
 * до единой настройки. Значения выбраны под фирменный стиль Агентства.
 */

test('По умолчанию: широкий контейнер и кнопки-капсулы', function () {
    // Умолчание — «Стандарт»: после пересборки шкалы «Очень широкий» стал
    // 1700px, и прежнее умолчание молча расширило бы готовые сайты.
    assert_same('standard', DesignSettings::OPTIONS['container']['default']);
    assert_same('pill', DesignSettings::OPTIONS['button']['default']);

    $css = DesignSettings::cssVariables(['container' => 'standard', 'button' => 'pill']);
    assert_contains('--container-max:1440px', $css);
    assert_contains('--btn-radius:999px', $css);
});

test('По умолчанию: шапка липкая, прозрачная и с тенью', function () {
    assert_true(HeaderConfig::DEFAULTS['sticky'], 'липкая шапка');
    // Прозрачность работает только там, где страница её просит
    // (pages.transparent_header) — то есть на главной.
    assert_true(HeaderConfig::DEFAULTS['transparent'], 'прозрачная шапка');
    assert_true(HeaderConfig::DEFAULTS['shadow']['enabled'], 'тень под шапкой');
});

test('По умолчанию: цвета и шрифты фирменные', function () {
    $theme = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/SiteThemeCss.php');

    // Палитра из утверждённой концепции дизайна: тёмно-синий и бирюзовый.
    assert_contains("Setting::get('color_primary', '#0F2B46')", $theme);
    assert_contains("Setting::get('color_accent', '#009BBE')", $theme);
    // Пара по умолчанию: Noto Sans — текст, Noto Serif — заголовки. Только
    // они и лежат локально. Умолчания объявлены ровно в одном месте: раньше
    // SiteThemeCss и шапка задавали разные семейства, и сайт предзагружал
    // шрифты, которыми ничего не набрано.
    assert_contains("DEFAULT_BODY_FONT = \"'Noto Sans'", $theme);
    assert_contains("DEFAULT_HEADING_FONT = \"'Noto Serif'", $theme);

    $header = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    assert_contains('SiteThemeCss::fontStacks()', $header, 'шапка берёт стек из общего источника');
    assert_not_contains("Setting::get('font_family'", $header, 'второго умолчания в шапке быть не должно');

    // Расширенная кириллица обязательна: узбекские Ғ Қ Ҳ лежат в cyrillic-ext.
    foreach ([
        'noto-sans/noto-sans-var-cyrillic.woff2',
        'noto-sans/noto-sans-var-cyrillic-ext.woff2',
        'noto-serif/noto-serif-var-cyrillic.woff2',
        'noto-serif/noto-serif-var-cyrillic-ext.woff2',
    ] as $file) {
        assert_true(
            is_file(dirname(__DIR__, 2) . '/public/assets/fonts/' . $file),
            "шрифт {$file} должен лежать в проекте, а не грузиться со стороны"
        );
    }
});

test('Расширенная кириллица покрыта: узбекские буквы попадают в подключаемое подмножество', function () {
    // Проверяем по существу, а не по строке диапазона: файл cyrillic-ext
    // обрезан со всей исторической кириллицы до современных тюркских букв,
    // поэтому дословный диапазон Google здесь уже не встречается.
    //
    // Узбекские Ғғ Ққ Ҳҳ и казахские Әә Ңң Өө Үү Һһ. Без них кириллица
    // рисуется системным шрифтом вперемешку с основным.
    $letters = [
        'Ғ' => 0x0492, 'ғ' => 0x0493, 'Қ' => 0x049A, 'қ' => 0x049B,
        'Ҳ' => 0x04B2, 'ҳ' => 0x04B3, 'Ә' => 0x04D8, 'Ң' => 0x04A2,
        'Ө' => 0x04E8, 'Ү' => 0x04AE, 'Һ' => 0x04BA,
    ];

    foreach (['noto-sans', 'noto-serif'] as $slug) {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/' . $slug . '.css');
        assert_true(
            preg_match('/\/\* cyrillic-ext \*\/\s*@font-face\s*\{[^}]*unicode-range:\s*([^;]+);/s', $css, $m) === 1,
            $slug . ': не объявлено подмножество cyrillic-ext'
        );

        $covered = static function (int $code) use ($m): bool {
            foreach (explode(',', $m[1]) as $part) {
                $part = trim($part);
                if (preg_match('/^U\+([0-9A-Fa-f]+)-([0-9A-Fa-f]+)$/', $part, $range) === 1) {
                    if ($code >= hexdec($range[1]) && $code <= hexdec($range[2])) {
                        return true;
                    }
                } elseif (preg_match('/^U\+([0-9A-Fa-f]+)$/', $part, $single) === 1
                    && $code === (int) hexdec($single[1])) {
                    return true;
                }
            }

            return false;
        };

        foreach ($letters as $letter => $code) {
            assert_true($covered($code), $slug . ": буква {$letter} вне объявленного диапазона");
        }
    }
});

test('Схема не подменяет фирменные цвета своими', function () {
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

    // Раньше установка сеяла чёрный и красный, и дефолты из кода не работали.
    assert_not_contains("('color_primary'", $schema);
    assert_not_contains("('color_accent'", $schema);
    assert_not_contains("('font_family'", $schema);
});
