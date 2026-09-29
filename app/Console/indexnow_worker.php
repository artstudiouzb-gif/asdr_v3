<?php

declare(strict_types=1);

/*
 * IndexNow: сообщает Яндексу и Bing об адресах, изменённых с прошлого прохода.
 *   php app/Console/indexnow_worker.php
 *
 * Каждые 15 минут (пример cron):
 *   0,15,30,45 * * * * php /path/to/app/Console/indexnow_worker.php >> storage/logs/indexnow_worker.log 2>&1
 *
 * Зачем: без уведомления о свежей новости поисковик узнаёт, когда придёт за
 * картой сайта в следующий раз, — это часы, а бывает и сутки. Нет строки в
 * crontab — нет и отправок. Подробности — App\Core\Seo\IndexNow.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

\App\Core\Heartbeat::touch('indexnow');

$result = \App\Core\Seo\IndexNow::run();
echo date('c') . ' IndexNow: ' . $result['message'] . PHP_EOL;

// Ненулевой код — отказ отправки: так cron и внешний монитор узнают о нём,
// не разбирая текст.
exit($result['status'] === 'failed' ? 1 : 0);
