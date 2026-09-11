<?php

declare(strict_types=1);

test('visual refinement unifies section headings without changing markup', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');

    assert_contains('[data-visual-system] .section-head__title::before', $css);
    assert_contains('background: var(--gov-teal);', $css);
});

test('text-wrap: balance не применяется без разобранной причины', function (): void {
    // Запрет выведен из замера на СЕТКЕ карточек: браузер разносил заголовок
    // по строкам, исходя из его собственной ширины, и соседние карточки ряда
    // ломались в разных местах — ряд читался неровным. Ширину строки там
    // задаёт колонка. `text-wrap: pretty` остаётся: он убирает висячее слово,
    // а не двигает перенос.
    //
    // Проверка читает ВЕСЬ публичный CSS, а не один файл. Пока она сидела
    // внутри public-layout-polish.css, приём спокойно жил в слое главной и в
    // стилях ленты — то есть правило было, а нарушения лежали рядом с ним.
    // А заголовку, который на экране один, приём не вредит вовсе: такие случаи
    // разобраны поимённо в TEXT_WRAP_BALANCE_BY_DESIGN.
    $budget = quality_budget('text_wrap_balance');
    assert_true(
        $budget['value'] === 0,
        sprintf(
            'перенос отдан браузеру там, где решение не объяснено: %s. '
            . 'В сетке карточек ширину строки задаёт колонка; если заголовок на экране '
            . 'один, добавьте селектор в TEXT_WRAP_BALANCE_BY_DESIGN с причиной.',
            $budget['detail']
        )
    );
});

test('editorial media treatment excludes portraits and respects forced colors', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');

    assert_contains('--editorial-media-filter: saturate(.9) contrast(1.025);', $css);
    assert_contains('.news-card__cover,', $css);
    assert_contains('.project-card__media,', $css);
    assert_contains('@media (forced-colors: active)', $css);
    assert_contains(') img { filter: none; }', $css);
});

test('visual refinement uses a component scope instead of blocking the homepage', function (): void {
    $root = APP_ROOT;
    $css = (string) file_get_contents($root . '/public/assets/css/public-layout-polish.css');
    $header = (string) file_get_contents($root . '/app/Views/site/_header.php');
    $page = (string) file_get_contents($root . '/app/Views/site/page.php');

    assert_not_contains('body:not(.is-home)', $css);
    assert_not_contains("' is-home'", $header);
    assert_contains('data-visual-system', $header);
    // Главная больше не исключение: пока система на ней не действовала, один и
    // тот же блок выглядел на главной иначе, чем во внутреннем разделе — у
    // заголовков секций пропадала акцентная полоска. Область по-прежнему
    // задаётся атрибутом, а не классом body — ради этого тест и писался.
    assert_contains('$visualSystemScope = true;', $page);
    assert_contains('[data-visual-system] .section-head', $css);
    assert_contains('[data-visual-system] :where(', $css);
});

test('разобранные случаи text-wrap: balance не протухают', function (): void {
    // Список исключений опаснее запрета: правило из него уходит вместе с
    // правкой CSS, а строка с причиной остаётся и продолжает разрешать то,
    // чего уже нет. Тогда следующий такой же случай пройдёт молча — по
    // обоснованию, написанному для другого селектора.
    $live = text_wrap_balance_selectors();
    foreach (TEXT_WRAP_BALANCE_BY_DESIGN as $selector => $why) {
        assert_true(
            isset($live[$selector]),
            sprintf('в CSS больше нет `%s` — строку из TEXT_WRAP_BALANCE_BY_DESIGN надо убрать', $selector)
        );
        assert_true(
            trim($why) !== '',
            sprintf('у `%s` не написана причина, по которой перенос отдан браузеру', $selector)
        );
    }
});
