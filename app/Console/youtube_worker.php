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

use App\Core\BackgroundTasks;

$result = BackgroundTasks::run('youtube', in_array('--force', $argv, true));
if (!$result['ran']) {
    fwrite(STDOUT, 'Пропуск: ' . $result['message'] . '.' . PHP_EOL);
    exit(0);
}
if (!$result['ok']) {
    fwrite(STDERR, 'Не удалось: ' . $result['message'] . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'YouTube-импорт: ' . $result['message'] . '.' . PHP_EOL);
exit(0);
