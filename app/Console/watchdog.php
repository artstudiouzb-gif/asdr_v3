<?php

declare(strict_types=1);

/*
 * Сторож тишины: сообщает о том, что перестало работать без ошибки.
 *   php app/Console/watchdog.php
 *
 * Cron (каждые 15 минут):
 *   [мин]/15 * * * * php /path/to/app/Console/watchdog.php >> /path/to/storage/logs/watchdog.log 2>&1
 *
 * Проверяет молчащие воркеры (cron перестал их вызывать), застрявшие очереди
 * публикаций и писем, свободное место на диске. О каждой проблеме сообщает в
 * Telegram один раз и отдельно — когда она ушла.
 *
 * За себя сторож не отвечает: если сервер или cron умерли, некому и сообщить.
 * Поэтому чистый проход отмечается сигналом наружу (`App\Core\HeartbeatPing`,
 * адрес в MONITORING_HEARTBEAT_URL) — тревогу поднимет приёмник, когда сигнал
 * перестанет приходить.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\HeartbeatPing;
use App\Core\Logger;
use App\Core\ProcessLock;
use App\Core\Watchdog;

$lock = ProcessLock::acquire('watchdog');
if ($lock === null) {
    fwrite(STDERR, 'watchdog уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    $result = Watchdog::run();

    if ($result['new'] !== []) {
        Logger::warning('Сторож: обнаружены остановки — ' . implode(', ', $result['new']));
    }
    if ($result['resolved'] !== []) {
        Logger::info('Сторож: восстановлено — ' . implode(', ', $result['resolved']));
    }

    foreach ($result['problems'] as $key => $text) {
        fwrite(STDOUT, sprintf("[%s] %s%s", $key, $text, PHP_EOL));
    }
    fwrite(STDOUT, sprintf(
        'Проблем: %d, новых: %d, восстановлено: %d.%s',
        count($result['problems']),
        count($result['new']),
        count($result['resolved']),
        PHP_EOL
    ));

    // Сигнал наружу — только по чистому проходу: приёмник считает тишину
    // аварией, и пинг при известных проблемах прятал бы их от него.
    if (HeartbeatPing::isConfigured()) {
        if ($result['problems'] === []) {
            $sent = HeartbeatPing::send();
            fwrite(STDOUT, ($sent ? 'Сигнал наружу отправлен.' : 'Сигнал наружу не ушёл — см. журнал.') . PHP_EOL);
        } else {
            fwrite(STDOUT, 'Сигнал наружу пропущен: есть незакрытые проблемы.' . PHP_EOL);
        }
    }
} finally {
    ProcessLock::release($lock);
}

exit(0);
