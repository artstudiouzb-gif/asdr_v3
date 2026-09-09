<?php

declare(strict_types=1);

use App\Core\HeaderConfig;

test('HeaderConfig нормализует параметры дизайна подменю', function (): void {
    $cfg = HeaderConfig::normalize([
        'styles' => [
            'submenu_style' => 'cards',
            'submenu_width' => 'wide',
            'submenu_font_size' => '14.4',
            'submenu_padding' => 'spacious',
            'submenu_transform' => 'capitalize',
            'submenu_radius' => 'rounded',
            'submenu_shadow' => 'deep',
            'submenu_divider' => 'accent',
            'submenu_bg' => '#FFFFFF',
            'submenu_color' => '#1E293B',
            'submenu_hover' => '#0284C7',
            'submenu_divider_color' => '#E2E8F0',
        ],
    ]);

    $styles = $cfg['styles'];
    assert_same('cards', $styles['submenu_style']);
    assert_same('wide', $styles['submenu_width']);
    assert_same('14.4', $styles['submenu_font_size']);
    assert_same('spacious', $styles['submenu_padding']);
    assert_same('capitalize', $styles['submenu_transform']);
    assert_same('rounded', $styles['submenu_radius']);
    assert_same('deep', $styles['submenu_shadow']);
    assert_same('accent', $styles['submenu_divider']);
    assert_same('#ffffff', $styles['submenu_bg']);
    assert_same('#1e293b', $styles['submenu_color']);
    assert_same('#0284c7', $styles['submenu_hover']);
    assert_same('#e2e8f0', $styles['submenu_divider_color']);
});

test('HeaderConfig безопасно сбрасывает неизвестные параметры подменю', function (): void {
    $styles = HeaderConfig::normalize([
        'styles' => [
            'submenu_style' => 'script',
            'submenu_width' => '9999px',
            'submenu_shadow' => 'url(evil)',
            'submenu_font_size' => '9999',
            'submenu_bg' => 'red',
        ],
    ])['styles'];

    assert_same('lines', $styles['submenu_style']);
    assert_same('normal', $styles['submenu_width']);
    assert_same('soft', $styles['submenu_shadow']);
    assert_same('13.8', $styles['submenu_font_size']);
    assert_same('', $styles['submenu_bg']);
});

test('Конструктор шапки выводит и применяет настройки подменю', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/header/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/HeaderController.php');
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $themeCss = (string) file_get_contents(APP_ROOT . '/app/Core/SiteThemeCss.php');
    $css = theme_css();

    foreach ([
        'styles_submenu_style',
        'styles_submenu_width',
        'styles_submenu_font_size',
        'styles_submenu_padding',
        'styles_submenu_transform',
        'styles_submenu_radius',
        'styles_submenu_shadow',
        'styles_submenu_divider',
        'styles_submenu_bg',
        'styles_submenu_color',
        'styles_submenu_hover',
        'styles_submenu_divider_color',
    ] as $field) {
        assert_contains($field, $view, "поле {$field} есть в конструкторе");
        assert_contains($field, $controller, "поле {$field} сохраняется");
    }

    assert_contains('site-menu--submenu-', $header);
    assert_contains('site-menu--submenu-transform-', $header);
    assert_contains('--submenu-width', $themeCss);
    assert_contains("type=\"number\"", $view);
    assert_contains('Любое значение от 10 до 24 px', $view);
    assert_contains('--submenu-shadow', $themeCss);
    assert_contains('.site-menu--submenu-cards', $css);
    assert_contains('.site-menu--submenu-divider-none', $css);
    assert_contains('.site-menu--submenu-transform-uppercase', $css);
    assert_contains(
        '.site-menu--style-underline .site-menu__item--has-children:hover > .site-menu__link::after',
        $css,
        'линия родительского пункта остаётся активной при переходе в подменю'
    );
    assert_contains(
        '.site-menu--style-underline .site-menu__item.is-open > .site-menu__link::after',
        $css,
        'линия родительского пункта остаётся активной у открытого подменю'
    );
});
