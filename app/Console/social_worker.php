<?php

declare(strict_types=1);

/*
 * Воркер очереди авто-публикаций в соцсети ArtStudio CMS.
 *   php app/Console/social_worker.php
 *
 * Запускать по Cron (например, раз в 5 минут — «пятая звёздочка/5»):
 *   [мин/5] * * * * php /path/to/app/Console/social_worker.php >> /path/to/storage/logs/social_worker.log 2>&1
 *
 * Забирает pending-публикации из social_posts и публикует их через API
 * соответствующей сети (App\Core\SocialPublisher). При ошибке увеличивает
 * счётчик попыток; после трёх неудач публикация помечается как failed.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

$workerLock = \App\Core\ProcessLock::acquire('social_worker'); // группа 6
if ($workerLock === null) {
    fwrite(STDERR, 'social_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}
\App\Core\Heartbeat::touch('social'); // отмечаем только реально начавший работу процесс

use App\Core\Logger;
use App\Core\SocialPublisher;
use App\Core\SocialSettings;
use App\Models\SocialPost;

$batch = SocialPost::pendingBatch(20);
if ($batch === []) {
    fwrite(STDOUT, 'Очередь публикаций пуста.' . PHP_EOL);
    \App\Core\ProcessLock::release($workerLock);
    exit(0);
}

$publisher = new SocialPublisher();
$sent = 0;
$failed = 0;

foreach ($batch as $row) {
    // Единая логика отправки строки очереди (см. SocialSettings::dispatchRow).
    $res = SocialSettings::dispatchRow($row, $publisher);

    if ($res['ok']) {
        $sent++;
        fwrite(STDOUT, sprintf("OK %s <- новость #%d\n", (string) $row['network'], (int) $row['news_id']));
    } else {
        $failed++;
        Logger::error(sprintf('Social publish failed [%s] news #%d: %s', (string) $row['network'], (int) $row['news_id'], (string) $res['error']));
    }
}

if ($sent > 0) {
    Logger::info(sprintf('Автопубликация по расписанию: опубликовано %d, ошибок %d.', $sent, $failed));
}

fwrite(STDOUT, sprintf('Готово: опубликовано %d, ошибок %d.%s', $sent, $failed, PHP_EOL));
\App\Core\ProcessLock::release($workerLock);
exit(0);
