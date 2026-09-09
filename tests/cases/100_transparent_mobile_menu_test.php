<?php

declare(strict_types=1);

use App\Core\DesignSettings;

test('локальный каталог содержит Noto Sans с нужными начертаниями', function (): void {
    assert_true(isset(DesignSettings::GOOGLE_FONTS['noto-sans']));
    $font = DesignSettings::GOOGLE_FONTS['noto-sans'];
    assert_same('Noto Sans', $font[0]);
    assert_contains("'Noto Sans'", $font[1]);
    assert_contains("'Noto Sans Fallback'", $font[1], 'в стеке нет запасного начертания с метриками');
    assert_contains('Noto+Sans:wght@400;600;700', $font[2]);

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/noto-sans.css');
    assert_contains("font-family: 'Noto Sans';", $css);
    assert_not_contains('https://', $css, 'CSS Noto Sans не должен обращаться во внешний CDN');
});

test('прозрачная мобильная шапка сохраняет видимый бургер и контрастное раскрытое меню', function (): void {
    $css = theme_css();
    assert_true(is_string($css));
    assert_contains('body .site-header--transparent:not(.is-scrolled) .site-burger,', $css);
    assert_contains('body.design-mmenu-burger.mobile-menu-open .site-header--transparent:not(.is-scrolled) .site-menu__link {', $css);
    assert_contains('color: var(--gov-title);', $css);
});
