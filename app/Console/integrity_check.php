<?php

declare(strict_types=1);

/*
 * Контроль целостности файлов сайта.
 *   php app/Console/integrity_check.php --baseline   # снять эталон (после релиза)
 *   php app/Console/integrity_check.php              # сверить с эталоном
 *
 * Раз в час (пример cron):
 *   17 * * * * php /path/to/app/Console/integrity_check.php >> storage/logs/integrity.log 2>&1
 *
 * Зачем: дефейс и веб-шелл — самый частый инцидент у госсайта, и замеченным он
 * обычно становится по звонку, а не по алерту. Скрипт хранит SHA-256 всех
 * исполняемых файлов и шаблонов и сообщает в Telegram о новых, изменённых и
 * удалённых. Загрузки, кэш, логи и сгенерированные ассеты не отслеживаются:
 * они меняются в обычной работе.
 *
 * После планового обновления кода эталон снимается заново (`--baseline`),
 * иначе каждый релиз будет выглядеть как взлом. `scripts/release.php` делает
 * это сам.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\FormNotifier;
use App\Core\Integrity;
use App\Core\Logger;
use App\Core\ProcessLock;

$baseline = in_array('--baseline', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$lock = ProcessLock::acquire('integrity_check');
if ($lock === null) {
    fwrite(STDERR, 'integrity_check уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    if ($baseline) {
        $count = Integrity::writeBaseline();
        fwrite(STDOUT, sprintf("Эталон обновлён: %d файлов.%s", $count, PHP_EOL));
        exit(0);
    }

    if (!Integrity::hasBaseline()) {
        fwrite(STDERR, "Эталон не снят. Выполните: php app/Console/integrity_check.php --baseline\n");
        exit(2);
    }

    $diff = Integrity::compare();
    $total = count($diff['added']) + count($diff['changed']) + count($diff['removed']);

    if ($total === 0) {
        if (!$quiet) {
            fwrite(STDOUT, "Расхождений нет.\n");
        }
        Integrity::rememberReported([]);
        exit(0);
    }

    foreach (['changed' => 'Изменены', 'added' => 'Появились', 'removed' => 'Удалены'] as $key => $title) {
        if ($diff[$key] !== []) {
            fwrite(STDERR, $title . ' (' . count($diff[$key]) . "):\n");
            foreach ($diff[$key] as $path) {
                fwrite(STDERR, '  ' . $path . "\n");
            }
        }
    }

    Logger::security('Контроль целостности: расхождения с эталоном', [
        'added' => count($diff['added']),
        'changed' => count($diff['changed']),
        'removed' => count($diff['removed']),
    ]);

    // Повторно об одном и том же наборе не пишем: иначе почасовой cron
    // превратит канал уведомлений в шум и алерт перестанут читать.
    if (Integrity::isNewReport($diff)) {
        FormNotifier::broadcast(Integrity::alertText($diff));
    }
    Integrity::rememberReported($diff);

    exit(1);
} finally {
    ProcessLock::release($lock);
}
