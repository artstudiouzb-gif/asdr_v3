<?php

declare(strict_types=1);

test('accessibility controls open in a side drawer and stay above the page', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/a11y.css');
    $header = file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    $panel = file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_a11y_panel.php');
    $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/a11y.js');

    assert_true(is_string($css));
    assert_true(is_string($header));
    assert_true(is_string($panel));
    assert_true(is_string($js));
    // Панель выезжает справа поверх страницы и не двигает вёрстку.
    assert_contains('.a11y-drawer {', $css);
    assert_contains('position: fixed;', $css);
    assert_contains('z-index: 901;', $css);
    assert_contains('width: min(420px, 100vw);', $css);
    // На узком экране занимает всю ширину.
    assert_contains('@media (max-width: 480px)', $css);
    assert_contains('width: 100vw;', $css);
    assert_contains('id="a11y-panel"', $panel);
    assert_contains('aria-controls="a11y-panel"', $header);
    assert_contains("toggle.setAttribute('aria-expanded'", $js);
    assert_contains("event.key !== 'Escape'", $js);
});

test('dropdown search is anchored, constrained and restores focus', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    $themeCss = theme_css();
    $header = file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');

    assert_true(is_string($css));
    assert_true(is_string($themeCss));
    assert_true(is_string($header));
    assert_true(is_string($js));
    assert_contains('.site-search-overlay { position: fixed; inset: 0; z-index: 700;', $css);
    assert_contains('width: min(620px, calc(100vw - 32px))', $css);
    assert_contains('body.design-search-inline .site-header .site-search { display: inline-flex; }', $themeCss);
    assert_contains('body.design-search-overlay .site-header .site-search-toggle { display: grid; }', $themeCss);
    // Источником вида поиска стал конструктор шапки ($searchConfig['style'],
    // где 'modal' = overlay) вместо настроек дизайна. Инвариант тот же: тип
    // сводится строго к inline|overlay и попадает в класс body.
    assert_contains("\$searchType = (\$searchConfig['style'] ?? 'inline') === 'modal' ? 'overlay' : 'inline';", $header);
    assert_contains("\$designBodyClass .= ' design-search-' . \$searchType;", $header);
    assert_contains("\$searchHtml = \$searchType === 'overlay' ? \$overlaySearchHtml : \$inlineSearchHtml;", $header);
    assert_contains("<?php if (\$searchType === 'overlay'): ?>", $header);
    assert_contains('id="site-search-popover"', $header);
    assert_contains('minlength="2" required', $header);
    assert_contains('var positionSearch = function (toggle)', $js);
    assert_contains('focusTarget.focus()', $js);
    assert_contains("e.key === 'Tab'", $js);
    assert_contains("document.body.classList.add('site-search-open')", $js);
    assert_contains("hdr.style.setProperty('--hdr-panel-height'", $js);
});
