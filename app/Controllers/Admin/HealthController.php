<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\SystemHealth;
use App\Core\View;

/**
 * «Состояние системы» — одно место, где владелец видит, работает ли всё.
 *
 * Раздел ничего не проверяет сам и никуда не ходит: считают по-прежнему
 * `Watchdog`, `Heartbeat`, `Integrity`, `RestoreDrill`, `MigrationRunner` и
 * память интеграций. Здесь их ответы просто становятся видимыми — до этого они
 * жили в CLI и cron, куда владелец shared-хостинга не заходит.
 *
 * Только супер-админ: строки называют версии, воркеры, пути и причины отказов
 * интеграций — редактору это ни к чему, а знание о внутреннем устройстве
 * лишним не бывает только у того, кто отвечает за сервер.
 */
final class HealthController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        View::render('admin/health/index', [
            'groups' => SystemHealth::groups(),
            'worst' => SystemHealth::worst(),
        ]);
    }
}
