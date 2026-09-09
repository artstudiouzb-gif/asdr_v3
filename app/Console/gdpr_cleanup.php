<?php

declare(strict_types=1);

/*
 * GDPR-очистка старых заявок форм (группа 6).
 *   php app/Console/gdpr_cleanup.php
 *
 * Раз в сутки (пример cron):
 *   30 3 * * * php /path/to/app/Console/gdpr_cleanup.php >> storage/logs/gdpr_cleanup.log 2>&1
 *
 * Удаляет form_submissions старше настройки «Срок хранения ПДн» (дней).
 * pii_retention_days = 0 → очистка отключена (хранить бессрочно).
 * Дополнительно чистит журнал действий администраторов старше 180 дней.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Logger;
use App\Core\ProcessLock;
use App\Models\FormSubmission;
use App\Models\Setting;

$lock = ProcessLock::acquire('gdpr_cleanup');
if ($lock === null) {
    fwrite(STDERR, 'gdpr_cleanup уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    // Журнал действий администраторов: храним 180 дней (не зависит от ПДн).
    $auditRemoved = \App\Models\AuditLog::purgeOlderThan(180);
    if ($auditRemoved > 0) {
        Logger::info('Очистка журнала действий: удалены старые записи', ['removed' => $auditRemoved]);
    }

    // 404-трекер: путь без обращений 90 дней уже не актуален.
    $nfRemoved = \App\Models\NotFoundLog::purgeOlderThan(90);
    if ($nfRemoved > 0) {
        Logger::info('Очистка 404-трекера: удалены неактуальные пути', ['removed' => $nfRemoved]);
    }

    // Очистка старых попыток входа (RateLimiter)
    $rlRemoved = \App\Core\RateLimiter::purgeExpired(24);
    if ($rlRemoved > 0) {
        Logger::info('Очистка rate-limiter: удалены устаревшие попытки входа', ['removed' => $rlRemoved]);
    }

    $days = (int) Setting::get('pii_retention_days', '0');
    if ($days <= 0) {
        fwrite(STDOUT, 'Срок хранения ПДн не задан (0) — очистка заявок отключена.' . PHP_EOL);
        exit(0);
    }

    $removed = FormSubmission::deleteOlderThan($days);
    if ($removed > 0) {
        Logger::info('GDPR-очистка: удалены старые заявки форм', ['removed' => $removed, 'older_than_days' => $days]);
    }
    fwrite(STDOUT, sprintf('Готово: удалено заявок старше %d дн. — %d.%s', $days, $removed, PHP_EOL));
    exit(0);
} catch (\Throwable $e) {
    Logger::warning('GDPR-очистка: ошибка', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    ProcessLock::release($lock);
}
