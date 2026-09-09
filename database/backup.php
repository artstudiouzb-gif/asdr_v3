<?php

declare(strict_types=1);

/*
 * CLI-бэкап ArtStudio CMS.
 *   php database/backup.php
 * Создаёт storage/backups/backup_YYYY-MM-DD_HHMMSS.zip (дамп БД + загрузки).
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Backup;

try {
    $path = Backup::create();
    fwrite(STDOUT, 'Бэкап создан: ' . $path . PHP_EOL);
    fwrite(STDOUT, 'Размер: ' . round(filesize($path) / 1024, 1) . ' КБ' . PHP_EOL);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка бэкапа: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
