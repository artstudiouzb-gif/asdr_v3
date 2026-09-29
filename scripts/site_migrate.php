<?php

declare(strict_types=1);

/*
 * Переезд сайта на другой хостинг (App\Core\SiteMigration, docs/DEPLOY.md,
 * раздел «Переезд на другой хостинг»).
 *
 * То же делает раздел «Переезд» в панели (/admin/migration) — через воркер
 * по cron; консоль остаётся на случай, когда панель недоступна.
 *
 * На СТАРОМ сервере:
 *   php scripts/site_migrate.php export [--no-encrypt]
 *     → storage/migration/outgoing/backup_<дата>-migration.zip и .sha256
 *       рядом: база, загрузки и манифест переезда (адрес, версия, отпечаток
 *       ключа шифрования). Пароль архива спрашивается с клавиатуры (или
 *       берётся из ASDR_MIGRATION_PASSWORD) — аргументом его не передаём:
 *       он остался бы в истории оболочки и в списке процессов.
 *
 * На НОВОМ сервере (код уже выложен, config/config.php заполнен, установщик
 * пройден или база пустая):
 *   php scripts/site_migrate.php check  <архив.zip>
 *     → только проверки: окружение, база, сумма архива, ключ шифрования.
 *   php scripts/site_migrate.php import <архив.zip> --confirm=MIGRATE
 *       [--from-url=https://старый.uz] [--to-url=https://новый.uz] [--plain-backup]
 *     → замена базы и загрузок, миграции, ссылки на новый адрес, права,
 *       эталон целостности, кэш.
 *   php scripts/site_migrate.php urls --from-url=… --to-url=… [--apply]
 *     → только замена абсолютных ссылок (по умолчанию — подсчёт).
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\SiteMigration;

$command = $argv[1] ?? '';
$archive = '';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[str_replace('-', '_', $m[1])] = $m[2] ?? true;
    } elseif ($archive === '') {
        $archive = $arg;
    }
}

$out = static function (string $line): void {
    fwrite(STDOUT, '  ✓ ' . $line . PHP_EOL);
};

// Пароль — из окружения или с клавиатуры без эха.
$askPassword = static function (string $prompt, bool $twice): string {
    $env = getenv('ASDR_MIGRATION_PASSWORD');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $read = static function (string $label): string {
        fwrite(STDOUT, $label);
        $tty = function_exists('posix_isatty') && posix_isatty(STDIN) && function_exists('shell_exec');
        if ($tty) {
            shell_exec('stty -echo');
        }
        $line = fgets(STDIN);
        if ($tty) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return rtrim((string) $line, "\r\n");
    };
    $password = $read($prompt);
    if ($twice && !hash_equals($password, $read('Ещё раз: '))) {
        throw new \RuntimeException('Пароли не совпадают.');
    }

    return $password;
};

try {
    switch ($command) {
        case 'export':
            fwrite(STDOUT, "Пакет переезда: база, загрузки и манифест…\n");
            $password = ($options['no_encrypt'] ?? false)
                ? null
                : $askPassword('Пароль архива (не короче ' . SiteMigration::PASSWORD_MIN . ' знаков): ', true);
            $result = SiteMigration::export($password);
            $manifest = $result['manifest'];
            $out('Архив: ' . $result['archive'] . ' (' . round((int) filesize($result['archive']) / 1048576, 1) . ' МБ)');
            $out('Сумма: ' . basename($result['archive']) . '.sha256 — переносите вместе с архивом.');
            $out('Адрес сайта: ' . ($manifest['app_url'] ?: 'не задан'));
            if (is_string($manifest['key_fingerprint'])) {
                fwrite(STDOUT, "\nКлюч шифрования (отпечаток {$manifest['key_fingerprint']}) архив НЕ несёт.\n"
                    . "Перенесите APP_ENCRYPTION_KEY из config/config.php или переменных окружения этого сервера\n"
                    . "на новый — без него коды входа и токены интеграций там не расшифруются.\n");
            }
            break;

        case 'check':
            if ($archive === '') {
                throw new \RuntimeException('Укажите архив: php scripts/site_migrate.php check <архив.zip>');
            }
            $password = SiteMigration::isEncrypted($archive) ? $askPassword('Пароль архива: ', false) : null;
            SiteMigration::preflight($archive, (bool) ($options['plain_backup'] ?? false), $out, $password);
            $manifest = SiteMigration::readManifest($archive);
            if ($manifest !== null) {
                $out('Пакет снят ' . $manifest['created_at'] . ' с ' . ($manifest['app_url'] ?: 'адреса без настройки')
                    . ', версия ' . $manifest['release'] . ', PHP ' . $manifest['php'] . '.');
                $to = rtrim((string) Config::get('app.url', ''), '/');
                if ($manifest['app_url'] !== '' && $to !== '' && $manifest['app_url'] !== $to) {
                    fwrite(STDOUT, "\nАдрес меняется: {$manifest['app_url']} → {$to}. Ссылки в материалах import заменит сам.\n");
                }
            }
            fwrite(STDOUT, "\nПроверки пройдены. Установка: php scripts/site_migrate.php import {$archive} --confirm=MIGRATE\n");
            break;

        case 'import':
            if ($archive === '') {
                throw new \RuntimeException('Укажите архив: php scripts/site_migrate.php import <архив.zip> --confirm=MIGRATE');
            }
            $password = is_file($archive) && SiteMigration::isEncrypted($archive) ? $askPassword('Пароль архива: ', false) : null;
            SiteMigration::import($archive, [
                'password' => $password,
                'confirm' => (string) ($options['confirm'] ?? ''),
                'from_url' => isset($options['from_url']) ? (string) $options['from_url'] : null,
                'to_url' => isset($options['to_url']) ? (string) $options['to_url'] : null,
                'plain_backup' => (bool) ($options['plain_backup'] ?? false),
            ], $out);
            fwrite(STDOUT, "\nПереезд завершён. Осталось:\n"
                . "  1. Cron: строки из docs/DEPLOY.md, раздел 6, с путём этого сервера.\n"
                . "  2. DNS домена — на новый сервер; HTTPS — сертификат на хостинге.\n"
                . "  3. php scripts/release_check.php и php scripts/smoke.php <адрес> --admin …\n"
                . "  4. Старый сервер держите до проверки: он — ваша копия на случай отката.\n");
            break;

        case 'urls':
            $from = (string) ($options['from_url'] ?? '');
            $to = (string) ($options['to_url'] ?? '');
            if ($from === '' || $to === '') {
                throw new \RuntimeException('Укажите --from-url и --to-url.');
            }
            $apply = (bool) ($options['apply'] ?? false);
            $counts = SiteMigration::replaceUrl(Database::pdo(), $from, $to, !$apply);
            $out(($counts === [] ? 'Ссылок на ' . $from . ' нет.' : SiteMigration::describeCounts($counts))
                . ($apply ? ' Заменено.' : ' (подсчёт; замена — с --apply)'));
            break;

        default:
            fwrite(STDERR, "Команды: export [--no-encrypt] | check <архив> | import <архив> --confirm=MIGRATE | urls --from-url= --to-url= [--apply]\n"
                . "Подробности — docs/DEPLOY.md, «Переезд на другой хостинг».\n");
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '  ✗ ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
