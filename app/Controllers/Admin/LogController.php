<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\LogReader;
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

        $channel = (string) ($_GET['channel'] ?? 'error');
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

        $channel = (string) ($_POST['channel'] ?? '');
        if (!isset(LogReader::CHANNELS[$channel])) {
            Flash::error('Неизвестный журнал.');
            header('Location: /admin/logs');
            exit;
        }

        if (LogReader::clear($channel)) {
            Flash::success('Журнал «' . LogReader::CHANNELS[$channel] . '» очищен.');
        } else {
            Flash::error('Не удалось очистить журнал: проверьте права на storage/logs.');
        }

        header('Location: /admin/logs?channel=' . urlencode($channel));
        exit;
    }
}
