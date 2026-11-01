<?php

declare(strict_types=1);

/*
 * Лента Instagram на сайте: забирает последние посты аккаунта.
 *   php app/Console/instagram_worker.php [--force]
 *
 * Cron (например, раз в час):
 *   0 * * * * php /path/to/app/Console/instagram_worker.php >> /path/to/storage/logs/instagram_worker.log 2>&1
 *
 * Сайт показывает сохранённую копию, а не ходит в Instagram на каждый показ
 * страницы. Без галочки «Обновлять ленту автоматически» проход пропускается —
 * ключ --force запускает его независимо от неё (для ручной проверки).
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\BackgroundTasks;

$result = BackgroundTasks::run('instagram', in_array('--force', $argv, true));
if (!$result['ran']) {
    fwrite(STDOUT, 'Пропуск: ' . $result['message'] . '.' . PHP_EOL);
    exit(0);
}
if (!$result['ok']) {
    fwrite(STDERR, 'Не удалось: ' . $result['message'] . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Лента Instagram: ' . $result['message'] . '.' . PHP_EOL);
exit(0);
