<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\LogReader;
use App\Core\Redirect;
use App\Core\View;

/**
 * Журнал ошибок в панели.
 *
 * Журнал был всегда, но лежал файлом на shared-хостинге, куда владелец не
 * заходит: узнать, что происходит на сайте, он мог только скачав `error.log`
 * и отдав его инженеру. Раздел отвечает на тот же вопрос сам.
 *
 * Только супер-админ: записи журнала называют пути на диске, имена таблиц и
 * внутренние сообщения — редактору это ни к чему.
 */
final class LogController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        $channel = Input::query()->str('channel', 'error', 32);
        if (!isset(LogReader::CHANNELS[$channel])) {
            $channel = 'error';
        }

        View::render('admin/logs/index', [
            'channel' => $channel,
            'channels' => LogReader::channels(),
            'data' => LogReader::read($channel),
        ]);
    }

    public function clear(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $channel = Input::post()->str('channel', '', 32);
        if (!isset(LogReader::CHANNELS[$channel])) {
            Flash::error('Неизвестный журнал.');
            Redirect::to('/admin/logs');
        }

        if (LogReader::clear($channel)) {
            Flash::success('Журнал «' . LogReader::CHANNELS[$channel] . '» очищен.');
        } else {
            Flash::error('Не удалось очистить журнал: проверьте права на storage/logs.');
        }

        Redirect::to('/admin/logs?channel=' . urlencode($channel));
    }

    /** Удаляет из файлового журнала только записи старше выбранного срока. */
    public function purge(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $channel = Input::post()->str('channel', '', 32);
        $days = Input::post()->int('days');
        if (!isset(LogReader::CHANNELS[$channel]) || !in_array($days, [7, 30, 90], true)) {
            Flash::error('Не удалось определить журнал или срок хранения.');
            Redirect::to('/admin/logs');
        }

        $removed = LogReader::purgeOlderThan($channel, $days);
        if ($removed === null) {
            Flash::error('Не удалось удалить старые записи: проверьте права на storage/logs.');
        } else {
            Flash::success('Удалено старых записей: ' . $removed . '.');
        }

        Redirect::to('/admin/logs?channel=' . urlencode($channel));
    }

    /**
     * Выгрузка журнала целиком ZIP-архивом: активный файл и архивы ротации.
     * Панель показывает только хвост, а проверке за период (отчёт об
     * инциденте, передача инженеру) нужен весь журнал.
     */
    public function download(): void
    {
        Auth::requireSuperAdmin();

        $channel = Input::query()->str('channel', '', 32);
        if ($channel !== 'all' && !isset(LogReader::CHANNELS[$channel])) {
            Flash::error('Неизвестный журнал.');
            Redirect::to('/admin/logs');
        }

        $zip = LogReader::archive($channel);
        if ($zip === null) {
            Flash::error('Выгружать нечего: журнал пуст или архив не удалось собрать.');
            Redirect::to('/admin/logs?channel=' . urlencode($channel === 'all' ? 'error' : $channel));
        }
        // Вкладку могут закрыть посреди отдачи — тогда строка после
        // readfile() не выполнится, а временный архив остался бы на диске.
        register_shutdown_function(static function () use ($zip): void {
            if (is_file($zip)) {
                @unlink($zip);
            }
        });

        $name = 'logs-' . $channel . '-' . date('Y-m-d-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($zip));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($zip);
        exit;
    }
}
