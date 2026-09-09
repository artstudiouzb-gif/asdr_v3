<?php

declare(strict_types=1);

use App\Models\AuditLog;

test('Центр безопасности показывает реальные каналы 2FA и данные аудита', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SecurityController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/security/index.php');

    assert_contains('TelegramBot::isConfigured()', $controller);
    assert_contains('TelegramGateway::isConfigured()', $controller);
    assert_contains('AuditLog::recentAuthEvents', $controller);
    assert_contains('AuditLog::authSummary', $controller);
    assert_contains('SessionRegistry::forUser', $controller);

    assert_not_contains('two_factor_secret', $view, 'несуществующее поле больше не используется');
    assert_contains("\$event['ip']", $view, 'используется реальная колонка audit_log.ip');
    assert_contains('/admin/audit?method=AUTH', $view);
    assert_contains('Технический security.log', $view);
});

test('Все исходы входа имеют понятные метки журнала', function (): void {
    foreach ([
        'auth/login',
        'auth/login.pending-2fa',
        'auth/login.setup-required',
        'auth/login.send-failed',
        'auth/login.locked',
        'auth/login.failed',
        'auth/2fa',
        'auth/2fa.failed',
        'auth/2fa.resent',
        'auth/2fa.resend-failed',
        'auth/logout',
    ] as $path) {
        $meta = AuditLog::authEventMeta($path);
        assert_true($meta['label'] !== 'Событие аутентификации', 'нет метки для ' . $path);
        assert_true(in_array($meta['tone'], ['success', 'info', 'warning', 'danger'], true));
    }
});

test('Выход из панели нельзя вызвать GET-ссылкой', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $adminHeader = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    $siteToolbar = (string) file_get_contents(APP_ROOT . '/app/Views/site/components/admin_toolbar.php');

    assert_not_contains("\$router->get('/admin/logout'", $routes);
    assert_contains('method="post" action="/admin/logout"', $adminHeader);
    assert_contains('method="post" action="/admin/logout"', $siteToolbar);
    assert_contains('name="csrf_token"', $siteToolbar);
});
