<?php

declare(strict_types=1);

/*
 * Восстановление бэкапа ArtStudio CMS в ОТДЕЛЬНУЮ тестовую БД и каталог
 * (задача 1.2). НЕ трогает боевую базу и боевые загрузки — предназначен для
 * регулярной проверки, что бэкапы реально разворачиваются.
 *
 *   php database/restore.php <путь-к-архиву.zip> <тестовая_БД> <каталог-файлов>
 *
 * Пример:
 *   php database/restore.php storage/backups/backup_2026-07-06_030000.zip \
 *       artstudio_restore_check /tmp/artstudio_restore_files
 *
 * Параметры подключения к тестовой БД берутся из config[db] (host/port/
 * username/password), меняется только имя базы. Целевая БД должна существовать
 * и быть пустой (её содержимое будет перезаписано дампом).
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Backup;
use App\Core\Config;

$zip = $argv[1] ?? '';
$targetDb = $argv[2] ?? '';
$filesDir = $argv[3] ?? '';

if ($zip === '' || $targetDb === '' || $filesDir === '') {
    fwrite(STDERR, "Использование: php database/restore.php <архив.zip> <тестовая_БД> <каталог-файлов>\n");
    exit(2);
}
if (!is_file($zip)) {
    fwrite(STDERR, "Архив не найден: {$zip}\n");
    exit(2);
}

$db = [
    'host' => (string) Config::get('db.host', '127.0.0.1'),
    'port' => (string) Config::get('db.port', '3306'),
    'database' => $targetDb,
    'username' => (string) Config::get('db.username', 'root'),
    'password' => (string) Config::get('db.password', ''),
];

fwrite(STDOUT, "Восстановление {$zip} → БД «{$targetDb}», файлы → {$filesDir}\n");
$started = microtime(true);

try {
    $report = Backup::restore($zip, $db, $filesDir);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ОШИБКА: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($report['messages'] as $m) {
    fwrite(STDOUT, '  - ' . $m . "\n");
}
// Отметка об успешной репетиции восстановления. По ней `release_check.php`
// напоминает, что бэкапы давно не разворачивали: копия, которую ни разу не
// восстанавливали, бэкапом не является.
if ($report['ok']) {
    $marker = APP_ROOT . '/storage/backups/.last_restore_check';
    @file_put_contents($marker, json_encode([
        'checked_at' => date('c'),
        'archive' => basename($zip),
        'tables' => $report['tables'],
        'files' => $report['files'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
}

$seconds = round(microtime(true) - $started, 1);
fwrite(STDOUT, sprintf(
    "Итог: %s. Таблиц: %d, файлов: %d. Контрольная сумма: %s. Заняло: %s c.\n",
    $report['ok'] ? 'УСПЕХ' : 'НЕУДАЧА',
    $report['tables'],
    $report['files'],
    $report['checksum'] ? 'подтверждена' : 'не проверена',
    $seconds
));

exit($report['ok'] ? 0 : 1);
