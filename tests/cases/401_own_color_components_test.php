<?php

declare(strict_types=1);

use App\Core\SectionColors;

/*
 * Цвет секции не заходит внутрь компонентов, которые красят себя сами.
 *
 * Правило цвета секции весит по id, и прежде оно перекрашивало всё подряд:
 * заголовок полосы «Призыва к действию» с белым текстом на тёмной заливке
 * получал тёмный цвет светлой секции (1.00:1), кнопка — цвет ссылки, а белая
 * панель «Показателей» на тёмной секции — белый заголовок.
 */

function own_colors_css(string $file): string
{
    return (string) file_get_contents(APP_ROOT . '/public/assets/css/' . $file);
}

test('Правила цвета секции не выбирают элементы внутри компонентов со своими цветами', function (): void {
    foreach (['light', 'dark'] as $scheme) {
        $css = SectionColors::build(['_bg_text_scheme' => $scheme], 7);
        foreach (explode("\n", $css) as $line) {
            if (preg_match('/^#block-7 :is\((h1|p|a)/', $line) === 1) {
                assert_contains(SectionColors::outsideOwnColors() . '{', $line, $scheme . ': ' . substr($line, 0, 40));
            }
        }
    }
    foreach (['.block-cta', '.block-ctaband', '.block-banner'] as $component) {
        assert_true(in_array($component, SectionColors::OWN_COLORS, true), $component);
    }
    // Без подложки показатели стоят на фоне секции и обязаны брать её цвет.
    assert_contains('.block-counters:not(.block-counters--panel-none)', implode(',', SectionColors::OWN_COLORS));
});

test('«Показатели»: панель красит себя сама, без подложки — цветом секции', function (): void {
    $css = own_colors_css('blocks/counters.css');
    assert_true(
        (bool) preg_match('/\.block-counters:not\(\.block-counters--panel-none\)\s*\{[^}]*--section-title-fg:\s*var\(--counters-fg\)/', $css),
        'редакционный слой читает --section-title-fg напрямую — панель объявляет его себе'
    );
    assert_true(
        (bool) preg_match('/\.block-counters--panel-none\s*\{[^}]*--counters-fg:\s*var\(--counters-text,\s*var\(--section-title-fg\)\)/', $css),
        'без подложки числа берут цвет секции'
    );
    assert_not_contains('var(--counters-text, var(--gov-title))', preg_replace('/--counters-fg:[^;]+;/', '', $css), 'цвет чисел объявлен один раз, а не в каждом правиле');
    assert_true(
        (bool) preg_match('/\.block-counters--panel-none\.block-counters--cards \.counter[^{]*\{[^}]*background:\s*var\(--counters-bg,\s*transparent\)/', $css),
        '«без подложки» снимает и заливку карточек показателей'
    );
});

test('«Призыв к действию»: заголовок полосы и фото с затемнением', function (): void {
    $theme = own_colors_css('gov-theme.css');
    // Общее правило заголовков темы красит .ctaband__title в тёмный — на
    // тёмной полосе это тёмное по тёмному.
    assert_true((bool) preg_match('/\n\.ctaband__title \{[^}]*color:\s*inherit/', $theme), 'заголовок полосы берёт цвет полосы');

    $front = own_colors_css('frontend.css');
    assert_contains('background-image: var(--block-banner-image)', $front, 'кадр «Фото с затемнением» кто-то обязан прочитать');
    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/cta.php');
    assert_contains('--block-banner-image:', $template);
});

test('Цвет текста поверхностей не ссылается на несуществующую переменную', function (): void {
    // --text-primary не объявлена нигде: объявление через неё оставляло
    // --section-fg пустым, и белая карточка наследовала белый текст секции.
    $theme = preg_replace('#/\*.*?\*/#s', '', own_colors_css('gov-theme.css'));
    $surfaces = implode(', ', SectionColors::SURFACES);
    $pos = strpos((string) $theme, '.social-embed-card, .team-card, .testimonial, .textimage__info--card, .timeline-card, .widget--style-card) {');
    assert_true($pos !== false, 'правило поверхностей найдено');
    $rule = substr((string) $theme, (int) $pos, 300);
    assert_contains('--section-fg: var(--gov-ink)', $rule);
    assert_not_contains('text-primary', (string) file_get_contents(APP_ROOT . '/app/Core/SectionColors.php'));
    assert_true($surfaces !== '');
});
