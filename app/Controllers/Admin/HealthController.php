<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\Input;
use App\Core\Redirect;
use App\Core\Scheduler;
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
            'schedule' => self::schedule(),
            'schedulerLast' => Heartbeat::lastRun('scheduler'),
            'canSpawn' => Scheduler::canSpawn(),
        ]);
    }

    /**
     * Расписание фоновых задач: когда задача шла последний раз — по отметке
     * планировщика или по собственной отметке воркера (его могли запустить и
     * отдельной строкой cron), берётся более свежая.
     *
     * @return list<array{task:string,label:string,cron:string,last:?int}>
     */
    private static function schedule(): array
    {
        $runs = Scheduler::lastRuns();
        $out = [];
        foreach (Scheduler::TASKS as $task => [, $cron]) {
            $last = max($runs[$task] ?? 0, Heartbeat::lastRun($task) ?? 0);
            $out[] = [
                'task' => $task,
                'label' => Scheduler::LABELS[$task],
                'cron' => $cron,
                'last' => $last > 0 ? $last : null,
            ];
        }

        return $out;
    }

    /** «Запустить сейчас»: воркер уходит в фон, страница не ждёт его конца. */
    public function run(): void
    {
        Auth::requireSuperAdmin();
        \App\Core\Csrf::verifyRequest();
        $task = Input::post()->oneOf('task', array_keys(Scheduler::TASKS), '');
        if ($task === '') {
            Flash::error('Неизвестная задача.');
        } elseif (Scheduler::spawn($task)) {
            Flash::success('Задача «' . (Scheduler::LABELS[$task] ?? $task) . '» запущена в фоне. Результат — в её журнале и в строках выше через минуту-другую.');
        } else {
            Flash::error('Запустить не удалось: хостинг запрещает запуск процессов из веба (proc_open). Задача выполнится по расписанию.');
        }
        Redirect::to('/admin/health#health-schedule');
    }
}
