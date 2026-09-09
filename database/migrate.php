<?php

declare(strict_types=1);

/*
 * CLI-система миграций ArtStudio CMS.
 *
 *   php database/migrate.php            — накатить все новые миграции
 *   php database/migrate.php status     — показать статус (применённые/новые)
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';
require __DIR__ . '/../app/Core/MigrationRunner.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\MigrationRunner;

$config = require __DIR__ . '/../config/config.php';
Config::set($config);
Database::init($config['db']);
$pdo = Database::pdo();
$migrationsDir = __DIR__ . '/migrations';

$command = $argv[1] ?? 'migrate';

if ($command === 'status') {
    try {
        $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable) {
        $applied = [];
    }
    $applied = array_flip(array_map('strval', $applied));
    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    fwrite(STDOUT, "Миграции:\n");
    foreach ($files as $file) {
        $name = basename($file);
        $mark = isset($applied[$name]) ? '[x] применена' : '[ ] новая';
        fwrite(STDOUT, "  {$mark}  {$name}\n");
    }
    exit(0);
}

if ($command !== 'migrate') {
    fwrite(STDERR, "Неизвестная команда. Используйте migrate или status.\n");
    exit(2);
}

try {
    $pending = MigrationRunner::pending($pdo, $migrationsDir);
    if ($pending === []) {
        fwrite(STDOUT, "Нет новых миграций.\n");
        exit(0);
    }

    MigrationRunner::applyPending(
        $pdo,
        $migrationsDir,
        static function (string $event, string $name): void {
            if ($event === 'start') {
                fwrite(STDOUT, "Применяю {$name} ... ");
                return;
            }
            if ($event === 'done') {
                fwrite(STDOUT, "ok\n");
            }
        }
    );

    fwrite(STDOUT, "Готово.\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDOUT, "ОШИБКА\n");
    fwrite(STDERR, 'Миграции остановлены: ' . $e->getMessage() . "\n");
    exit(1);
}
