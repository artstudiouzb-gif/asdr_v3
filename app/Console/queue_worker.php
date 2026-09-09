<?php

declare(strict_types=1);

/*
 * Единый воркер обработки фоновых задач (Enterprise Queue Worker).
 *   php app/Console/queue_worker.php [--queue=default] [--limit=20]
 *
 * Cron (каждую минуту):
 *   * * * * * php /path/to/app/Console/queue_worker.php >> /path/to/storage/logs/queue_worker.log 2>&1
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\JobQueue;
use App\Core\Logger;
use App\Core\ProcessLock;

Heartbeat::touch('queue_worker');

$lock = ProcessLock::acquire('queue_worker');
if ($lock === null) {
    fwrite(STDERR, 'queue_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    $batch = JobQueue::claimBatch(20);
    if ($batch === []) {
        fwrite(STDOUT, 'Очередь задач пуста.' . PHP_EOL);
        exit(0);
    }

    $processed = 0;
    $failed = 0;

    foreach ($batch as $job) {
        $id = $job['id'];
        $handler = $job['handler'];
        $payload = $job['payload'];

        try {
            if (class_exists($handler)) {
                $instance = new $handler();
                if (is_callable($instance)) {
                    $instance($payload);
                } elseif (method_exists($instance, 'handle')) {
                    $instance->handle($payload);
                } else {
                    throw new RuntimeException("Handler {$handler} не имеет метода handle() или __invoke()");
                }
            } elseif (is_callable($handler)) {
                $handler($payload);
            } else {
                throw new RuntimeException("Обработчик задачи {$handler} не найден");
            }

            JobQueue::markCompleted($id);
            $processed++;
        } catch (\Throwable $e) {
            JobQueue::markFailed($id, $job['attempts'], $job['max_attempts'], $e->getMessage());
            Logger::error("Ошибка выполнения задачи #{$id} [{$handler}]: " . $e->getMessage());
            $failed++;
        }
    }

    fwrite(STDOUT, sprintf("Обработано задач: %d, сбоев: %d.%s", $processed, $failed, PHP_EOL));
} finally {
    ProcessLock::release($lock);
}

exit(0);
