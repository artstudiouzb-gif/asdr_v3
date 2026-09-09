<?php

declare(strict_types=1);

/*
 * Загрузчик для нативного тест-раннера (без Composer/PHPUnit).
 * Регистрирует PSR-4 автозагрузчик App\ -> /app и задаёт минимальную
 * конфигурацию, НЕ открывая сессию и НЕ подключаясь к БД (это делают
 * только те тесты, которым нужна база — через Database::init).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/Core/helpers.php';

\App\Core\Config::set([
    'app' => ['env' => 'testing', 'debug' => true, 'url' => 'http://localhost', 'timezone' => 'UTC'],
    'crypto' => ['encryption_key' => str_repeat('11', 32), 'previous_encryption_key' => ''],
    'paths' => [
        'protected_uploads' => APP_ROOT . '/storage/protected_uploads',
        'public_uploads' => APP_ROOT . '/public/uploads/public',
        'public_uploads_url' => '/uploads/public',
    ],
]);

date_default_timezone_set('UTC');
