<?php

declare(strict_types=1);

use App\Core\DesignSettings;

/*
 * Настройки «Дизайна» обязаны доходить до страницы.
 *
 * Настройка печатает `--radius`, `--radius-sm` (0.6 от него) и `--btn-radius`,
 * но правило, где радиус написан числом, переменную не слушает. Замерено
 * в браузере до правки: на странице «Руководство» настройку не слушали
 * карточка сотрудника, фотография руководителя и блок образования, в ленте —
 * карточка новости, в каталоге — панель фильтров. Редактор двигал ползунок,
 * а половина страницы не менялась — это и читается как «настройки не
 * работают».
 *
 * Поэтому здесь два стража: у ключевых компонентов радиус берётся из
 * переменной, а общее число жёстких значений в публичном CSS может только
 * уменьшаться — тем же приёмом, что бюджеты `!important` и классов без правил.
 */

/*
 * Сбор правил (`public_radius_rules`) и отбор жёстких значений
 * (`public_hard_radius_rules`) объявлены в tests/budgets.php: по ним же считает
 * бюджет отчёт `php .claude/skills/quality-budgets/report.php`.
 */

test('Настройка «Скругление углов» печатает переменные', function () {
    $css = DesignSettings::cssVariables(['radius' => 'large', 'button' => 'rounded']);

    assert_contains('--radius:22px', $css);
    assert_contains('--radius-sm:calc(22px * .6)', $css);
    assert_contains('--btn-radius:', $css);

    $none = DesignSettings::cssVariables(['radius' => 'none']);
    assert_contains('--radius:0px', $none, 'вариант «Прямые» обязан давать ноль');
});

test('Ключевые карточки берут радиус из переменной', function () {
    $rules = public_radius_rules();

    // Компонент => селектор, ровно с которого начинается его правило.
    $required = [
        'карточка новости' => '.news-card',
        'карточка записи' => '.content-card',
        'карточка документа' => '.doc-card',
        'слайдер' => '.block-slider',
    ];

    foreach ($required as $title => $selector) {
        $own = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => $rule['selector'] === $selector
        ));
        assert_true($own !== [], $title . ': правило ' . $selector . ' не найдено — селектор переименовали?');
        foreach ($own as $rule) {
            assert_true(
                str_contains($rule['value'], 'var(--radius')
                || str_contains($rule['value'], 'var(--btn-radius'),
                $title . ' (' . $rule['file'] . ') не слушает настройку: ' . $rule['value']
            );
        }
    }
});

test('Бюджет жёстких скруглений в публичном CSS только уменьшается', function () {
    // Круги, пилюли и нули настройкой не управляются: это форма элемента,
    // а не оформление карточки, — отбор живёт в public_hard_radius_rules().
    $budget = quality_budget('public_hard_radius');

    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'жёстких значений border-radius стало больше бюджета: '
        . $budget['value'] . ' > ' . $budget['ceiling']
        . '; новое правило должно брать var(--radius), var(--radius-sm) или var(--btn-radius)'
        . '. Например: ' . $budget['detail']
    );
});

test('«Плотность секций» меняет вертикальный ритм', function () {
    // До правки настройка не действовала вовсе: блок выводится с классом
    // `cms-block--space-<пресет>`, а тот берёт отступ из --space-*, минуя
    // --section-pad, куда плотность и печаталась. Значение подменяем в памяти
    // запроса — в БД тест не пишет и соседние сценарии не задевает.
    \App\Models\Setting::overrideInMemory('design_density', 'compact');
    $compact = DesignSettings::semanticSpacings();
    \App\Models\Setting::overrideInMemory('design_density', 'spacious');
    $spacious = DesignSettings::semanticSpacings();
    \App\Models\Setting::overrideInMemory('design_density', 'standard');
    $standard = DesignSettings::semanticSpacings();

    assert_true($compact !== $spacious, 'плотность не меняет отступы секций');
    assert_true($compact !== $standard, '«Компактно» совпало со «Стандартом»');
    // «Стандарт» обязан совпадать с прежними значениями: сайт, где настройку
    // не трогали, от этой правки меняться не должен.
    assert_same('clamp(28px, 4vw, 56px)', $standard['space_premium']);
});

test('Заголовки берут межстрочный интервал из настройки', function () {
    // Тема грузится после базы, и жёсткое line-height в её общем правиле
    // заголовков перекрывало переменную: замерено, что настройку слушал один
    // заголовок из двадцати семи.
    $theme = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/gov-theme.css');
    $rule = '';
    if (preg_match('/h1, h2, h3, h4, h5, h6,.*?\{(.*?)\}/s', $theme, $m) === 1) {
        $rule = $m[1];
    }
    assert_true($rule !== '', 'общее правило заголовков темы не найдено');
    assert_contains('var(--heading-line-height', $rule);
    assert_contains('var(--heading-font-weight', $rule);
    assert_contains('var(--heading-letter-spacing', $rule);
});
