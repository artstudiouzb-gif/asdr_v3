<?php

declare(strict_types=1);

test('WP CLI использует канонический ключ db', function () {
    $source = (string) file_get_contents(APP_ROOT . '/scripts/wp_import.php');
    assert_contains("Config::get('db')", $source);
    assert_not_contains("Config::get('database')", $source);
});

test('Файловый портал останавливает скачивание при rate limit', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Repo/PortalController.php');
    assert_contains("if (!RateLimiter::throttle('repo_download'", $source);
    assert_contains("http_response_code(429)", $source);
});

test('RepoFile сверяет реальный MIME с ожидаемым', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Models/RepoFile.php');
    assert_contains('$expectedMime = self::ALLOWED[$extension]', $source);
    assert_contains('Содержимое файла не соответствует расширению.', $source);
});

test('Чанки изолированы по пользователю и проверяются по порядку', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ChunkedUploadController.php');
    assert_contains("APP_ROOT . '/storage/cache/chunks'", $source);
    assert_contains("\$rootDir . '/user-' . (int) Auth::id()", $source);
    assert_contains('flock($lock, LOCK_EX)', $source);
    assert_contains("Нарушен порядок или состав чанков", $source);
    assert_contains('STALE_AFTER_SECONDS', $source);
    assert_contains('LOCK_EX | LOCK_NB', $source);
});

test('Удаление последнего канала 2FA снова ограничивает активную сессию', function () {
    $auth = (string) file_get_contents(APP_ROOT . '/app/Core/Auth.php');
    $profile = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ProfileController.php');
    assert_contains('syncTwoFactorSetup', $auth);
    assert_contains("\$_SESSION['2fa_setup_required'] = true", $auth);
    assert_contains('Auth::syncTwoFactorSetup($updatedUser)', $profile);
});

test('Web Push mutations требуют JSON, CSRF и rate limit', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/PushController.php');
    assert_contains("application/json", $source);
    assert_contains('Csrf::verify($token)', $source);
    assert_contains("RateLimiter::throttle('push_subscription'", $source);
});
