<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer as Presentation;

/*
 * Ритм секций: одна шкала на «воздух» блока и на отдельные отступы сверху и
 * снизу.
 *
 * Прежде рядов было два и они спорили именами. У «воздуха» ступени звались
 * `small` / `premium` / `max`, у отдельных отступов — `small` / `medium` /
 * `large`, причём «Средний» во втором поле означал ту же величину, что
 * «Премиум» в первом: сравнить два поля глазами было нельзя. Вдобавок ступеней
 * было четыре, и между «Малым» (14–24px) и «Премиумом» (28–56px) лежал разрыв
 * вдвое — а ритм, ради которого отступы и настраиваются, живёт как раз между
 * ними.
 *
 * Отдельно от этого ряда живёт шкала --space-3xs…3xl: это внутренние
 * промежутки компонентов, статичные rem. Пока оба ряда назывались --space-*,
 * `--space-s` (1rem) и `--space-small` (clamp(14px,2.5vw,24px)) читались как
 * одно и то же — имена похожи, величины разные.
 */

test('Ступени ритма: ряд один, прежние имена сохраняют свою величину', function () {
    assert_same(
        ['none', 'xs', 'small', 'mid', 'premium', 'max'],
        Presentation::SPACING,
        'ряд ступеней'
    );

    // Подпись и переменная есть у каждой ступени: список объявлен один раз,
    // и три копии (форма, нормализатор, рендерер) разъехались бы при первом
    // же добавлении ступени.
    foreach (Presentation::SPACING as $step) {
        assert_true(isset(Presentation::SPACING_LABELS[$step]), 'ступень без подписи: ' . $step);
        assert_true(isset(Presentation::SPACING_VARS[$step]), 'ступень без переменной: ' . $step);
    }
    assert_same(count(Presentation::SPACING), count(Presentation::SPACING_LABELS));
    assert_same(count(Presentation::SPACING), count(Presentation::SPACING_VARS));

    // Прежние имена отдельных отступов приводятся к ряду ТОЙ ЖЕ величины:
    // переименование не должно менять вид уже собранных страниц.
    assert_same('premium', Presentation::padding('medium'), 'medium назывался величиной premium');
    assert_same('max', Presentation::padding('large'), 'large назывался величиной max');
    assert_same('default', Presentation::padding('чужое'));
    assert_same('premium', Presentation::spacing('чужое'), 'у «воздуха» ступени default нет');

    // Новая средняя ступень не зовётся `medium` именно потому, что это слово
    // занято прежним смыслом.
    assert_true(!in_array('medium', Presentation::SPACING, true), 'medium нельзя переиспользовать');
});

test('Каждая ступень ритма нарисована в CSS и опирается на переменную', function () {
    $css = public_design_css();

    foreach (Presentation::SPACING as $step) {
        assert_contains('.cms-block--space-' . $step, $css, 'ступень без правила в CSS: ' . $step);
    }

    // Переменные ступеней объявлены — иначе правило есть, а значения у него
    // нет, и отступ молча схлопывается в ноль.
    foreach (['--section-space-xs', '--section-space-s', '--section-space-m', '--section-space-l', '--section-space-xl'] as $var) {
        assert_contains($var . ':', $css, 'ступень не объявлена: ' . $var);
    }

    // Промежуточные ступени выводятся из опорных, а не записаны числами:
    // иначе «Плотность секций» двигала бы края шкалы, оставляя середину.
    assert_contains('--section-space-xs: calc(', $css, 'xs должна выводиться из опорной ступени');
    assert_contains('--section-space-m: calc(', $css, 'mid должна выводиться из опорных ступеней');

    // Старые имена переменных ритма не должны оставаться: они путались с
    // компонентной шкалой --space-*.
    assert_true(
        !str_contains($css, 'var(--space-small)') && !str_contains($css, 'var(--space-premium)')
            && !str_contains($css, 'var(--space-max)'),
        'ритм секций больше не берётся из --space-small/premium/max'
    );
});

test('Опорные ступени приезжают из настроек «Дизайна»', function () {
    $themeCss = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/SiteThemeCss.php');

    foreach (['--section-space-s', '--section-space-l', '--section-space-xl'] as $var) {
        assert_contains($var, $themeCss, 'опорная ступень не печатается слоем «Дизайна»: ' . $var);
    }

    // Запасные значения в CSS обязаны совпадать с умолчанием «Стандарт»:
    // иначе сайт с несобранным слоем «Дизайна» выглядит иначе, чем с
    // собранным, — тот же разъезд «одно имя, два умолчания», ради которого
    // сведены цвета (тест 345).
    $spacings = App\Core\DesignSettings::semanticSpacings();
    $frontend = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    $pairs = [
        '--section-space-s' => $spacings['space_small'],
        '--section-space-l' => $spacings['space_premium'],
        '--section-space-xl' => $spacings['space_max'],
    ];
    foreach ($pairs as $var => $value) {
        assert_contains($var . ': ' . $value . ';', $frontend, 'запасное значение разошлось с умолчанием: ' . $var);
    }
});
