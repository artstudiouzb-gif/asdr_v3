<?php

declare(strict_types=1);

use App\Core\DesignSettings;

/*
 * Тени: одно место объявления и голый var() в точках использования.
 *
 * Шкал теней было три — --shadow-1…4 в теме, --shadow-sm/md/lg и
 * --shadow-primary в базе, — и у точек использования копились запасные
 * значения, которые срабатывают только без :root, то есть никогда (тот же
 * довод, что у цвета в тесте 345 и у скругления в тесте 309). Объявления
 * живут в tokens.css: его подключают и страницы без темы (вход в портал).
 */

const SHADOW_TOKENS = [
    '--card-shadow', '--shadow-control', '--shadow-hover', '--shadow-overlay', '--shadow-modal',
];

/** @return array<string, string> */
function shadow_public_css_sources(): array
{
    $out = [];
    foreach (array_merge(
        (array) glob(APP_ROOT . '/public/assets/css/*.css'),
        (array) glob(APP_ROOT . '/public/assets/css/blocks/*.css')
    ) as $file) {
        $file = (string) $file;
        if (str_ends_with($file, '.min.css') || str_starts_with(basename($file), 'admin')) {
            continue;
        }
        $out[substr($file, strlen(APP_ROOT) + 1)] = (string) file_get_contents($file);
    }

    return $out;
}

test('Токены теней используются без запасных значений', function () {
    foreach (shadow_public_css_sources() as $path => $css) {
        foreach (SHADOW_TOKENS as $token) {
            assert_same(
                0,
                preg_match_all('/var\(\s*' . preg_quote($token, '/') . '\s*,/', $css),
                "$path: у var($token, …) есть запасное значение — число живёт в tokens.css"
            );
        }
    }
});

test('Токены теней объявлены один раз — в tokens.css', function () {
    $declared = [];
    foreach (shadow_public_css_sources() as $path => $css) {
        preg_match_all('/(?<![\w-])(--shadow-[a-z0-9]+|--card-shadow)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $decl) {
            $declared[$decl[1]][] = $path;
        }
    }
    foreach (SHADOW_TOKENS as $token) {
        assert_same(1, count($declared[$token] ?? []), "$token объявлен не один раз: " . implode('; ', $declared[$token] ?? ['нигде']));
        assert_contains('tokens.css', $declared[$token][0], "$token объявляется в tokens.css");
    }
});

test('Прежние шкалы теней сняты целиком', function () {
    // Три шкалы (--shadow-1…4, --shadow-sm/md/lg, --shadow-primary) сведены к
    // ролям. Оставленное старое имя означало бы, что шкал опять две.
    foreach (shadow_public_css_sources() as $path => $css) {
        assert_same(
            0,
            preg_match_all('/--shadow-(?:[1-4]|sm|md|lg|primary)\\b/', $css),
            "$path: осталось имя прежней шкалы теней — используйте роль"
        );
    }
});

test('Тень карточки по умолчанию совпадает с тем, что печатает «Дизайн»', function () {
    // Портал /repo слоя настроек не получает: там действует это число, и оно
    // обязано совпадать с «Мягкой» тенью при умолчаниях цвета и силы.
    reset_design_state();
    $tokens = (string) file_get_contents(APP_ROOT . '/public/assets/css/tokens.css');
    preg_match('/--card-shadow:\s*([^;]+);/', $tokens, $m);
    assert_same(DesignSettings::cardShadow('soft'), trim($m[1] ?? ''), '--card-shadow в tokens.css');
});
