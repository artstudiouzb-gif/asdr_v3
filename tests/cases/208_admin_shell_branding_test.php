<?php

declare(strict_types=1);

test('Бренд админки находится в крупной зоне sidebar, а topbar остаётся рабочим', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    $shellCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-shell-v2.css');

    assert_contains("/assets/css/admin-shell-v2.css", $header, 'Новая оболочка подключена после основного admin.css');
    assert_contains('class="admin-sidebar__brand"', $header, 'Бренд размещён внутри боковой панели');
    assert_contains("AdminBrand::badgeHtml('admin-sidebar__logoimg', 'admin-sidebar__logo')", $header, 'Логотип использует отдельные крупные sidebar-классы');
    assert_not_contains('class="admin-topbar__brand"', $header, 'Верхняя панель больше не дублирует логотип');
    assert_contains('class="admin-user__identity"', $header, 'В topbar показаны имя и роль пользователя');

    assert_contains('--admin-sidebar-w: 286px', $shellCss, 'Полная панель достаточно широка для бренда');
    assert_contains('width: 52px', $shellCss, 'Буквенный знак увеличен');
    assert_contains('height: 52px', $shellCss, 'Высота знака увеличена');
    assert_contains('html.admin-nav-collapsed', $shellCss, 'Сохранён компактный режим панели');
    assert_contains('@media (max-width: 960px)', $shellCss, 'Предусмотрен off-canvas sidebar на мобильных');
});
