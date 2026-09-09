<?php

declare(strict_types=1);

/*
 * Еженедельный email-дайджест новостей.
 *   php app/Console/digest_worker.php
 *
 * Cron (раз в неделю, например понедельник 09:00):
 *   0 9 * * 1 php /path/to/app/Console/digest_worker.php >> /path/to/storage/logs/digest_worker.log 2>&1
 *
 * Собирает новости, опубликованные за последние 7 дней, и ставит письмо в
 * очередь (mail_worker отправляет) каждому подписчику с персональной ссылкой
 * отписки. Нет новых новостей или подписчиков — тихо выходит.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\DigestDispatcher;
use App\Core\Logger;
use App\Core\ProcessLock;

$lock = ProcessLock::acquire('digest_worker');
if ($lock === null) {
    fwrite(STDERR, 'digest_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    $result = DigestDispatcher::queueWeekly();
    if ($result['recipients'] === 0) {
        fwrite(STDOUT, 'Активных подписчиков нет — дайджест не формируется.' . PHP_EOL);
        exit(0);
    }
    if ($result['news'] === 0) {
        fwrite(STDOUT, 'За неделю новостей нет — дайджест не отправляется.' . PHP_EOL);
        exit(0);
    }
    if ($result['queued'] === 0 && $result['duplicates'] > 0) {
        fwrite(STDOUT, 'Дайджест за период ' . $result['period'] . ' уже поставлен в очередь.' . PHP_EOL);
        exit(0);
    }

    Logger::info('Дайджест поставлен в очередь', $result);
    fwrite(STDOUT, sprintf(
        'OK: %d новостей, %d писем поставлено в очередь, %d дублей пропущено.%s',
        $result['news'],
        $result['queued'],
        $result['duplicates'],
        PHP_EOL
    ));
    exit(0);
} catch (\Throwable $e) {
    Logger::warning('Дайджест: ошибка', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    ProcessLock::release($lock);
}
