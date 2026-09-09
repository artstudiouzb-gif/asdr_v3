<?php

declare(strict_types=1);

use App\Core\DesignSettings;

/*
 * Палитра и панель «Настройки отображения».
 *
 * Слой «Дизайна» печатает свои семантические цвета в :root после темы, то есть
 * перекрывает её токены. Пока значения расходились, тема со своей холодной
 * шкалой не действовала ни на одном сайте: нейтральный серый текст рядом с
 * синими заголовками читается как грязный, а подписи были тусклыми — 5.74:1
 * против 7.58:1. Поэтому умолчания обязаны совпадать с токенами темы.
 *
 * Панель — второй сюжет: тринадцать кнопок с рамками, плашки под иконками и
 * кольцо-свечение у выбранного превращали её в решётку. Признак выбора
 * должен быть один.
 */

/** Контраст по WCAG 2.x для двух непрозрачных цветов. */
$contrast = static function (string $a, string $b): float {
    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $out = 0.0;
        foreach ([[0, 0.2126], [2, 0.7152], [4, 0.0722]] as [$offset, $weight]) {
            $v = hexdec(substr($hex, $offset, 2)) / 255;
            $out += $weight * ($v <= 0.03928 ? $v / 12.92 : ((($v + 0.055) / 1.055) ** 2.4));
        }

        return $out;
    };
    $one = $lum($a);
    $two = $lum($b);
    $hi = max($one, $two);
    $lo = min($one, $two);

    return round(($hi + 0.05) / ($lo + 0.05), 2);
};

test('Палитра «Дизайна» совпадает с токенами темы', function () {
    $theme = (string) file_get_contents(__DIR__ . '/../../public/assets/css/gov-theme.css');
    $rootAt = (int) strpos($theme, ':root {');
    $root = substr($theme, $rootAt, (int) strpos($theme, "\n}", $rootAt) - $rootAt);
    $token = static function (string $name) use ($root): string {
        preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $root, $m);

        return strtolower(trim($m[1] ?? ''));
    };

    $colors = DesignSettings::semanticColors();

    assert_same($token('--gov-bg'), strtolower($colors['bg_primary']), 'фон страницы разошёлся с темой');
    assert_same($token('--gov-surface'), strtolower($colors['bg_surface']), 'поверхность разошлась с темой');
    assert_same($token('--gov-ink'), strtolower($colors['text_main']), 'основной текст разошёлся с темой');
    assert_same($token('--gov-muted'), strtolower($colors['text_muted']), 'подписи разошлись с темой');
    assert_same($token('--gov-border'), strtolower($colors['border_color']), 'граница разошлась с темой');
});

test('Текст и подписи держат контраст с запасом', function () use ($contrast) {
    $colors = DesignSettings::semanticColors();

    $main = $contrast($colors['text_main'], $colors['bg_surface']);
    $muted = $contrast($colors['text_muted'], $colors['bg_surface']);
    $mutedOnPage = $contrast($colors['text_muted'], $colors['bg_primary']);

    assert_true($main >= 15.0, 'основной текст ' . $main . ':1 — ниже ожидаемого');
    // Подпись — не «серая по вкусу»: 4.5:1 это минимум AA, а на госсайте
    // вторичный текст читают так же часто, как основной. Прежние #666666
    // давали 5.74:1 и выглядели тусклыми.
    assert_true($muted >= 7.0, 'подписи ' . $muted . ':1 — тусклее AAA');
    assert_true($mutedOnPage >= 7.0, 'подписи на фоне страницы ' . $mutedOnPage . ':1');
});

test('Панель «Настройки отображения» не носит лишних рамок и подложек', function () {
    $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/a11y.css');

    // Плитка выбора: рамка прозрачная в покое — цвет добавляет выбор, а не
    // толщину, иначе геометрия прыгает и панель читается решёткой.
    assert_true(
        (bool) preg_match('/\.a11y-choice\s*\{[^}]*border:\s*1\.5px solid transparent/s', $css),
        'у плитки выбора снова рамка в покое'
    );
    assert_true(
        (bool) preg_match('/\.a11y-choice\s*\{[^}]*background:\s*transparent/s', $css),
        'плитка выбора снова на своей подложке'
    );
    // Признак выбора один: заливка и рамка акцента. Кольцо-свечение
    // добавляло третий способ сказать то же самое.
    assert_false(
        (bool) preg_match('/\.a11y-choice\[aria-pressed="true"\]\s*\{[^}]*box-shadow/s', $css),
        'у выбранной плитки вернулось свечение'
    );
    // Строка тумблера — строка списка, а не карточка: состояние показывает
    // свитч справа.
    assert_true(
        (bool) preg_match('/\.a11y-toggle-btn\s*\{[^}]*border:\s*0/s', $css),
        'строки тумблеров снова в рамках'
    );
    assert_false(
        (bool) preg_match('/\.a11y-toggle-btn__icon\s*\{[^}]*background:/s', $css),
        'под иконкой тумблера вернулась плашка'
    );
    // Панель стоит на поверхности, а не на сером фоне страницы: иначе белые
    // элементы внутри читаются как заплатки.
    assert_true(
        (bool) preg_match('/\.a11y-drawer\s*\{[^}]*background:\s*var\(--bg-surface/s', $css),
        'панель снова на фоне страницы'
    );
    // Узкий экран: дорожки сетки должны уметь сжиматься, иначе плитки
    // уезжают за правый край (замерено на 320px).
    assert_contains('repeat(4, minmax(0, 1fr))', $css);
    assert_contains('repeat(2, minmax(0, 1fr))', $css);
});
