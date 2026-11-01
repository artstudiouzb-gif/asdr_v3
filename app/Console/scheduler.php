<?php

declare(strict_types=1);

/*
 * Планировщик: одна строка cron вместо отдельной на каждый воркер.
 *   * * * * * php /path/to/app/Console/scheduler.php >> /path/to/storage/logs/scheduler.log 2>&1
 *
 * Каждую минуту запускает в фоне воркеры, которым пора по расписанию
 * (App\Core\Scheduler::TASKS). Расписание и последние запуски видны в
 * «Состоянии системы» → «Расписание».
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Scheduler;

Heartbeat::touch('scheduler');

if (!Scheduler::canSpawn()) {
    fwrite(STDERR, 'proc_open отключён на этом хостинге — запуск воркеров невозможен. Заведите строки cron для воркеров по отдельности.' . PHP_EOL);
    exit(1);
}

$started = Scheduler::tick(time());
if ($started !== []) {
    fwrite(STDOUT, date('Y-m-d H:i') . ' запущено: ' . implode(', ', $started) . PHP_EOL);
}
exit(0);
