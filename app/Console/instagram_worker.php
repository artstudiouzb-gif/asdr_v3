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

use App\Core\InstagramFeed;
use App\Core\Logger;
use App\Core\ProcessLock;

$force = in_array('--force', $argv, true);

if (!$force && !InstagramFeed::settings()['enabled']) {
    fwrite(STDOUT, 'Лента Instagram выключена в админке — пропуск.' . PHP_EOL);
    exit(0);
}
if (!InstagramFeed::isConfigured()) {
    fwrite(STDERR, 'Не задан токен Instagram — пропуск.' . PHP_EOL);
    exit(0);
}

$lock = ProcessLock::acquire('instagram_worker');
if ($lock === null) {
    fwrite(STDERR, 'instagram_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    $result = InstagramFeed::sync(null);
    if (!$result['ok']) {
        Logger::error('Лента Instagram не обновлена: ' . $result['error']);
        fwrite(STDERR, 'Лента не обновлена: ' . $result['error'] . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'Лента Instagram: ' . $result['summary'] . '.' . PHP_EOL);
} finally {
    ProcessLock::release($lock);
}

exit(0);
