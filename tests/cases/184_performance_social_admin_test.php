<?php

declare(strict_types=1);

use App\Core\Cache;
use App\Core\ProcessLock;

test('Cache::flush очищает контент, но сохраняет блокировки, rate-limit и загрузки', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $root = APP_ROOT . '/storage/cache';
    $rateFile = $root . '/rate-limits/test-' . $suffix . '.limit';
    $chunkFile = $root . '/chunks/test-' . $suffix . '.part';
    @mkdir(dirname($rateFile), 0750, true);
    @mkdir(dirname($chunkFile), 0750, true);
    file_put_contents($rateFile, '{}');
    file_put_contents($chunkFile, 'part');
    Cache::put('flush-test:' . $suffix, 'cached');

    $lockName = 'flush_test_' . $suffix;
    $lock = ProcessLock::acquire($lockName);
    assert_true(is_resource($lock), 'тестовая блокировка получена');
    $lockFile = $root . '/' . $lockName . '.lock';

    Cache::flush();
    assert_same(null, Cache::get('flush-test:' . $suffix));
    assert_true(is_file($rateFile), 'rate-limit не удалён');
    assert_true(is_file($chunkFile), 'незавершённая загрузка не удалена');
    assert_true(is_file($lockFile), 'блокировка воркера не удалена');

    ProcessLock::release($lock);
    @unlink($rateFile);
    @unlink($chunkFile);
    @unlink($lockFile);
});

test('Производительность: форма сбалансирована и использует строгие валидаторы', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php');

    assert_same(substr_count($view, '<div'), substr_count($view, '</div>'));
    assert_same(substr_count($view, '<form'), substr_count($view, '</form>'));
    assert_contains('SettingsValidator::boundedInt', $controller);
    assert_contains('SettingsValidator::publicBaseUrl', $controller);
    assert_contains("Cache::forgetPrefix('page:')", $controller);
    assert_contains('settings-jump-nav', $view);
    assert_contains('pattern="[A-Fa-f0-9]{32}"', $view);
});

test('Авто-публикация: ручной запуск разделяет lock с Cron и heartbeat ставится после lock', function (): void {
    $settings = (string) file_get_contents(APP_ROOT . '/app/Core/SocialSettings.php');
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/social_worker.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SocialController.php');

    assert_contains("ProcessLock::acquire('social_worker')", $settings);
    assert_contains("SocialSettings::dispatchQueue(5)", $controller);
    $lockPos = strpos($worker, "ProcessLock::acquire('social_worker')");
    $heartbeatPos = strpos($worker, "Heartbeat::touch('social')");
    assert_true($lockPos !== false && $heartbeatPos !== false && $lockPos < $heartbeatPos);
});

test('Авто-публикация: есть валидация платформ и ручной повтор failed-записей', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SocialController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/settings/social.php');
    $model = (string) file_get_contents(APP_ROOT . '/app/Models/SocialPost.php');

    assert_contains('configurationErrors', $controller);
    assert_contains("'/admin/social/retry'", $routes);
    assert_contains('/admin/social/retry', $view);
    assert_contains('retryFailed', $model);
    assert_contains('settings-jump-nav', $view);
    assert_same(substr_count($view, '<form'), substr_count($view, '</form>'));
});
