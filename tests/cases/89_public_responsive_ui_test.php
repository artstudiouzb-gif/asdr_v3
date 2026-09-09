<?php

declare(strict_types=1);

test('public cards expose consistent keyboard and pointer states', function (): void {
    $css = theme_css();

    assert_true(is_string($css));
    assert_contains('.news-card__link, .newsfeat-lead, .newsfeat-mini', $css);
    assert_contains(':focus-visible', $css);
    assert_contains('@media (hover: hover) and (pointer: fine)', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
});

test('public layouts include narrow header and grid safeguards', function (): void {
    $css = theme_css();

    assert_true(is_string($css));
    assert_contains('@media (max-width: 1000px) and (min-width: 721px)', $css);
    assert_contains('.cms-columns--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }', $css);
    assert_contains('body.mobile-menu-open { overflow: hidden; }', $css);
    assert_contains('max-height: calc(100dvh - 72px)', $css);
    assert_contains('.newsdocs-item {', $css);
    assert_contains('overflow-wrap: anywhere', $css);
});
