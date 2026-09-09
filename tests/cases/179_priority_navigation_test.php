<?php

declare(strict_types=1);

test('Priority navigation отделена от полного мобильного drawer', function (): void {
    $template = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    assert_contains("'menu' => \$priorityMenuHtml", $template);
    assert_contains('$drawerMenu = $menuHtml', $template);
    assert_contains('data-priority-overflow-toggle', $template);
    assert_contains('data-mobile-menu-toggle', $template);
});

test('Desktop priority navigation сохраняет порядок пунктов и доступность', function (): void {
    $script = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');

    // Панель переполнения адресуется через parts.panel после рефакторинга;
    // порядок вставки (в начало панели) остался тем же.
    assert_contains("parts.panel.insertBefore(item, parts.panel.firstChild)", $script);
    assert_contains("menu.insertBefore(parts.panel.firstElementChild, parts.overflow)", $script);
    assert_contains("while (items.length > 1 && doesNotFit(header, menu, parts))", $script);
    assert_contains('itemRect.left < menuRect.left', $script);
    assert_contains('itemRect.right > menuRect.right', $script);
    assert_contains('var fitTolerance = 4;', $script);
    assert_not_contains('menu.scrollWidth > menu.clientWidth', $script);
    assert_contains("toggle.setAttribute('aria-expanded'", $script);
    assert_contains("desktop.matches", $script);
});

test('Priority-бургер является последним элементом горизонтального меню', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    assert_contains('.site-menu__overflow {', $css);
    assert_contains('.site-menu__overflow-panel {', $css);
    assert_contains('.site-header__inner:has([data-priority-menu]) > .site-header__zone:has([data-priority-menu])', $css);
    assert_contains('.site-header__zone--center:not(:has([data-priority-menu]))', $css);
    assert_contains('.hdr-util:has(> [data-priority-menu])', $css);
    assert_contains('flex: 1 1 0;', $css);
    assert_contains('flex-wrap: nowrap !important', $css);
    assert_contains('.site-menu__link:has(+ [data-priority-overflow])::before', $css);
    assert_contains('.site-menu__divider:has(+ [data-priority-overflow])', $css);
    assert_contains('content: none !important;', $css);
    assert_contains('.site-menu__overflow.is-open .site-menu__overflow-toggle span:nth-child(1)', $css);
    assert_contains('transform: translateY(6px) rotate(45deg)', $css);
    assert_contains('@media (max-width: 720px)', $css);
    assert_contains('[data-priority-overflow]', $css);
});

test('Прозрачная шапка использует компактный hamburger без белой карточки', function (): void {
    $css = theme_css();

    assert_contains('body .site-header--transparent:not(.is-scrolled) .site-burger,', $css);
    assert_contains('body .site-header--transparent:not(.is-scrolled) .site-menu__overflow-toggle {', $css);
    assert_contains('width: 36px;', $css);
    assert_contains('border-radius: var(--radius-sm, 6px);', $css);
    assert_contains('background: transparent;', $css);
    assert_contains('box-shadow: none;', $css);
});

test('Ссылки панели «Ещё» контрастны на прозрачной шапке', function (): void {
    $css = theme_css();

    assert_contains(
        'body .site-header--transparent:not(.is-scrolled) .site-menu__overflow-panel > .site-menu__link,',
        $css
    );
    assert_contains(
        'color: var(--submenu-color, var(--gov-title)) !important;',
        $css
    );
    assert_contains(
        'color: var(--submenu-hover, var(--menu-hover-color, var(--gov-teal))) !important;',
        $css
    );
});
