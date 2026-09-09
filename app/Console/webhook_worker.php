<?php

declare(strict_types=1);

/*
 * Воркер очереди вебхуков ArtStudio CMS (задача 136).
 *   php app/Console/webhook_worker.php
 *
 * Запускать по Cron (например, каждую минуту):
 *   [* * * * *] php /path/to/app/Console/webhook_worker.php >> storage/logs/webhook_worker.log 2>&1
 *
 * Забирает pending-доставки, отправляет POST на URL вебхука с HMAC-подписью,
 * фиксирует HTTP-код. При ошибке — ретрай (до 3 попыток), затем failed.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\WebhookDispatcher;

$result = WebhookDispatcher::processQueue(30);
if ($result['busy']) {
    fwrite(STDERR, 'webhook_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}
Heartbeat::touch('webhook'); // отмечаем только реально выполненный запуск
if ($result['taken'] === 0) {
    fwrite(STDOUT, 'Очередь вебхуков пуста.' . PHP_EOL);
    exit(0);
}

fwrite(
    STDOUT,
    sprintf('Готово: доставлено %d, ошибок %d.%s', $result['sent'], $result['failed'], PHP_EOL)
);
exit(0);
