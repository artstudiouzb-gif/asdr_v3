<?php

declare(strict_types=1);

use App\Core\AdminBrand;
use App\Models\Setting;

test('admin shell stability stylesheet wins over theme-specific legacy variables', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-shell-stability.css');

    assert_contains('html[data-admin-theme="default"]', $css);
    assert_contains('--admin-topbar-bg: rgba(255, 255, 255, .97)', $css);
    assert_contains('--admin-sidebar-bg: #13233f', $css);
    assert_contains('--admin-sidebar-active-text: #ffffff', $css);
    assert_contains('.admin-topbar > .admin-notification-bell + .admin-user', $css);
    assert_contains('z-index: 105 !important', $css);
    assert_contains('@media (max-width: 960px)', $css);
});

test('admin shell stability stylesheet loads after notification component styles', function (): void {
    Setting::set('admin_brand_accent', '');
    $head = AdminBrand::styleTag();

    $notificationCss = strpos($head, 'admin-notifications.css');
    $stabilityCss = strpos($head, 'admin-shell-stability.css');
    $notificationJs = strpos($head, 'admin-notifications.js');

    assert_true($notificationCss !== false, 'notification styles are connected');
    assert_true($stabilityCss !== false, 'shell stability styles are connected');
    assert_true($notificationJs !== false, 'notification client is connected');
    assert_true($notificationCss < $stabilityCss, 'stability overrides notification component styles');
    assert_true($stabilityCss < $notificationJs, 'styles are declared before deferred client');
});

test('custom admin accent overrides selected theme variables', function (): void {
    Setting::set('admin_brand_accent', '#17999B');

    try {
        $head = AdminBrand::styleTag();
        assert_contains(':root[data-admin-theme]{', $head);
        assert_contains('--admin-accent:#17999b;', $head);
        assert_contains('--admin-primary:#17999b;', $head);
        assert_contains('--admin-primary-dark:#148485;', $head);
    } finally {
        Setting::set('admin_brand_accent', '');
    }
});
