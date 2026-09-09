<?php

declare(strict_types=1);

/*
 * Воркер webpush-рассылки ArtStudio CMS.
 *   php app/Console/push_worker.php
 *
 * Запускать по Cron рядом с social_worker (например, раз в 5 минут):
 *   [мин/5] * * * * php /path/to/app/Console/push_worker.php >> /path/to/storage/logs/push_worker.log 2>&1
 *
 * Забирает pending-задания из webpush_queue (новость -> все подписки),
 * мёртвые подписки (404/410) удаляет. После трёх неудач задание — failed.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

\App\Core\Heartbeat::touch('push');

$workerLock = \App\Core\ProcessLock::acquire('push_worker');
if ($workerLock === null) {
    fwrite(STDERR, 'push_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

use App\Core\AppUrl;
use App\Core\Logger;
use App\Core\WebPush;
use App\Models\News;
use App\Models\WebPushSubscription;

if (!WebPush::isEnabled()) {
    fwrite(STDOUT, 'Webpush выключен в настройках.' . PHP_EOL);
    exit(0);
}

$batch = WebPushSubscription::pendingQueue(5);
if ($batch === []) {
    fwrite(STDOUT, 'Очередь webpush пуста.' . PHP_EOL);
    exit(0);
}

$push = new WebPush();
$base = AppUrl::base();

foreach ($batch as $job) {
    $jobId = (int) $job['id'];
    $news = News::findById((int) $job['news_id']);
    if ($news === null || ($news['status'] ?? '') !== 'published' || !empty($news['deleted_at'])) {
        WebPushSubscription::markQueueFailed($jobId, 'Новость недоступна или не опубликована.');
        continue;
    }

    $cover = trim((string) ($news['image'] ?? ''));
    $imageUrl = $cover !== '' ? (str_starts_with($cover, 'http') ? $cover : $base . $cover) : null;
    $iconUrl = $base . '/favicon.ico';

    $payload = (string) json_encode([
        'title' => (string) $news['title'],
        'body' => mb_substr(trim(strip_tags((string) ($news['excerpt'] ?? ''))), 0, 160),
        'url' => $base . '/news/' . rawurlencode((string) $news['slug']),
        'icon' => $iconUrl,
        'badge' => $iconUrl,
        'image' => $imageUrl,
        'tag' => 'news-' . (int) $news['id'],
        'data' => [
            'url' => $base . '/news/' . rawurlencode((string) $news['slug']),
        ],
    ], JSON_UNESCAPED_UNICODE);

    $sent = 0;
    $gone = 0;
    $errors = 0;
    $lastError = '';
    foreach (WebPushSubscription::all() as $sub) {
        $res = $push->send([
            'endpoint' => (string) $sub['endpoint'],
            'p256dh' => (string) $sub['p256dh'],
            'auth' => (string) $sub['auth'],
        ], $payload);
        if ($res['ok']) {
            $sent++;
        } elseif ($res['gone']) {
            WebPushSubscription::deleteByEndpoint((string) $sub['endpoint']);
            $gone++;
        } else {
            $errors++;
            $lastError = (string) $res['error'];
        }
    }

    // Частичные сбои отдельных подписок — норма; фейлим задание только
    // если не ушло ни одного уведомления при наличии ошибок.
    if ($sent > 0 || $errors === 0) {
        WebPushSubscription::markQueueSent($jobId);
        fwrite(STDOUT, sprintf("OK новость #%d: отправлено %d, удалено мёртвых %d, ошибок %d\n", (int) $job['news_id'], $sent, $gone, $errors));
    } else {
        WebPushSubscription::markQueueFailed($jobId, $lastError !== '' ? $lastError : 'Все отправки не удались.');
        Logger::error(sprintf('Webpush не отправлен для новости #%d: %s', (int) $job['news_id'], $lastError));
    }
}

exit(0);
