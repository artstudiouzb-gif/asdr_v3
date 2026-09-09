<?php

declare(strict_types=1);

test('Скрытый вход загружается до gateway и управляется из Центра безопасности', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SecurityController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/security/index.php');

    // Ищем именно исполняемые вызовы: названия методов также упоминаются в
    // комментариях bootstrap, поэтому короткий поиск давал ложный порядок.
    $loadPos = strpos($bootstrap, '\\App\\Core\\AdminEntryConfig::loadIntoConfig();');
    $enforcePos = strpos($bootstrap, '\\App\\Core\\AdminEntryGate::enforce();');
    assert_true($loadPos !== false, 'Runtime-конфигурация шлюза загружается');
    assert_true($enforcePos !== false, 'Gateway применяется к HTTP-запросам');
    assert_true($loadPos < $enforcePos, 'Runtime-конфигурация загружается до проверки маршрута');
    assert_contains("'/admin/security/admin-entry'", $bootstrap, 'POST настроек обслуживается до общего Router');
    assert_contains('AdminEntrySettingsController())->update()', $bootstrap, 'Запрос передаётся защищённому контроллеру');

    assert_contains('AdminEntryConfig::state()', $controller, 'Контроллер передаёт состояние во view');
    assert_contains('id="admin-entry"', $view, 'В Центре безопасности есть отдельная секция');
    assert_contains('action="/admin/security/admin-entry"', $view, 'Форма отправляется в защищённый обработчик');
    assert_contains('confirm_access_change', $view, 'Опасное изменение требует явного подтверждения');
});

test('Установщик генерирует и показывает защищённый адрес панели', function (): void {
    $installer = (string) file_get_contents(APP_ROOT . '/app/Controllers/InstallController.php');
    $done = (string) file_get_contents(APP_ROOT . '/app/Views/install/done.php');

    assert_contains('AdminEntryConfig::ensureGenerated()', $installer, 'Адрес генерируется после создания установки');
    assert_contains("'adminEntryUrl' => \$adminEntryUrl", $installer, 'URL передаётся финальному экрану');
    assert_contains('— Панель управления: %s', $installer, 'Письмо содержит фактический защищённый URL');
    assert_not_contains('— Панель управления: %s/admin/login', $installer, 'Старый очевидный URL не отправляется');

    assert_contains('/** @var string $adminEntryUrl */', $done, 'Финальный экран ожидает защищённый URL');
    assert_contains("\$adminEntryUrl ?? '/admin/login'", $done, 'Есть безопасный fallback при ошибке генерации');
    assert_contains('Войти в панель управления', $done, 'Ссылка входа остаётся понятной пользователю');
});
