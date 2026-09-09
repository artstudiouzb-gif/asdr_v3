<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Core\Uploader;
use App\Core\UrlGuard;

test('Логи скрывают пароли, токены, cookies и одноразовые ссылки', function (): void {
    $secret = 'example-sensitive-value-123456';
    $message = 'password=' . $secret
        . ' Authorization: Bearer ' . $secret
        . ' Cookie: sid=' . $secret
        . ' https://example.test/admin/reset/' . $secret;
    $safe = Logger::redact($message);

    assert_not_contains($secret, $safe, 'Секрет не остаётся в строке журнала');
    assert_contains('[REDACTED]', $safe, 'На месте секрета остаётся понятный маркер');

    $context = Logger::redactContext([
        'user' => 'admin',
        'access_token' => $secret,
        'nested' => ['signature' => $secret, 'url' => '?token=' . $secret],
    ]);
    assert_same('[REDACTED]', $context['access_token'], 'Токен верхнего уровня скрыт');
    assert_same('[REDACTED]', $context['nested']['signature'], 'Вложенная подпись скрыта');
    assert_not_contains($secret, (string) $context['nested']['url'], 'Токен в URL скрыт');
});

test('SSRF-защита запрещает внутренние адреса и URL с учётными данными', function (): void {
    assert_false(UrlGuard::isSafeRemote('http://127.0.0.1/admin'), 'Loopback IPv4 запрещён');
    assert_false(UrlGuard::isSafeRemote('http://[::1]/admin'), 'Loopback IPv6 запрещён');
    assert_false(UrlGuard::isSafeRemote('https://user:pass@8.8.8.8/data'), 'Userinfo в URL запрещён');

    $target = UrlGuard::safeRemoteTarget('https://8.8.8.8/data');
    assert_true(is_array($target), 'Публичный IP разрешён');
    assert_same('8.8.8.8', $target['host'], 'Хост цели определён');
    assert_same(443, $target['port'], 'Порт HTTPS определён');
});

test('Пользовательские исходящие запросы закрепляют проверенный DNS-адрес', function (): void {
    $http = (string) file_get_contents(APP_ROOT . '/app/Core/Http.php');
    $legacyImporter = (string) file_get_contents(APP_ROOT . '/app/Core/LegacyCmsImporter.php');

    assert_contains('CURLOPT_RESOLVE', $http, 'HTTP-клиент закрепляет DNS-результат');
    assert_contains('CURLINFO_PRIMARY_IP', $http, 'HTTP-клиент сверяет фактический IP');
    assert_contains('getSafeRemote', $legacyImporter, 'Импорт использует безопасный клиент');
});

test('Загрузчик проверяет реальное содержимое, а не только имя файла', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'asdr-upload-');
    if ($path === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }
    file_put_contents($path, '<?php echo "unsafe";');

    try {
        $rejected = false;
        try {
            Uploader::storeFromPath(
                $path,
                'avatar.jpg',
                (int) filesize($path),
                'public',
                null,
                false
            );
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        assert_true($rejected, 'PHP-код с расширением JPG отклонён по MIME');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('Защищённые файлы не открываются редактору только по факту входа', function (): void {
    $download = (string) file_get_contents(APP_ROOT . '/public/download.php');
    $fileController = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FileController.php');
    $chunkedController = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ChunkedUploadController.php');

    assert_contains("RbacGuard::can('manage_protected_files')", $download, 'Скачивание проверяет отдельное право');
    assert_contains('FileEntry::filtered($_GET, $canManageProtected)', $fileController, 'Редактор не получает токены защищённых файлов');
    assert_contains("requirePermission('manage_protected_files')", $fileController, 'Операции с защищёнными файлами требуют права');
    assert_contains("RbacGuard::can('manage_protected_files')", $chunkedController, 'Чанковая загрузка проверяет право');
});

test('Персональные данные заявок требуют отдельного права', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FormController.php');
    $dashboard = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DashboardController.php');

    assert_true(
        substr_count($controller, "requirePermission('manage_submissions')") >= 2,
        'Просмотр и удаление заявок защищены'
    );
    assert_contains("RbacGuard::can('manage_submissions')", $dashboard, 'Дашборд не загружает заявки редактору');
});

test('Сессия имеет простой и абсолютный предел времени', function (): void {
    $session = (string) file_get_contents(APP_ROOT . '/app/Core/Session.php');
    $config = (string) file_get_contents(APP_ROOT . '/config/config.example.php');

    assert_contains("session.absolute_lifetime", $session, 'Сессия читает абсолютный предел');
    assert_contains("started_at", $session, 'Время начала сессии фиксируется');
    assert_contains("SESSION_ABSOLUTE_LIFETIME", $config, 'Предел можно настроить через окружение');
});
