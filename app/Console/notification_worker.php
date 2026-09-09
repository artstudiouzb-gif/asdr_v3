<?php

declare(strict_types=1);

/*
 * Воркер внешней доставки Центра уведомлений.
 *
 *   php app/Console/notification_worker.php
 *
 * Cron каждую минуту:
 *   * * * * * php /path/to/app/Console/notification_worker.php >> /path/to/storage/logs/notification_worker.log 2>&1
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Mailer;
use App\Core\ProcessLock;
use App\Core\TelegramBot;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;

Heartbeat::touch('notifications');
$lock = ProcessLock::acquire('notification_worker');
if ($lock === null) {
    fwrite(STDERR, 'notification_worker уже выполняется — пропуск.' . PHP_EOL);
    exit(0);
}

$batch = NotificationDelivery::claimBatch(25);
if ($batch === []) {
    fwrite(STDOUT, 'Очередь уведомлений пуста.' . PHP_EOL);
    exit(0);
}

$mailer = new Mailer();
$sent = 0;
$failed = 0;
$deferred = 0;

foreach ($batch as $item) {
    $id = (int) $item['id'];
    $severity = (string) ($item['severity'] ?? 'info');
    $preference = NotificationPreference::forUser((int) $item['user_id']);

    // Ночью обычные события ждут; security и critical доставляются сразу.
    if (!in_array($severity, ['security', 'critical'], true)
        && NotificationPreference::isQuietNow($preference)) {
        NotificationDelivery::defer($id, 15);
        $deferred++;
        continue;
    }

    $title = trim((string) $item['title']);
    $message = trim((string) $item['message']);
    $channel = (string) $item['channel'];
    $destination = (string) $item['destination'];
    $ok = false;
    $error = 'Канал доставки вернул false';

    try {
        if ($channel === 'email') {
            $safeTitle = htmlspecialchars($title, ENT_QUOTES);
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES));
            $body = '<h2>' . $safeTitle . '</h2><p>' . $safeMessage . '</p>'
                . '<p><small>Подробности доступны после авторизации в Центре уведомлений CMS.</small></p>';
            $ok = $mailer->send(
                $destination,
                '[' . strtoupper($severity) . '] ' . $title,
                $body,
                (string) ($item['username'] ?? '')
            );
            $error = $mailer->lastError() ?? $error;
        } elseif ($channel === 'telegram') {
            $chatId = filter_var($destination, FILTER_VALIDATE_INT);
            if ($chatId === false || !TelegramBot::isConfigured()) {
                $error = 'Telegram-бот не настроен или chat_id недействителен';
            } else {
                $text = '<b>' . htmlspecialchars($title, ENT_QUOTES) . '</b>'
                    . "\n\n" . htmlspecialchars($message, ENT_QUOTES)
                    . "\n\n<i>Подробности доступны после входа в CMS.</i>";
                $ok = TelegramBot::sendMessage((int) $chatId, $text, 'HTML');
            }
        } else {
            $error = 'Неизвестный канал: ' . $channel;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $ok = false;
    }

    if ($ok) {
        NotificationDelivery::markSent($id);
        $sent++;
    } else {
        NotificationDelivery::markFailed($id, (int) $item['attempts'], $error);
        $failed++;
    }
}

fwrite(STDOUT, sprintf(
    'Обработано: отправлено %d, ошибок %d, отложено тихими часами %d.%s',
    $sent,
    $failed,
    $deferred,
    PHP_EOL
));
