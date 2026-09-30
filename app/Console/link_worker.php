<?php

declare(strict_types=1);

/*
 * Проверка ссылок в контенте — внутренних и внешних.
 *   php app/Console/link_worker.php             # полный проход
 *   php app/Console/link_worker.php --internal  # только свои ссылки
 *
 * Раз в сутки (пример cron):
 *   40 4 * * * php /path/to/app/Console/link_worker.php >> storage/logs/links.log 2>&1
 *
 * Внешний адрес, не ответивший один раз, битым не считается — только после
 * нескольких отказов подряд (LinkChecker::UNREACHABLE_STREAK). Живые внешние
 * ссылки перепроверяются раз в неделю, так что проход не долбит чужие сайты.
 * Код возврата — ноль и при битых ссылках: это забота редактора, а не авария,
 * и cron не должен слать письмо каждую ночь.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\LinkChecker;

Heartbeat::touch('links');

$result = LinkChecker::run(!in_array('--internal', $argv, true), 600);

echo 'Ссылок в контенте: ' . $result['total']
    . ', проверено сейчас: ' . $result['checked']
    . ', битых: ' . $result['broken']
    . ', не отвечают: ' . $result['unreachable']
    . ', закрыты от роботов: ' . $result['blocked']
    . ($result['remaining'] > 0 ? ', отложено до следующего прохода: ' . $result['remaining'] : '')
    . PHP_EOL;
