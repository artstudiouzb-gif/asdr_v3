<?php

declare(strict_types=1);

/*
 * Воркер переезда на другой хостинг (раздел «Переезд» в панели).
 *   php app/Console/migration_worker.php
 *
 * Cron (раз в минуту, пока идёт переезд; потом задание можно снять):
 *   * * * * * php /path/to/app/Console/migration_worker.php >> /path/to/storage/logs/migration_worker.log 2>&1
 *
 * Панель только заказывает: снятие пакета с медиатекой и тем более замена
 * базы длиннее любого таймаута веб-запроса, а обрыв посреди замены оставил
 * бы базу собранной наполовину. Работу делает этот процесс — из командной
 * строки, без таймаута. Последовательность та же, что у
 * `scripts/site_migrate.php` (`App\Core\SiteMigration`).
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Logger;
use App\Core\ProcessLock;
use App\Core\SiteMigration;
use App\Core\SiteMigrationState;

Heartbeat::touch('migration');

// Блокировка до чтения состояния: два запуска cron подряд иначе оба взяли бы
// одну задачу.
$lock = ProcessLock::acquire('migration_worker');
if ($lock === null) {
    fwrite(STDOUT, 'migration_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    $state = SiteMigrationState::read();

    // «Выполняется», а блокировку мы взяли — значит, прошлый процесс убит
    // (таймаут хостинга, OOM). Ждать отметки времени не нужно: живой
    // воркер держал бы блокировку.
    if ($state['status'] === SiteMigrationState::STATUS_RUNNING) {
        SiteMigrationState::finish(
            SiteMigrationState::STATUS_FAILED,
            'процесс оборвался посреди работы. Если шла установка, проверьте сайт: при поломке разверните '
                . 'страховочную копию из storage/backups (снята перед заменой базы).'
        );
        Logger::error('Переезд: процесс воркера оборвался.');
        exit(1);
    }
    if ($state['status'] !== SiteMigrationState::STATUS_QUEUED) {
        fwrite(STDOUT, 'Задач переезда нет.' . PHP_EOL);
        exit(0);
    }

    $password = SiteMigrationState::takePassword();
    SiteMigrationState::markRunning();
    $report = static function (string $line): void {
        SiteMigrationState::step($line);
        fwrite(STDOUT, '  ✓ ' . $line . PHP_EOL);
    };

    try {
        if ($state['task'] === SiteMigrationState::TASK_EXPORT) {
            $report('Снимаю пакет: база, загрузки и манифест' . ($password !== null ? ', шифрование AES-256' : '') . '…');
            $result = SiteMigration::export($password);
            $report('Пакет готов: ' . basename($result['archive']) . ' ('
                . round((int) filesize($result['archive']) / 1048576, 1) . ' МБ).');
            SiteMigrationState::finish(SiteMigrationState::STATUS_DONE, '', basename($result['archive']));
            Logger::security('Снят пакет переезда', ['file' => basename($result['archive'])]);
        } elseif ($state['task'] === SiteMigrationState::TASK_IMPORT) {
            $archive = SiteMigration::resolve(SiteMigration::incomingDir(), $state['archive']);
            if ($archive === null) {
                throw new \RuntimeException('архив ' . $state['archive'] . ' пропал из ' . SiteMigration::incomingDir() . '.');
            }
            SiteMigration::import($archive, [
                'confirm' => SiteMigration::CONFIRM_CODE,
                'from_url' => $state['options']['from_url'] !== '' ? $state['options']['from_url'] : null,
                'to_url' => $state['options']['to_url'] !== '' ? $state['options']['to_url'] : null,
                'plain_backup' => $state['options']['plain_backup'],
                'password' => $password,
            ], $report);
            SiteMigrationState::finish(SiteMigrationState::STATUS_DONE);
            Logger::security('Установлен пакет переезда', ['file' => $state['archive']]);
        } else {
            throw new \RuntimeException('неизвестная задача.');
        }
    } catch (\Throwable $e) {
        SiteMigrationState::finish(SiteMigrationState::STATUS_FAILED, $e->getMessage());
        Logger::error('Переезд остановлен: ' . $e->getMessage());
        fwrite(STDERR, 'Остановлено: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
} finally {
    SiteMigrationState::clearPassword();
    ProcessLock::release($lock);
}

exit(0);
