<?php

declare(strict_types=1);

use App\Core\AdminEntryGate;
use App\Core\Config;

putenv('ADMIN_ENTRY_PATH');
putenv('ADMIN_ENTRY_KEY');

Config::merge([
    'security' => [
        'admin_entry_path' => '/control-7f3a9c2e4d6b8f10',
        'admin_entry_ttl' => 1800,
        'admin_entry_key' => '',
        'admin_entry_allowed_cidrs' => [],
    ],
    'crypto' => [
        'encryption_key' => str_repeat('11', 32),
        'previous_encryption_key' => '',
    ],
]);

test('AdminEntryGate принимает только длинный одноуровневый секретный путь', function (): void {
    assert_same(
        '/control-7f3a9c2e4d6b8f10',
        AdminEntryGate::normalizePath('/control-7f3a9c2e4d6b8f10')
    );
    assert_same(
        '/control-7f3a9c2e4d6b8f10',
        AdminEntryGate::normalizePath('control-7f3a9c2e4d6b8f10')
    );

    assert_same(null, AdminEntryGate::normalizePath('/short'));
    assert_same(null, AdminEntryGate::normalizePath('/admin-secret-7f3a9c2e4d6b8f10'));
    assert_same(null, AdminEntryGate::normalizePath('/control/7f3a9c2e4d6b8f10'));
    assert_same(null, AdminEntryGate::normalizePath("/control-7f3a9c2e4d6b8f10\r\nX-Test: 1"));
    assert_same(null, AdminEntryGate::normalizePath('https://example.test/control-7f3a9c2e4d6b8f10'));
});

test('AdminEntryGate разрешает next только для маршрутов аутентификации', function (): void {
    $reset = '/admin/reset/' . str_repeat('a', 64);

    assert_same('/admin/login', AdminEntryGate::sanitizeNext('/admin/login'));
    assert_same('/admin/login/2fa', AdminEntryGate::sanitizeNext('/admin/login/2fa'));
    assert_same('/admin/forgot', AdminEntryGate::sanitizeNext('/admin/forgot'));
    assert_same($reset, AdminEntryGate::sanitizeNext($reset));

    assert_same('/admin/login', AdminEntryGate::sanitizeNext('/admin/users'));
    assert_same('/admin/login', AdminEntryGate::sanitizeNext('https://evil.example/'));
    assert_same('/admin/login', AdminEntryGate::sanitizeNext("/admin/login\r\nLocation: https://evil.example"));
});

test('AdminEntryGate подписывает короткоживущий cookie и привязывает его к User-Agent', function (): void {
    $now = 1_800_000_000;
    $token = AdminEntryGate::issueGrantValue($now + 600, 'Browser A');

    assert_true(AdminEntryGate::validateGrantValue($token, $now, 'Browser A'));
    assert_false(AdminEntryGate::validateGrantValue($token, $now, 'Browser B'));
    assert_false(AdminEntryGate::validateGrantValue($token, $now + 601, 'Browser A'));

    $tampered = substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');
    assert_false(AdminEntryGate::validateGrantValue($tampered, $now, 'Browser A'));
});

test('Ссылка восстановления проходит через секретный gateway без open redirect', function (): void {
    $reset = '/admin/reset/' . str_repeat('b', 64);
    assert_same(
        '/control-7f3a9c2e4d6b8f10?next=' . rawurlencode($reset),
        AdminEntryGate::entryUrl($reset)
    );
    assert_same(
        '/control-7f3a9c2e4d6b8f10?next=' . rawurlencode('/admin/login'),
        AdminEntryGate::entryUrl('https://evil.example/')
    );
});

test('Шлюз подключён до роутера и скрывает admin CSP от обычного сканера', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $headers = (string) file_get_contents(APP_ROOT . '/app/Core/SecurityHeaders.php');
    $reset = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PasswordResetController.php');

    assert_contains('AdminEntryGate::enforce()', $bootstrap);
    assert_contains('AdminEntryGate::hasPresentedCookie()', $headers);
    assert_contains("AdminEntryGate::entryUrl('/admin/reset/' . \$token)", $reset);
});
