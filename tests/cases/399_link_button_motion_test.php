<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\DesignSettings;
use App\Core\SectionHead;

/**
 * «Дизайн»: ссылки «Все …» со стрелкой или с рисуемым подчёркиванием и
 * заливка контурных кнопок при наведении.
 *
 * Обе настройки меняют только поведение при наведении, поэтому умолчание
 * обязано давать прежний сайт: ни класса на <body>, ни другой разметки.
 * Стрелка вынесена в отдельный элемент — прежде она была частью текста, и
 * скрыть её было нечем, а диктор читал «Все новости стрелка вправо».
 */

test('Ссылки и контурные кнопки — настройки «Дизайна» с прежним видом по умолчанию', function (): void {
    $options = DesignSettings::OPTIONS;
    assert_same('Общие', $options['link_style']['group'], 'группа «Общие» рисуется формой сама');
    assert_same('arrow', $options['link_style']['default']);
    assert_same(['arrow', 'draw'], array_keys($options['link_style']['choices']));
    assert_same('off', $options['button_fill']['default']);
    assert_same(['off', 'accent', 'primary'], array_keys($options['button_fill']['choices']));

    $base = ['catalog_layout' => 'grid', 'sidebar_position' => 'floating', 'card_style' => 'soft', 'detail_layout' => 'plain'];
    $default = DesignSettings::bodyClasses($base);
    assert_not_contains('design-links-', $default);
    assert_not_contains('design-btnfill', $default);

    assert_contains(' design-links-draw', DesignSettings::bodyClasses(['link_style' => 'draw'] + $base));
    assert_contains(' design-btnfill design-btnfill-accent', DesignSettings::bodyClasses(['button_fill' => 'accent'] + $base));
    assert_contains(' design-btnfill design-btnfill-primary', DesignSettings::bodyClasses(['button_fill' => 'primary'] + $base));
    assert_not_contains('design-btnfill', DesignSettings::bodyClasses(['button_fill' => 'evil"'] + $base));
    assert_same('arrow', DesignSettings::sanitize('link_style', 'underline'));
});

test('Ссылка «Все …» одна на все шаблоны, стрелка скрыта от диктора', function (): void {
    $link = SectionHead::allLink('Все <новости>', '/news?a=1&b=2');
    assert_contains('href="/news?a=1&amp;b=2"', $link);
    assert_contains('<span class="section-head__all-text">Все &lt;новости&gt;</span>', $link);
    assert_contains('<span class="section-head__arrow" aria-hidden="true">→</span>', $link);

    // Своей копии разметки у шаблонов нет: пятая копия разъехалась бы с
    // остальными, и стрелку в ней настройка не спрятала бы.
    foreach (glob(APP_ROOT . '/templates/blocks/*.php') ?: [] as $file) {
        assert_not_contains('class="section-head__all"', (string) file_get_contents($file), basename($file) . ' собирает ссылку «все» сам');
    }
});

test('Контурные кнопки помечены зацепкой заливки, основные — нет', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 399,
        'type' => 'buttons',
        'data' => json_encode(['items' => [
            ['text' => 'Подать заявку', 'url' => '/apply', 'style' => 'primary'],
            ['text' => 'Скачать положение', 'url' => '/doc', 'style' => 'outline'],
            ['text' => 'Подробнее', 'url' => '/more', 'style' => 'link'],
        ]], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    $html = (string) $rendered['html'];
    assert_contains('block-buttons__btn--outline btn-fill"', $html);
    assert_not_contains('block-buttons__btn--primary btn-fill', $html, 'основная кнопка залита всегда');
    assert_not_contains('block-buttons__btn--link btn-fill', $html, 'у ссылки нет поверхности, которую заливать');

    foreach ([
        'templates/blocks/hero.php' => 'block-hero__button--ghost btn-fill',
        'templates/blocks/cta.php' => 'block-banner__button--ghost btn-fill',
        'app/Views/site/news_show.php' => 'newsdetail__btn--ghost btn-fill',
        'app/Core/Hero/HeroRenderer.php' => "'ghost' ? ' btn-fill'",
    ] as $file => $needle) {
        assert_contains($needle, (string) file_get_contents(APP_ROOT . '/' . $file), $file . ': контур без зацепки');
    }
});

test('Приёмы оформлены, движение гаснет при «меньше движения»', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');
    assert_contains('.design-links-draw .section-head__arrow { display: none; }', $css);
    assert_contains('.design-links-draw :where(.rich-content) a {', $css, 'ссылка в тексте получает линию в покое');
    assert_contains('@media (hover: none)', $css, 'на касании наведения нет — линия нужна сразу');
    // ::after у кнопок обложки занят бликом, поэтому заливка — ::before.
    assert_contains('.design-btnfill .btn-fill::before {', $css);
    assert_not_contains('.btn-fill::after', $css);
    assert_contains('--btn-fill-fg: var(--on-accent, #fff)', $css, 'текст на акценте подбирается по контрасту');

    // Переходы объявлены только внутри no-preference: вне его приём просто
    // появляется, без анимации.
    $outside = (string) preg_replace('/@media \(prefers-reduced-motion: no-preference\)\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css);
    $tail = substr($outside, (int) strpos($outside, '.design-links-draw .section-head__arrow'));
    assert_not_contains('transition', $tail);
    assert_contains('transition: background-size', $css);
    assert_contains('transition: transform .38s', $css);
});
