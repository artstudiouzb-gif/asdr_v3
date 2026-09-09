<?php

declare(strict_types=1);

/*
 * Автозагрузка роликов с YouTube-канала в раздел «Видео».
 *   php app/Console/youtube_worker.php [--force]
 *
 * Cron (например, раз в час):
 *   0 * * * * php /path/to/app/Console/youtube_worker.php >> /path/to/storage/logs/youtube_worker.log 2>&1
 *
 * Забирает с канала карточки роликов (название, описание, ссылку и
 * кадр-превью) и заводит недостающие записи. Само видео не скачивается.
 * Без галочки «Загружать автоматически» в админке проход пропускается —
 * ключ --force запускает импорт независимо от неё (для ручной проверки).
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Logger;
use App\Core\ProcessLock;
use App\Core\YoutubeImport;

$force = in_array('--force', $argv, true);
$cfg = YoutubeImport::settings();

if (!$force && !$cfg['enabled']) {
    fwrite(STDOUT, 'Автозагрузка с YouTube выключена в админке — пропуск.' . PHP_EOL);
    exit(0);
}
if (!YoutubeImport::isConfigured()) {
    fwrite(STDERR, 'Канал YouTube не указан или адрес не разобран — пропуск.' . PHP_EOL);
    exit(0);
}

$lock = ProcessLock::acquire('youtube_worker');
if ($lock === null) {
    fwrite(STDERR, 'youtube_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}
Heartbeat::touch('youtube');

try {
    $result = YoutubeImport::sync(null);
    if (!$result['ok']) {
        Logger::error('YouTube-импорт не удался: ' . $result['error']);
        fwrite(STDERR, 'Импорт не удался: ' . $result['error'] . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'YouTube-импорт: ' . $result['summary'] . '.' . PHP_EOL);
} finally {
    ProcessLock::release($lock);
}

exit(0);
