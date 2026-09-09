<?php

declare(strict_types=1);

/*
 * Репетиция восстановления из бэкапа.
 *   php app/Console/restore_drill.php                  # свежий архив в базу <боевая>_restore_check
 *   php app/Console/restore_drill.php --db=имя_базы    # своя база для репетиции
 *   php app/Console/restore_drill.php --archive=путь   # конкретный архив
 *
 * Раз в неделю (пример cron):
 *   40 3 * * 0 php /path/to/app/Console/restore_drill.php >> storage/logs/restore-drill.log 2>&1
 *
 * Зачем: копия, которую ни разу не восстанавливали, бэкапом не является.
 * Ручной `database/restore.php` требует трёх аргументов, поэтому на практике
 * его не запускают, и release_check годами повторяет «восстановление ни разу
 * не проверялось». Здесь всё то же самое, но само и с проверкой содержимого.
 *
 * Боевую базу и боевые загрузки не трогает: разворачивает в отдельную базу и
 * свой временный каталог, после себя убирает.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\FormNotifier;
use App\Core\RestoreDrill;

$targetDb = '';
$archive = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $targetDb = substr($arg, 5);
    }
    if (str_starts_with($arg, '--archive=')) {
        $archive = substr($arg, 10);
    }
}

$result = RestoreDrill::run($targetDb, $archive);

echo 'Репетиция восстановления: ' . ($result['ok'] ? 'УСПЕХ' : 'НЕУДАЧА') . PHP_EOL;
echo '  архив: ' . ($result['archive'] !== '' ? $result['archive'] : '—') . PHP_EOL;
echo '  база: ' . $result['database'] . PHP_EOL;
echo '  таблиц: ' . $result['tables'] . ', файлов: ' . $result['files'] . PHP_EOL;
foreach ($result['messages'] as $message) {
    echo '  - ' . $message . PHP_EOL;
}

if ($result['ok']) {
    RestoreDrill::writeMarker($result);
    exit(0);
}

// Неудачная репетиция — это сообщение о том, что бэкапов, по сути, нет.
// Молчать о таком нельзя: узнать об этом в день аварии слишком поздно.
FormNotifier::broadcast(
    "Репетиция восстановления не удалась\n"
    . 'Архив: ' . ($result['archive'] !== '' ? $result['archive'] : 'не найден') . "\n"
    . implode("\n", array_map(static fn (string $m): string => '• ' . $m, $result['messages']))
);

exit(1);
