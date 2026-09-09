<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\Updater;
use App\Core\UpdateState;
use App\Core\View;

/**
 * Раздел «Обновление системы».
 *
 * **Панель обновление не выполняет и выполнять не может.** Замена кода —
 * самое опасное действие в CMS, а веб-запрос обрывается по таймауту: обрыв
 * посреди замены оставит сайт с половиной старых и половиной новых файлов.
 * Поэтому кнопка пишет намерение (`UpdateState::queue`) и на этом запрос
 * заканчивается — за миллисекунды, обрывать нечего. Работу делает
 * `app/Console/update_worker.php` по cron; страница показывает его ход.
 *
 * Отсюда главная проверка этого контроллера: **без живого cron кнопку жать
 * нельзя**. Иначе намерение записано, а выполнять его некому — нажатие
 * выглядит как «ничего не произошло», и это худший вид отказа. Свежесть
 * воркера видно по его heartbeat.
 *
 * Чего здесь нет намеренно: выбора версии (ставится только последний релиз —
 * иначе через выбор ассета в панель приезжает произвольный архив) и адреса
 * репозитория (он из `UPDATE_REPO`, настройка дала бы увести обновление на
 * чужой репозиторий, то есть выполнить свой код на сервере).
 */
final class UpdateController
{
    /** Подтверждение действия: «Обновить» рядом с версией слишком легко нажать. */
    private const CONFIRM_CODE = 'ОБНОВИТЬ';

    /** Воркер молчит дольше — считаем, что cron не работает. */
    private const WORKER_SILENT_AFTER = 900;

    public function index(): void
    {
        Auth::requireSuperAdmin();

        // Состояние читаем до проверки релиза: если GitHub недоступен,
        // страница обязана показать хотя бы ход текущего обновления.
        $state = UpdateState::read();

        View::render('admin/update/index', [
            'state' => $state,
            'stale' => UpdateState::isStale($state),
            'check' => Updater::check(),
            'repo' => Updater::repo(),
            'worker' => self::workerStatus(),
            'confirmCode' => self::CONFIRM_CODE,
            'cronLine' => '* * * * * ' . PHP_BINARY . ' ' . APP_ROOT . '/app/Console/update_worker.php'
                . ' >> ' . APP_ROOT . '/storage/logs/update_worker.log 2>&1',
        ]);
    }

    /** Заказ обновления: пишем намерение и уходим. Файлов здесь не касаемся. */
    public function request(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $state = UpdateState::read();
        if (in_array($state['status'], [UpdateState::STATUS_QUEUED, UpdateState::STATUS_RUNNING], true)
            && !UpdateState::isStale($state)
        ) {
            Flash::error('Обновление уже идёт — дождитесь окончания.');
            $this->back();
        }

        $code = trim((string) ($_POST['confirm'] ?? ''));
        if (!hash_equals(self::CONFIRM_CODE, $code)) {
            Flash::error('Обновление не запущено: введите ' . self::CONFIRM_CODE . ' для подтверждения.');
            $this->back();
        }

        $worker = self::workerStatus();
        if (!$worker['alive']) {
            Flash::error('Обновление не запущено: воркер не отвечает — заказ никто не выполнит. '
                . 'Проверьте, что задание cron для app/Console/update_worker.php заведено и работает.');
            $this->back();
        }

        $check = Updater::check();
        if (!($check['ok'] ?? false)) {
            Flash::error('Обновление не запущено: ' . (string) ($check['error'] ?? 'не удалось узнать последний релиз.'));
            $this->back();
        }
        if (!($check['available'] ?? false)) {
            Flash::info('Установлена последняя версия — обновлять нечего.');
            $this->back();
        }
        if (!($check['installable'] ?? false)) {
            Flash::error('Обновление не запущено: ' . (string) ($check['reason'] ?? ''));
            $this->back();
        }

        // Auth::user() объявлен как ?array: requireSuperAdmin() выше гарантирует
        // не-null, но обращаться к полю напрямую нельзя — ErrorHandler
        // превращает предупреждение о чтении из null в исключение.
        $user = Auth::user();
        UpdateState::queue((string) ($check['latest'] ?? ''), (string) ($user['username'] ?? ''));
        Flash::success('Обновление до ' . (string) ($check['latest'] ?? '') . ' поставлено в очередь. '
            . 'Воркер возьмёт его в течение минуты; на время замены файлов сайт закроется на обслуживание.');
        $this->back();
    }

    /**
     * Сброс зависшего состояния руками.
     *
     * Воркер прибирает за собой сам, но только если cron жив: умер он вместе
     * с воркером — снимать режим обслуживания станет некому, и владельцу
     * нужна кнопка. Ровно поэтому же `public/index.php` не показывает
     * заглушку, когда её включило сорвавшееся обновление.
     */
    public function reset(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        UpdateState::releaseMaintenance();
        UpdateState::finish(UpdateState::STATUS_FAILED, 'сброшено вручную из панели.');
        Flash::warning('Состояние обновления сброшено, режим обслуживания снят. '
            . 'Файлы могли замениться частично — сверьте сайт и при необходимости разверните резервную копию.');
        $this->back();
    }

    /** @return array{last:?int, age:?int, alive:bool} */
    private static function workerStatus(): array
    {
        $last = Heartbeat::lastRun('update');
        $age = $last !== null ? time() - $last : null;

        return [
            'last' => $last,
            'age' => $age,
            'alive' => $age !== null && $age <= self::WORKER_SILENT_AFTER,
        ];
    }

    private function back(): never
    {
        header('Location: /admin/update');
        exit;
    }
}
