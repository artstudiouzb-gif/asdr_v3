<?php

declare(strict_types=1);

/*
 * Цвет текста внутри блоков редакционной страницы.
 *
 * `public-editorial-pages.css` подключается ПОСЛЕ общего бандла, поэтому его
 * правила поднимаются в каскаде выше компонентных. Правило вида
 * `.editorial-page__content > .cms-block--text .block-text__content { color:
 * var(--gov-ink) }` весит (0,3,0) и побеждает `:where(.rich-content)` с нулём —
 * текст переставал слушать цвет своей секции.
 *
 * На тёмной секции это давало 1.00:1: абзац красился ровно в цвет фона и
 * пропадал совсем. Поэтому цвет секции идёт первым, а прежний токен остаётся
 * запасным — вне тёмных секций переменная не задана, и вид не меняется.
 *
 * Шапка страницы и хлебные крошки — не секции: там цвет свой, и `--section-*`
 * им не адресован (в `:root` он разрешается в цвет темы).
 */

test('Текст внутри блоков редакционной страницы слушает цвет секции', function () {
    $file = APP_ROOT . '/public/assets/css/public-editorial-pages.css';
    $lines = explode("\n", (string) file_get_contents($file));

    // Токены темы, которыми красили текст мимо секции.
    $bare = ['var(--gov-ink)', 'var(--gov-title)', 'var(--editorial-muted)'];

    $offenders = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\s*color:\s*(.+);\s*$/', $line, $m)) {
            continue;
        }
        $value = trim($m[1]);
        if (!in_array($value, $bare, true)) {
            continue;
        }

        // Селектор правила — ближайшая строка с «{» выше, вместе с
        // перечислением через запятую над ней.
        $j = $i;
        while ($j > 0 && !str_contains($lines[$j], '{')) {
            $j--;
        }
        $start = $j;
        while ($start > 0 && str_ends_with(trim($lines[$start - 1]), ',')) {
            $start--;
        }
        $selector = trim(str_replace('{', '', implode(' ', array_slice($lines, $start, $j - $start + 1))));

        // Внутри блока страницы — обязан спрашивать секцию. Шапка и крошки
        // живут выше блоков, у них цвет свой.
        if (str_contains($selector, '__content') || str_contains($selector, '.cms-block')) {
            $offenders[] = 'строка ' . ($i + 1) . ': ' . mb_substr($selector, 0, 80) . ' → ' . $value;
        }
    }

    assert_same(
        [],
        $offenders,
        "цвет текста внутри блока задан мимо секции — на тёмной секции он пропадёт:\n  " . implode("\n  ", $offenders)
    );
});

test('Цвет секции идёт первым, а прежний токен остаётся запасным', function () {
    // Без запасного значения светлая страница потеряла бы свой цвет: вне
    // тёмных секций --section-* не задан.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    foreach ([
        'var(--section-fg, var(--gov-ink))',
        'var(--section-title-fg, var(--gov-title))',
        'var(--section-muted-fg, var(--editorial-muted))',
    ] as $expected) {
        assert_contains($expected, $css, 'потерян запасной цвет: ' . $expected);
    }

    // Шапка страницы не спрашивает цвет секции — она вне блоков.
    assert_true(
        preg_match('/\.content-pagehead--editorial \.content-pagehead__lead \{[^}]*color: var\(--editorial-muted\)/s', $css) === 1,
        'лид шапки страницы должен оставаться со своим цветом'
    );
});
