<?php

declare(strict_types=1);

test('admin layout exposes accessible responsive navigation controls', function (): void {
    $header = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/layout/header.php');
    $footer = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/layout/footer.php');

    assert_true(is_string($header));
    assert_true(is_string($footer));
    assert_contains('class="admin-skip-link"', $header);
    assert_contains('aria-current="page"', $header);
    assert_contains('data-sidebar-collapse', $header);
    assert_contains('data-sidebar-backdrop', $header);
    assert_contains('id="admin-content"', $header);
    assert_contains('artstudio:admin-sidebar-collapsed', $footer);
    assert_contains("e.key === 'Escape'", $footer);
    assert_contains("s.inert = mobile && !open", $footer);
});

test('admin stylesheet contains professional desktop and mobile states', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

    assert_true(is_string($css));
    assert_contains('--admin-topbar-bg: #1d2327', $css);
    assert_contains('--admin-accent: #2271b1', $css);
    assert_contains('.admin-nav-collapsed', $css);
    assert_contains('body.sidebar-open .admin-sidebar-backdrop', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
    assert_contains('.data-table tbody tr:nth-child(even)', $css);
    assert_contains('.admin-welcome', $css);
});
