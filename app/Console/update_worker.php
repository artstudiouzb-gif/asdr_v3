<?php

declare(strict_types=1);

/*
 * Воркер обновления системы.
 *   php app/Console/update_worker.php
 *
 * Cron (раз в минуту):
 *   * * * * * php /path/to/app/Console/update_worker.php >> /path/to/storage/logs/update_worker.log 2>&1
 *
 * Зачем отдельный процесс. Замена кода — самое опасное действие в CMS, а
 * веб-запрос обрывается по таймауту: обрыв посреди замены оставит сайт с
 * половиной старых и половиной новых файлов. Поэтому кнопка в панели только
 * пишет намерение (`UpdateState::queue`), а работу делает этот воркер —
 * из командной строки, без таймаута и без браузера на том конце.
 *
 * И воркер тоже отдельный, а не задача в общей очереди: у `JobQueue`
 * аренда строки 60 секунд, а зависшей задача считается через 600 — обновление
 * длиннее, и вторая копия воркера подхватила бы его прямо посреди замены
 * файлов.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Logger;
use App\Core\ProcessLock;
use App\Core\UpdateRunner;
use App\Core\Updater;
use App\Core\UpdateState;

Heartbeat::touch('update');

// Блокировка берётся ДО чтения состояния: два cron-запуска, разошедшиеся на
// секунду, иначе оба увидели бы задачу в очереди и начали заменять файлы.
$lock = ProcessLock::acquire('update_worker');
if ($lock === null) {
    fwrite(STDERR, 'update_worker уже выполняется — пропуск запуска.' . PHP_EOL);
    exit(0);
}

try {
    // Прибираем за сорвавшейся попыткой: закрываем зависшее «выполняется» и,
    // если замена файлов ещё не начиналась, открываем сайт. Начиналась —
    // сайт остаётся закрытым, там нужен человек (см. UpdateState). Без этой
    // уборки новая задача не взялась бы никогда.
    if (UpdateState::recoverStale()) {
        $recovered = UpdateState::read();
        Logger::error('Обновление оборвалось: ' . $recovered['error']);
        fwrite(STDERR, 'Найдено оборвавшееся обновление: ' . $recovered['error'] . PHP_EOL);
    }

    $state = UpdateState::read();
    if ($state['status'] !== UpdateState::STATUS_QUEUED) {
        fwrite(STDOUT, 'Обновление не заказано.' . PHP_EOL);
        exit(0);
    }

    $check = Updater::check();
    if (!($check['ok'] ?? false)) {
        UpdateState::finish(UpdateState::STATUS_FAILED, (string) ($check['error'] ?? 'не удалось узнать последний релиз.'));
        exit(1);
    }
    if (!($check['available'] ?? false)) {
        UpdateState::finish(UpdateState::STATUS_DONE, '');
        fwrite(STDOUT, 'Установлена последняя версия — обновлять нечего.' . PHP_EOL);
        exit(0);
    }
    // Заказывали одно, на GitHub уже другое — ставим только то, что заказано:
    // «обновить» из панели не должно молча означать «поставить что вышло».
    if ($state['release'] !== '' && $state['release'] !== (string) ($check['latest'] ?? '')) {
        UpdateState::finish(
            UpdateState::STATUS_FAILED,
            'заказан релиз ' . $state['release'] . ', а последний сейчас ' . (string) ($check['latest'] ?? '')
                . '. Проверьте версию в панели и закажите обновление заново.'
        );
        exit(1);
    }
    if (!($check['installable'] ?? false)) {
        UpdateState::finish(UpdateState::STATUS_FAILED, (string) ($check['reason'] ?? ''));
        exit(1);
    }

    UpdateState::markRunning();
    fwrite(STDOUT, 'Обновление до ' . (string) ($check['latest'] ?? '') . '…' . PHP_EOL);

    try {
        $result = UpdateRunner::run($check, static function (string $line): void {
            fwrite(STDOUT, '  ' . $line . PHP_EOL);
        });
        UpdateState::finish(UpdateState::STATUS_DONE);
        Logger::info('Система обновлена до ' . $result['release'] . '.');
        fwrite(STDOUT, 'Обновлено до ' . $result['release'] . '.' . PHP_EOL);
    } catch (\Throwable $e) {
        UpdateState::finish(UpdateState::STATUS_FAILED, $e->getMessage());
        Logger::error('Обновление не удалось: ' . $e->getMessage());
        fwrite(STDERR, 'Обновление остановлено: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
} finally {
    // Режим обслуживания здесь НЕ снимаем, хотя соблазн есть. Им распоряжается
    // бегунок — он и включает его перед заменой, и возвращает в `finally`.
    // А `recoverStale()` выше могла намеренно оставить сайт закрытым, потому
    // что прошлая попытка оборвалась посреди замены файлов; слепое снятие
    // здесь отменяло бы это решение и открывало сайт, собранный наполовину.
    ProcessLock::release($lock);
}

exit(0);
