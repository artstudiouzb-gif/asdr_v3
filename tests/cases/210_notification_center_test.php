<?php

declare(strict_types=1);

use App\Models\NotificationPreference;

test('Центр уведомлений подключён к защищённой админ-оболочке', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $brand = (string) file_get_contents(APP_ROOT . '/app/Core/AdminBrand.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NotificationController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/notifications/index.php');

    $gatePos = strpos($bootstrap, '\\App\\Core\\AdminEntryGate::enforce();');
    $notificationsPos = strpos($bootstrap, 'NotificationController::dispatch(');
    assert_true($gatePos !== false && $notificationsPos !== false, 'Gateway и notification dispatcher подключены');
    assert_true($gatePos < $notificationsPos, 'Скрытый admin gateway выполняется раньше notification routes');

    assert_contains('admin-notifications.css', $brand, 'Стили колокольчика загружаются в head');
    assert_contains('admin-notifications.js', $brand, 'Клиент колокольчика загружается в head');
    assert_contains('Auth::requireLogin()', $controller, 'Все endpoints требуют авторизацию');
    assert_contains('Csrf::verifyRequest()', $controller, 'Изменяющие endpoints требуют CSRF');
    assert_contains('action="/admin/notifications/preferences"', $view, 'Настройки каналов доступны на странице');
    assert_contains('Подтвердить ознакомление', $view, 'Критические события можно подтвердить');
});

test('Notification schema содержит получателей, настройки и надёжную очередь', function (): void {
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_03_notification_center.sql');
    $schema = (string) file_get_contents(APP_ROOT . '/app/Core/NotificationSchema.php');
    $delivery = (string) file_get_contents(APP_ROOT . '/app/Models/NotificationDelivery.php');

    foreach (['notifications', 'notification_recipients', 'notification_preferences', 'notification_deliveries'] as $table) {
        assert_contains('CREATE TABLE IF NOT EXISTS ' . $table, $migration, 'Таблица есть в миграции: ' . $table);
        assert_contains('CREATE TABLE IF NOT EXISTS ' . $table, $schema, 'Таблица есть в fresh-install fallback: ' . $table);
    }
    assert_contains('idempotency_key', $migration, 'Повторная доставка дедуплицируется');
    assert_contains('FOR UPDATE SKIP LOCKED', $delivery, 'Параллельные воркеры безопасно забирают очередь');
    assert_contains('locked_until', $delivery, 'Очередь использует аренду строк');
    assert_contains('markFailed', $delivery, 'Ошибки получают повторные попытки');
});

test('Фильтр важности и тихие часы работают предсказуемо', function (): void {
    $preference = [
        'minimum_severity' => 'warning',
        'quiet_start' => '22:00:00',
        'quiet_end' => '07:00:00',
    ];

    assert_false(NotificationPreference::allowsSeverity($preference, 'info'), 'Обычное info не выходит наружу');
    assert_true(NotificationPreference::allowsSeverity($preference, 'warning'), 'Warning проходит порог');
    assert_true(NotificationPreference::allowsSeverity($preference, 'critical'), 'Critical проходит порог');

    assert_true(
        NotificationPreference::isQuietNow($preference, new DateTimeImmutable('2026-08-03 23:30:00')),
        'Ночной интервал после полуночи поддержан'
    );
    assert_true(
        NotificationPreference::isQuietNow($preference, new DateTimeImmutable('2026-08-04 06:30:00')),
        'Ночной интервал до окончания поддержан'
    );
    assert_false(
        NotificationPreference::isQuietNow($preference, new DateTimeImmutable('2026-08-04 12:00:00')),
        'Днём доставка разрешена'
    );
});

test('Клиент колокольчика выводит серверный текст только через textContent', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-notifications.js');

    assert_contains('title.textContent = String(item.title', $js, 'Заголовок не вставляется как HTML');
    assert_contains('message.textContent = String(item.message', $js, 'Сообщение не вставляется как HTML');
    assert_contains('safeAdminUrl', $js, 'Переходы ограничены внутренней админкой');
    assert_contains("data.set('_csrf'", $js, 'POST колокольчика содержит CSRF');
    assert_not_contains('link.innerHTML = item', $js, 'Пользовательские данные не попадают в innerHTML');
});

test('Внешние уведомления используют безопасные краткие сообщения', function (): void {
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/notification_worker.php');
    $form = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/FormController.php');
    $entry = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/AdminEntrySettingsController.php');

    assert_contains('htmlspecialchars($message, ENT_QUOTES)', $worker, 'Текст внешней доставки HTML-экранируется');
    assert_contains('Подробности доступны после', $worker, 'Внешняя доставка содержит только безопасную краткую версию');
    assert_contains('Получена новая заявка через форму', $form, 'Заявка уведомляет только о факте события');
    assert_contains("'form-submission-' . \$submissionId", $form, 'Заявка дедуплицируется по внутреннему ID');
    assert_contains('Сам секретный адрес во внешние уведомления не передаётся', $entry, 'Секрет gateway явно исключён');
    assert_not_contains("NotificationCenter::administrators(\n                    'Адрес входа обновлён'", $worker, 'Worker не строит тексты с секретом шлюза');
});
