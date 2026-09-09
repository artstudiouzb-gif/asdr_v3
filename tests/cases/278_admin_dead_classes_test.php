<?php

declare(strict_types=1);

/**
 * Классы разметки админки, которых нет в CSS.
 *
 * Такой класс не ломает страницу и потому живёт годами: `.btn--success` рисовал
 * обычную серую кнопку, `.badge--warning` — нейтральную пометку, `.admin-grid`
 * не давал сетки, а `.status-badge--published` и `.status-badge--draft` красили
 * «Активен» и «Отписан» одинаково. Разом нашлось 52 таких класса.
 *
 * Тест держит бюджет: остаток — классы-хуки и дубли, у которых оформление даёт
 * соседний класс. Число может только уменьшаться; новый класс без правила
 * роняет тест.
 */

/*
 * `admin_markup_classes()` и `admin_orphan_classes()` объявлены в
 * tests/budgets.php — там же лежит потолок, и отчёт по бюджетам считает
 * ровно то же самое.
 */

test('Классы админки описаны в CSS', function () {
    $css = '';
    foreach (admin_css_files() as $file) {
        $css .= (string) file_get_contents($file);
    }
    assert_true(strlen($css) > 100000, 'CSS админки прочитан');

    $used = admin_markup_classes();
    assert_true(count($used) > 300, 'классы разметки собраны (найдено ' . count($used) . ')');

    $budget = quality_budget('admin_dead_classes');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'классов без правил не больше ' . $budget['ceiling']
            . ' (сейчас ' . $budget['value'] . ': ' . $budget['detail'] . ')'
    );
});

/** Относительная яркость по WCAG. */
function admin_css_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $parts = [];
    foreach ([0, 2, 4] as $offset) {
        $value = (int) hexdec(substr($hex, $offset, 2)) / 255;
        $parts[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
}

test('Статусные бейджи читаемы в светлой теме', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');

    // Тёмный набор объявлен с отступом внутри @media — берём только правила
    // верхнего уровня, они и действуют в светлой панели.
    preg_match_all(
        '/^\.status-badge--[a-z-]+(?:,\s*\.status-badge--[a-z-]+)*\s*\{([^}]*)\}/m',
        $css,
        $matches,
        PREG_SET_ORDER
    );
    assert_true(count($matches) >= 5, 'светлые правила статусных бейджей найдены (' . count($matches) . ')');

    foreach ($matches as $rule) {
        // Полупрозрачная заливка проверке не поддаётся: под ней может быть
        // любая поверхность. Светлый набор обязан быть сплошным цветом —
        // прежний rgba(…, .12) со светлым текстом и был причиной 2:1.
        $ok = preg_match('/background:\s*(#[0-9a-f]{6})/i', $rule[1], $bg)
            && preg_match('/color:\s*(#[0-9a-f]{6})/i', $rule[1], $fg);
        assert_true($ok === 1 || $ok === true, 'светлый набор задан сплошными цветами: ' . trim($rule[0]));
        $light = admin_css_luminance($bg[1]);
        $dark = admin_css_luminance($fg[1]);
        [$light, $dark] = $light >= $dark ? [$light, $dark] : [$dark, $light];
        $ratio = ($light + 0.05) / ($dark + 0.05);
        assert_true(
            $ratio >= 4.5,
            'контраст ' . $fg[1] . ' на ' . $bg[1] . ' не ниже 4.5:1 (сейчас ' . round($ratio, 2) . ':1)'
        );
    }
});
