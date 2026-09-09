<?php

declare(strict_types=1);

/**
 * Read-only проверка сервера перед/после выпуска.
 *
 *   php scripts/release_check.php
 *   php scripts/release_check.php --allow-pending  # перед применением миграций
 *   php scripts/release_check.php --json
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';
require __DIR__ . '/../app/Core/SecretBox.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\SecretBox;

$root = dirname(__DIR__);
$jsonOutput = in_array('--json', $argv, true);
$allowPending = in_array('--allow-pending', $argv, true);
$checks = [];

$add = static function (string $name, string $status, string $message) use (&$checks): void {
    $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
};

$add('php_version', version_compare(PHP_VERSION, '8.4.0', '>=') ? 'ok' : 'error', 'PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'gd', 'curl', 'dom', 'fileinfo', 'openssl', 'zip'] as $extension) {
    $add('ext_' . $extension, extension_loaded($extension) ? 'ok' : 'error', $extension);
}

$configFile = $root . '/config/config.php';
$installedLock = $root . '/storage/installed.lock';
$add('config', is_file($configFile) ? 'ok' : 'error', 'config/config.php');
$add('install_lock', is_file($installedLock) ? 'ok' : 'error', 'storage/installed.lock');

foreach (['storage/logs', 'storage/cache', 'storage/backups', 'storage/protected_uploads', 'public/uploads/public'] as $relative) {
    $path = $root . '/' . $relative;
    $add('writable_' . str_replace('/', '_', $relative), is_dir($path) && is_writable($path) ? 'ok' : 'error', $relative);
}

$free = @disk_free_space($root);
if ($free !== false) {
    $add('disk_space', $free >= 256 * 1024 * 1024 ? 'ok' : 'warning', round($free / 1024 / 1024) . ' MiB free');
}

if (is_file($configFile)) {
    try {
        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException('config.php должен вернуть массив');
        }
        Config::set($config);

        $env = (string) ($config['app']['env'] ?? 'production');
        $debug = (bool) ($config['app']['debug'] ?? false);
        $url = (string) ($config['app']['url'] ?? '');
        $add('production_env', $env === 'production' ? 'ok' : 'warning', 'APP_ENV=' . $env);
        $add('debug_disabled', !$debug ? 'ok' : 'error', $debug ? 'APP_DEBUG включён' : 'APP_DEBUG выключен');
        $httpsUrl = str_starts_with($url, 'https://');
        $add('https_url', $httpsUrl ? 'ok' : 'error', $url !== '' ? $url : 'APP_URL не задан');

        // Схема в APP_URL — это намерение, а не факт. Раньше проверялась
        // только она, и установка, где шаг «включить HTTPS» на хостинге
        // пропущен, показывала https_url: OK, продолжая отвечать по HTTP:
        // сессионная cookie уходила без флага Secure (Session::start() берёт
        // его у RequestUrl::isHttps()), а HSTS не отправлялся вовсе — он
        // ставится только на HTTPS-ответе. Поэтому спрашиваем сам сайт: заход
        // по http:// обязан ответить постоянным редиректом на https://.
        if ($httpsUrl) {
            $plain = 'http://' . substr($url, strlen('https://'));
            // Свой curl, а не Http::request(): тот отдаёт только код и тело,
            // а здесь нужен именно адрес перенаправления — иначе «301» на
            // чужой хост прошёл бы за успех.
            // curl_init() объявлен как CurlHandle|false, и в файле со
            // strict_types false ушёл бы параметром в curl_setopt_array(),
            // то есть TypeError вместо строки отчёта.
            $ch = function_exists('curl_init') ? curl_init($plain) : false;
            if ($ch === false) {
                $add('https_redirect', 'warning', 'Не удалось опросить ' . $plain);
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $answered = curl_exec($ch) !== false;
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $location = trim((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL));

                if (!$answered || $status === 0) {
                    $add('https_redirect', 'warning', $plain . ' не ответил — проверьте вручную');
                } elseif (in_array($status, [301, 308], true) && str_starts_with(strtolower($location), 'https://')) {
                    $add('https_redirect', 'ok', $status . ' -> ' . $location);
                } else {
                    $add(
                        'https_redirect',
                        'error',
                        $plain . ' отвечает ' . $status . ($location !== '' ? ' -> ' . $location : '')
                            . ' вместо 301/308 на https. Проверьте APP_URL и редирект на хостинге.'
                    );
                }
            }
        }

        $add(
            'encryption_key',
            SecretBox::hasValidCurrentKey() ? 'ok' : 'error',
            SecretBox::hasValidCurrentKey() ? 'Ключ шифрования корректен' : 'Задайте APP_ENCRYPTION_KEY: 64 hex-символа'
        );

        Database::init((array) ($config['db'] ?? []));
        Database::pdo()->query('SELECT 1');
        $add('database', 'ok', 'Соединение установлено');

        $applied = [];
        try {
            $applied = Database::pdo()->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            $add('migrations_table', 'error', 'Таблица migrations недоступна');
        }
        $migrationFiles = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
        $pending = array_values(array_diff($migrationFiles, $applied));
        $migrationStatus = $pending === [] ? 'ok' : ($allowPending ? 'warning' : 'error');
        $add('migrations', $migrationStatus, $pending === [] ? 'Все применены' : 'Ожидают: ' . implode(', ', $pending));
    } catch (Throwable $e) {
        $add('runtime', 'error', $e->getMessage());
    }
}

$backupFiles = glob($root . '/storage/backups/*.zip') ?: [];
$latestBackup = 0;
foreach ($backupFiles as $backupFile) {
    $latestBackup = max($latestBackup, (int) filemtime($backupFile));
}
if ($latestBackup === 0) {
    $add('recent_backup', 'warning', 'Локальные резервные копии не найдены');
} else {
    $ageHours = (int) floor((time() - $latestBackup) / 3600);
    $add('recent_backup', $ageHours <= 48 ? 'ok' : 'warning', 'Последняя копия: ' . $ageHours . ' ч. назад');
}

// Репетиция восстановления. Отметку ставит database/restore.php после успешного
// разворачивания архива в отдельную БД. Раз в квартал — разумный минимум:
// без этого о нерабочем бэкапе узнают в день, когда он нужен.
$restoreMarker = $root . '/storage/backups/.last_restore_check';
if (!is_file($restoreMarker)) {
    $add('restore_drill', 'warning', 'Восстановление ни разу не проверялось (database/restore.php)');
} else {
    $drillDays = (int) floor((time() - (int) filemtime($restoreMarker)) / 86400);
    $add('restore_drill', $drillDays <= 90 ? 'ok' : 'warning', 'Последняя проверка восстановления: ' . $drillDays . ' дн. назад');
}

// Сигнал наружу. Свой сторож живёт на том же сервере: упал сервер — упал и он,
// и о простое никто не узнает. Внешний приёмник считает тишину аварией.
require_once $root . '/app/Core/HeartbeatPing.php';
$add(
    'heartbeat',
    \App\Core\HeartbeatPing::isConfigured() ? 'ok' : 'warning',
    \App\Core\HeartbeatPing::isConfigured()
        ? 'Сигнал наружу настроен (watchdog пингует приёмник)'
        : 'Сигнал наружу не настроен (MONITORING_HEARTBEAT_URL) — простой сервера некому заметить'
);

// Эталон целостности файлов (app/Console/integrity_check.php).
require_once $root . '/app/Core/Integrity.php';
$baselineAge = \App\Core\Integrity::baselineAge();
if ($baselineAge === null) {
    $add('integrity_baseline', 'warning', 'Эталон целостности не снят (integrity_check.php --baseline)');
} else {
    $add('integrity_baseline', 'ok', 'Эталон снят ' . (int) floor($baselineAge / 86400) . ' дн. назад');
}

// Доставляемость почты: без SPF/DKIM/DMARC уведомления заявителям и письма
// сброса пароля уходят в спам, причём молча — очередь считает их отправленными.
$mailHost = (string) Config::get('mail.host', '');
$fromEmail = (string) Config::get('mail.from_email', '');
if ($mailHost === '') {
    $add('mail_dns', 'warning', 'SMTP не настроен — письма не отправляются');
} elseif (!str_contains($fromEmail, '@')) {
    $add('mail_dns', 'warning', 'Не задан mail.from_email — проверить SPF/DKIM/DMARC не по чему');
} elseif (!function_exists('dns_get_record')) {
    $add('mail_dns', 'warning', 'dns_get_record недоступна — проверьте SPF/DKIM/DMARC вручную');
} else {
    $mailDomain = strtolower(substr($fromEmail, strrpos($fromEmail, '@') + 1));
    $txt = static function (string $name): array {
        $records = @dns_get_record($name, DNS_TXT);

        return is_array($records) ? $records : [];
    };
    $joined = static function (array $records): string {
        $values = [];
        foreach ($records as $record) {
            $values[] = (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? [])));
        }

        return implode("\n", $values);
    };

    $spf = $joined($txt($mailDomain));
    $dmarc = $joined($txt('_dmarc.' . $mailDomain));
    $missing = [];
    if (!str_contains(strtolower($spf), 'v=spf1')) {
        $missing[] = 'SPF';
    }
    if (!str_contains(strtolower($dmarc), 'v=dmarc1')) {
        $missing[] = 'DMARC';
    }

    $selector = trim((string) Config::get('mail.dkim_selector', ''));
    if ($selector === '') {
        $missing[] = 'DKIM (селектор не задан в mail.dkim_selector)';
    } elseif (!str_contains(strtolower($joined($txt($selector . '._domainkey.' . $mailDomain))), 'v=dkim1')) {
        $missing[] = 'DKIM (' . $selector . ')';
    }

    $add(
        'mail_dns',
        $missing === [] ? 'ok' : 'warning',
        $missing === []
            ? $mailDomain . ': SPF, DKIM, DMARC на месте'
            : $mailDomain . ': нет ' . implode(', ', $missing)
    );
}

$errors = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'error'));
$warnings = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warning'));

if ($jsonOutput) {
    echo json_encode(['ok' => $errors === 0, 'errors' => $errors, 'warnings' => $warnings, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    foreach ($checks as $check) {
        $mark = match ($check['status']) {
            'ok' => 'OK',
            'warning' => 'WARN',
            default => 'FAIL',
        };
        fwrite($check['status'] === 'error' ? STDERR : STDOUT, sprintf("[%s] %-28s %s\n", $mark, $check['name'], $check['message']));
    }
    fwrite(STDOUT, sprintf("Итог: ошибок %d, предупреждений %d.%s", $errors, $warnings, PHP_EOL));
}

exit($errors === 0 ? 0 : 1);
