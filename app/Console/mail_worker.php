<?php

declare(strict_types=1);

/*
 * Воркер очереди почты ArtStudio CMS.
 *   php app/Console/mail_worker.php
 *
 * Запускать по Cron (например, каждую минуту):
 *   * * * * * php /path/to/app/Console/mail_worker.php >> /path/to/storage/logs/mail_worker.log 2>&1
 *
 * Забирает pending-письма из mail_queue и отправляет их через нативный SMTP
 * (App\Core\Mailer). При ошибке увеличивает счётчик попыток; после трёх
 * неудач письмо помечается как failed.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

\App\Core\Heartbeat::touch('mail'); // группа 2.1

// Защита от наложения запусков (группа 6): не стартуем поверх незавершённого.
$workerLock = \App\Core\ProcessLock::acquire('mail_worker');
if ($workerLock === null) {
    fwrite(STDERR, 'mail_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

use App\Core\Mailer;
use App\Models\MailQueue;
use App\Models\Subscriber;

if (!Mailer::isConfigured()) {
    fwrite(STDERR, 'SMTP не настроен (config[mail][host] пуст). Нечего отправлять.' . PHP_EOL);
    exit(0);
}

$batch = MailQueue::pendingBatch(20);
if ($batch === []) {
    fwrite(STDOUT, 'Очередь пуста.' . PHP_EOL);
    exit(0);
}

$mailer = new Mailer();
$sent = 0;
$failed = 0;
$cancelled = 0;

foreach ($batch as $item) {
    $id = (int) $item['id'];
    if (($item['purpose'] ?? '') === 'digest') {
        $subscriberId = (int) ($item['subscriber_id'] ?? 0);
        if ($subscriberId <= 0 || !Subscriber::isActive($subscriberId)) {
            MailQueue::markCancelled($id, 'Получатель отписан или удалён до отправки');
            $cancelled++;
            continue;
        }
    }

    $ok = $mailer->send(
        (string) $item['to_email'],
        (string) $item['subject'],
        (string) $item['body'],
        $item['to_name'] !== null ? (string) $item['to_name'] : null
    );

    if ($ok) {
        MailQueue::markSent($id);
        $sent++;
    } else {
        // Точный ответ SMTP-сервера (например «550 SPF check failed») —
        // сразу видно, почему провайдер отклоняет письма.
        MailQueue::markFailed($id, $mailer->lastError() ?? 'SMTP send returned false');
        $failed++;
    }
}

fwrite(STDOUT, sprintf(
    'Обработано: отправлено %d, ошибок %d, отменено %d.%s',
    $sent,
    $failed,
    $cancelled,
    PHP_EOL
));
