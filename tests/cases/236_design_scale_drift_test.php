<?php

declare(strict_types=1);

/**
 * Сторож дрейфа дизайн-системы.
 *
 * Проблема, ради которой он существует: в публичной теме накопились 73 разных
 * статичных размера шрифта, 163 тени и 606 !important. Значения вроде
 * 0.74/0.75/0.76/0.78rem — не решения, а следы ручной подгонки отдельных
 * компонентов. Когда общего словаря нет, единственный способ победить
 * соседнее правило — поднять приоритет, и каждая правка тянет за собой ещё
 * один !important.
 *
 * Тест НЕ требует привести всё в порядок разом. Он фиксирует потолок: числа
 * не должны расти. Тем же приёмом в проекте удержали шкалу брейкпоинтов
 * (тест 232) после схлопывания 33 значений в 8.
 *
 * Порог опускают ПО ФАКТУ чистки: перевёл компонент на токены из :root
 * (--step-*, --space-*, --shadow-*) — уменьшил число здесь. Поднимать
 * потолок, чтобы «прошло», нельзя: это ровно тот путь, которым 33
 * брейкпоинта когда-то и появились.
 */

/*
 * Потолки (`DESIGN_SCALE_CEILINGS`) и способ подсчёта (`public_design_css`,
 * `unique_static_values`) объявлены в tests/budgets.php — рядом с остальными
 * бюджетами проекта, чтобы отчёт
 * `php .claude/skills/quality-budgets/report.php` показывал запас по всем
 * сразу, а не по тем, о которых он знает.
 */

test('Число разных размеров шрифта в публичной теме не растёт', function (): void {
    $budget = quality_budget('design_font_sizes');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        sprintf(
            'статичных font-size стало %d при потолке %d. Новые размеры берут из шкалы '
            . '--step-* в :root (gov-theme.css), а не подбирают заново. Например: %s',
            $budget['value'],
            $budget['ceiling'],
            $budget['detail']
        )
    );
});

test('Число разных теней не растёт', function (): void {
    $budget = quality_budget('design_shadows');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        sprintf(
            'разных box-shadow стало %d при потолке %d. Ступени тени — --shadow-1..4 в :root. '
            . 'Например: %s',
            $budget['value'],
            $budget['ceiling'],
            $budget['detail']
        )
    );
});

test('Число разных радиусов не растёт', function (): void {
    $budget = quality_budget('design_radii');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        sprintf(
            'разных border-radius стало %d при потолке %d. Скругление задаёт админка '
            . '(--radius, --btn-radius), для «таблетки» есть --radius-pill. Например: %s',
            $budget['value'],
            $budget['ceiling'],
            $budget['detail']
        )
    );
});

test('Число !important в публичной теме не растёт', function (): void {
    $budget = quality_budget('design_important');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        sprintf(
            '!important стало %d при потолке %d. Если правило проигрывает соседу — '
            . 'разбираться с порядком и специфичностью, а не поднимать приоритет',
            $budget['value'],
            $budget['ceiling']
        )
    );
});

test('Шкалы объявлены и не конфликтуют с переменными админки', function (): void {
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    foreach (['--step-0', '--step-1', '--space-s', '--space-l', '--shadow-1', '--shadow-3', '--radius-pill'] as $token) {
        assert_contains($token . ':', $theme, 'токен ' . $token . ' объявлен');
    }

    // Скругление, отступы секций и базовый кегль задаёт админка: их печатает
    // DesignSettings::rootCss() в сгенерированный файл, который подключается
    // ПОСЛЕ темы. В теме они остаются только как значения по умолчанию (сайт
    // должен выглядеть прилично и до первого сохранения настроек), поэтому
    // проверяем не отсутствие, а именно наличие в обоих местах.
    $design = (string) file_get_contents(APP_ROOT . '/app/Core/DesignSettings.php');
    foreach (['--radius', '--card-gap', '--section-pad', '--btn-radius', '--base-font-size'] as $adminToken) {
        assert_contains($adminToken . ':', $design, $adminToken . ' задаётся админкой');
    }

    // А вот шкалы админка не задаёт — иначе они разъехались бы с настройками.
    foreach (['--step-0', '--space-s', '--shadow-1'] as $scaleToken) {
        assert_not_contains($scaleToken . ':', $design, $scaleToken . ' — шкала темы, не настройка');
    }
});
