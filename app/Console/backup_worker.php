<?php

declare(strict_types=1);

/*
 * Воркер согласованного бэкапа ArtStudio CMS (задача 1.2).
 *   php app/Console/backup_worker.php
 *
 * Запускать по Cron, например раз в сутки в 03:00:
 *   0 3 * * * php /path/to/app/Console/backup_worker.php >> /path/to/storage/logs/backup_worker.log 2>&1
 *
 * Одним проходом:
 *   - включает режим обслуживания на время снятия дампа (согласованность
 *     БД и файлов — настраивается config[backup][maintenance_during]);
 *   - снимает дамп БД + архивирует storage/protected_uploads и
 *     public/uploads/public в единый .zip;
 *   - кладёт рядом контрольную сумму .sha256;
 *   - дублирует архив во внешнее хранилище (config[backup][external_dir]);
 *   - ротирует локальные копии старше N дней (config[backup][retention_days]);
 *   - Logger::event(): INFO при успехе, CRITICAL при ошибке (Telegram-алерт).
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Backup;
use App\Core\Config;
use App\Core\Heartbeat;
use App\Core\Logger;
use App\Core\ProcessLock;

Heartbeat::touch('backup'); // группа 2.1

// Защита от наложения запусков: если предыдущий бэкап ещё идёт — выходим.
$lock = ProcessLock::acquire('backup_worker'); // группа 6 (единый хелпер)
if ($lock === null) {
    fwrite(STDERR, 'Предыдущий бэкап ещё выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

$maintenance = (bool) Config::get('backup.maintenance_during', true);
$retention = (int) Config::get('backup.retention_days', 14);
$keepDaily = (int) Config::get('backup.keep_daily', 7);
$keepWeekly = (int) Config::get('backup.keep_weekly', 4);

try {
    $started = microtime(true);
    $path = Backup::create($maintenance);
    $size = filesize($path);
    $sha = Backup::storedChecksum($path);

    $external = Backup::copyExternal($path);
    // keep_daily > 0 — умная схема «дневные + недельные»; keep_daily = 0 —
    // прежняя простая ротация по retention_days.
    $removed = $keepDaily > 0 ? Backup::rotateSmart($keepDaily, $keepWeekly) : Backup::rotate($retention);
    $seconds = round(microtime(true) - $started, 1);

    Logger::info('Бэкап снят успешно', [
        'file' => basename($path),
        'size' => $size,
        'sha256' => $sha,
        'external' => $external !== null ? basename($external) : 'нет (external_dir не задан)',
        'rotated' => $removed,
        'seconds' => $seconds,
    ]);

    fwrite(STDOUT, sprintf(
        "OK: %s (%d байт, %s c). Внешняя копия: %s. Ротировано: %d.%s",
        basename($path),
        $size,
        $seconds,
        $external !== null ? 'да' : 'нет',
        $removed,
        PHP_EOL
    ));
    exit(0);
} catch (\Throwable $e) {
    // Критично: провал бэкапа = единственная точка отказа. Шлём CRITICAL-алерт.
    Logger::critical('Бэкап НЕ СНЯТ (ошибка воркера)', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    // Дублируем алерт через бота из настроек (канал заявок) — он настроен
    // чаще, чем config[telegram] для алертов логов.
    \App\Core\FormNotifier::broadcast("\u{1F534} Автобэкап НЕ снят: " . $e->getMessage());
    fwrite(STDERR, 'ОШИБКА бэкапа: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    ProcessLock::release($lock);
}
