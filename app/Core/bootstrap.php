<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Глобальные помощники шаблонов (t() — перевод интерфейса).
require __DIR__ . '/helpers.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\ErrorHandler;
use App\Core\SecurityHeaders;

define('APP_ROOT', dirname(__DIR__, 2));

$configFile = APP_ROOT . '/config/config.php';
$installedLock = APP_ROOT . '/storage/installed.lock';

// Система считается установленной, когда есть и config.php, и файл-маркер.
define('APP_INSTALLED', is_file($configFile) && is_file($installedLock));

ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

// Заголовки Forwarded контролируются клиентом, если origin доступен напрямую.
// До отправки security headers удаляем их для любого peer, который не входит
// в явный allowlist reverse proxy. Для доверенного proxy затем безопасно
// определяем реальный IP посетителя.
$prepareHttpSecurity = static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $peer = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($peer === '' || !\App\Core\ClientIp::isTrustedProxy($peer)) {
        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED_PROTO',
            'HTTP_X_FORWARDED_HOST',
            'HTTP_X_FORWARDED_PORT',
            'HTTP_FORWARDED',
        ] as $header) {
            unset($_SERVER[$header]);
        }
    }

    \App\Core\ClientIp::applyTrustedProxy();
    // Выставляем заголовки после загрузки Config, но до подключения к БД:
    // так они попадают и на 404/500/503, а trusted proxy policy уже известна.
    SecurityHeaders::send();
    if (!headers_sent()) {
        header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    }

    // Принудительный HTTPS, когда боевой адрес объявлен как https (условия и
    // причина — в RequestUrl::httpsRedirectTarget). Стоит сразу после
    // applyTrustedProxy(): к этому моменту X-Forwarded-Proto от недоверенного
    // peer уже снят, то есть схему нельзя подделать заголовком. И до
    // подключения к БД — незашифрованный запрос не должен доходить до данных.
    $httpsTarget = \App\Core\RequestUrl::httpsRedirectTarget();
    if ($httpsTarget !== null && !headers_sent()) {
        // 301 кэшируется браузером и понятен поиску, но превращает POST в GET
        // и теряет тело. Для небезопасных методов 308: он тоже постоянный, но
        // обязывает повторить запрос тем же методом.
        $permanent = in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
            ? 301
            : 308;
        header('Location: ' . $httpsTarget, true, $permanent);
        exit;
    }
};

if (is_file($configFile)) {
    $config = require $configFile;
    Config::set($config);

    // Runtime-настройка скрытого входа хранится вне public/ и должна быть
    // подмешана до AdminEntryGate::enforce(). Переменные окружения сохраняют
    // высший приоритет внутри AdminEntryConfig.
    if (APP_INSTALLED) {
        \App\Core\AdminEntryConfig::loadIntoConfig();
    }

    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
    ErrorHandler::register((bool) ($config['app']['debug'] ?? false));
    $prepareHttpSecurity();

    if (APP_INSTALLED) {
        // Inspect the request before opening MySQL so obvious malicious input
        // cannot consume a database connection first.
        \App\Core\WafGuard::inspect();

        // Рабочий режим: недоступность БД -> брендированный 503 (fail-safe),
        // без вывода системного трейса.
        try {
            Database::init($config['db']);
        } catch (\Throwable $e) {
            \App\Core\Logger::critical('Падение БД (503): ' . $e->getMessage(), [
                'url' => $_SERVER['REQUEST_URI'] ?? 'cli',
            ]);
            if (PHP_SAPI !== 'cli') {
                $failedPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
                if ($failedPath === '/health') {
                    // Fail-safe health endpoint не раскрывает release, storage
                    // и внутреннюю структуру проверок неавторизованному клиенту.
                    http_response_code(503);
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Cache-Control: no-store');
                    header('Retry-After: 60');
                    echo json_encode(['status' => 'down'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // Вчерашняя копия лучше заглушки: содержимое настоящее,
                // просто не самое свежее. Файловый кеш базы не требует,
                // поэтому работает именно тогда, когда она недоступна.
                if (\App\Core\PublicResponseCache::tryServeStale()) {
                    exit;
                }

                http_response_code(503);
                header('Retry-After: 60');
                $view = APP_ROOT . '/app/Views/errors/503.php';
                echo is_file($view) ? file_get_contents($view) : 'Сервис временно недоступен.';
                exit;
            }
            throw $e;
        }
    } else {
        // Установка ещё не завершена, но config.php уже есть (после шага БД).
        // Сначала подключаемся мягко: если сервер БД недоступен, установщик сам
        // покажет ошибку. Но если соединение есть, структура обязана быть
        // доведена до текущей версии ДО открытия следующих шагов и демо-сидера.
        $installDbConnected = false;
        try {
            Database::init($config['db']);
            $installDbConnected = true;
        } catch (\Throwable $e) {
            // БД ещё может быть недоступна — установщик покажет ошибку сам.
        }

        if ($installDbConnected) {
            try {
                \App\Core\MigrationRunner::applyPending(
                    Database::pdo(),
                    APP_ROOT . '/database/migrations'
                );
            } catch (\Throwable $e) {
                // Не продолжаем установку на частично обновлённой схеме:
                // иначе ошибка проявится позже в моделях как Unknown column.
                throw new \RuntimeException(
                    'Не удалось применить обязательные миграции чистой установки: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }
} else {
    // Режим установки: config.php ещё нет. Работаем на минимальных дефолтах.
    Config::set(['app' => ['env' => 'production', 'debug' => true, 'url' => '', 'timezone' => 'UTC']]);
    date_default_timezone_set('UTC');
    ErrorHandler::register(true);
    $prepareHttpSecurity();
}

// Для обычного публичного GET без cookie сессию не создаём. Компоненты,
// которым она нужна (Auth, CSRF, Flash, CAPTCHA), запускают её сами.
if (PHP_SAPI !== 'cli' && \App\Core\Session::hasCookie()) {
    \App\Core\Session::start();
}

// Скрытый административный шлюз должен сработать до регистрации маршрутов:
// прямые неавторизованные /admin/* выглядят как обычный 404 и не успевают
// раскрыть форму входа, сброс пароля или внутренний маршрут панели.
if (PHP_SAPI !== 'cli') {
    \App\Core\AdminEntryGate::enforce();

    $bootstrapPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $bootstrapMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // Настройка шлюза обслуживается до общего Router, чтобы механизм оставался
    // автономным и не раскрывал отдельный маршрут неавторизованному сканеру.
    if ($bootstrapPath === '/admin/security/admin-entry' && $bootstrapMethod === 'POST') {
        (new \App\Controllers\Admin\AdminEntrySettingsController())->update();
    }

    // Центр уведомлений подключён как изолированный admin-модуль. Gateway уже
    // отработал, а сам контроллер повторно требует живую сессию и CSRF для POST.
    if (APP_INSTALLED && \App\Controllers\Admin\NotificationController::dispatch($bootstrapPath, $bootstrapMethod)) {
        exit;
    }
}
