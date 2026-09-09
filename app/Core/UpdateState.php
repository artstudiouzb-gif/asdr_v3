<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Состояние обновления системы: что заказано из админки, на каком шаге
 * воркер и чем всё кончилось.
 *
 * Зачем отдельный класс, а не пара настроек. Обновление идёт **не в том
 * процессе**, который его заказал: панель только пишет намерение, а работу
 * делает cron-воркер (`app/Console/update_worker.php`). Значит, между
 * заказчиком и исполнителем нужен общий журнал: без него панель не может
 * ни показать ход, ни отличить «воркер молчит» от «воркер работает».
 *
 * **Режим обслуживания принадлежит обновлению, а не наоборот.** Мы помним,
 * что было до нас (`maintenance_before`), и возвращаем именно это: сайт,
 * закрытый владельцем на профилактику, не должен открыться сам собой оттого,
 * что мимо прошло обновление. Слепое `maintenance_mode = 0` в конце — самая
 * частая ошибка чужих обновлялок: они открывают то, что закрывали не они.
 *
 * **И обратная беда лечится тоже.** Если процесс убили (таймаут хостинга,
 * OOM, перезагрузка), никто уже не снимет флаг, и сайт останется закрытым
 * навсегда. Поэтому на каждом шаге пишется отметка времени, а `isStale()`
 * считает молчание дольше `STALE_AFTER` сорванным обновлением.
 *
 * Но открывать сайт после такого обрыва можно **не всегда**, и это главная
 * развилка класса. Обрыв до начала замены файлов оставляет сайт целым — его
 * надо открыть. Обрыв во время замены оставляет половину старых файлов и
 * половину новых: открытый, такой сайт отдаёт 500 всем подряд, а закрытый —
 * честные 503 и понятную страницу. Поэтому перед `Updater::apply()` ставится
 * отметка `files_touched`, и дальше:
 *
 *  - файлы не трогали → `maintenanceStuck()` истинно, и `public/index.php`
 *    не показывает заглушку, даже если cron умер вместе с воркером;
 *  - файлы уже менялись → сайт остаётся закрытым, а панель (она доступна
 *    при закрытом сайте) показывает, что случилось, и даёт кнопку сброса.
 *    Здесь нужен человек: возможно, разворачивать резервную копию.
 */
final class UpdateState
{
    /** Ключ в settings: всё состояние — одной строкой JSON. */
    public const KEY = 'update_state';

    public const STATUS_IDLE = 'idle';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * Через сколько секунд молчания обновление считается сорвавшимся.
     *
     * Пятнадцать минут — это заведомо больше самого долгого шага (полная
     * резервная копия сайта с медиатекой), но заметно меньше времени, за
     * которое закрытый сайт становится происшествием.
     */
    public const STALE_AFTER = 900;

    /** Сколько строк журнала храним: панели нужен ход, а не архив. */
    private const LOG_LIMIT = 40;

    /**
     * @return array{status:string, release:string, requested_by:string,
     *     requested_at:int, started_at:int, finished_at:int, heartbeat:int,
     *     step:string, error:string, log:list<array{at:int,level:string,text:string}>,
     *     maintenance_owned:bool, maintenance_before:string, files_touched:bool}
     */
    public static function read(): array
    {
        $raw = Setting::get(self::KEY, '');
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return self::normalize(is_array($data) ? $data : []);
    }

    /**
     * `json_encode()` объявлена как `string|false`, а `Setting::set()` принимает
     * `string`: под `strict_types` отказ кодирования уронил бы запись состояния
     * с `TypeError`, ничего не сказав о причине. Здесь это опаснее, чем в
     * настройках оформления, — состоянием обновления заведуют режим
     * обслуживания и отметки шагов, и записанное неверно означало бы сайт,
     * закрытый на профилактику, которую некому снять. Данные свои, не
     * редакторские, поэтому отказ кодирования — это ошибка, а не замена
     * символов.
     *
     * @param array<string,mixed> $state
     */
    public static function write(array $state): void
    {
        Setting::set(self::KEY, json_encode(
            self::normalize($state),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * Заказ на обновление из панели. Больше веб-запрос не делает ничего:
     * замену файлов он бы не пережил, оборвавшись по таймауту на середине.
     */
    public static function queue(string $release, string $user): void
    {
        // Сначала отдаём режим обслуживания, если он ещё числится за прошлой
        // попыткой. Иначе новое обновление застало бы сайт закрытым, записало
        // бы это как «так было до нас» и добросовестно вернуло бы закрытым
        // после успешной установки — сайт не открылся бы уже никогда.
        self::releaseMaintenance();

        self::write([
            'status' => self::STATUS_QUEUED,
            'release' => $release,
            'requested_by' => $user,
            'requested_at' => time(),
            'heartbeat' => time(),
            'step' => 'Ожидание воркера',
            'log' => [['at' => time(), 'level' => 'ok', 'text' => 'Обновление до ' . $release . ' поставлено в очередь.']],
        ]);
    }

    /** Воркер взял задачу. */
    public static function markRunning(): array
    {
        $state = self::read();
        $state['status'] = self::STATUS_RUNNING;
        $state['started_at'] = time();
        $state['heartbeat'] = time();
        $state['step'] = 'Запуск';
        self::write($state);

        return $state;
    }

    /**
     * Шаг выполнен. Отметка времени здесь же: пока шаги идут, обновление
     * считается живым, и `isStale()` его не тронет.
     */
    public static function step(string $text, string $level = 'ok'): void
    {
        $state = self::read();
        $state['heartbeat'] = time();
        if ($level === 'ok') {
            $state['step'] = $text;
        }
        $state['log'][] = ['at' => time(), 'level' => $level, 'text' => $text];
        if (count($state['log']) > self::LOG_LIMIT) {
            $state['log'] = array_slice($state['log'], -self::LOG_LIMIT);
        }
        self::write($state);
    }

    /** Обновление закончилось — успехом или отказом. */
    public static function finish(string $status, string $error = ''): void
    {
        $state = self::read();
        $state['status'] = $status;
        $state['finished_at'] = time();
        $state['heartbeat'] = time();
        $state['error'] = $error;
        $state['step'] = $status === self::STATUS_DONE ? 'Готово' : 'Остановлено';
        $state['log'][] = [
            'at' => time(),
            'level' => $status === self::STATUS_DONE ? 'ok' : 'fail',
            'text' => $status === self::STATUS_DONE ? 'Обновление завершено.' : ('Обновление остановлено: ' . $error),
        ];
        self::write($state);
    }

    /**
     * Закрывает сайт на время замены файлов, запомнив, что было до нас.
     * Возвращать потом надо именно прежнее значение: сайт, закрытый
     * владельцем на профилактику, обновление открывать не вправе.
     */
    public static function takeMaintenance(): void
    {
        $state = self::read();
        if ($state['maintenance_owned']) {
            return;
        }
        $state['maintenance_before'] = Setting::get('maintenance_mode', '0');
        $state['maintenance_owned'] = true;
        $state['heartbeat'] = time();
        self::write($state);
        Setting::set('maintenance_mode', '1');
    }

    /** Возвращает режим обслуживания в то состояние, в каком его застали. */
    public static function releaseMaintenance(): void
    {
        $state = self::read();
        if (!$state['maintenance_owned']) {
            return;
        }
        Setting::set('maintenance_mode', $state['maintenance_before'] === '1' ? '1' : '0');
        $state['maintenance_owned'] = false;
        $state['heartbeat'] = time();
        self::write($state);
    }

    /**
     * Замена файлов началась. С этого мгновения обрыв означает возможную
     * половину старых файлов и половину новых, и сайт сам не открывается.
     */
    public static function markFilesTouched(): void
    {
        $state = self::read();
        $state['files_touched'] = true;
        $state['heartbeat'] = time();
        self::write($state);
    }

    /**
     * Обновление молчит дольше положенного — значит, процесс убили.
     *
     * @param array<string,mixed>|null $state
     */
    public static function isStale(?array $state = null): bool
    {
        $state = $state === null ? self::read() : self::normalize($state);
        if (!in_array($state['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        return time() - $state['heartbeat'] > self::STALE_AFTER;
    }

    /**
     * Сайт закрыт режимом обслуживания, который включило сорвавшееся
     * обновление. Спрашивает `public/index.php`: cron мог умереть вместе с
     * воркером, и тогда снять флаг больше некому — сайт открывается сам.
     */
    public static function maintenanceStuck(): bool
    {
        $state = self::read();

        return $state['maintenance_owned']
            && $state['maintenance_before'] !== '1'
            // Замена файлов уже шла — сайт может быть собран наполовину.
            // Открытый, он отдаёт 500 всем подряд; закрытый — честные 503.
            && !$state['files_touched']
            && self::isStale($state);
    }

    /**
     * Прибирает за сорвавшимся обновлением: снимает режим обслуживания и
     * помечает попытку неудачной. Зовёт воркер перед тем, как взять новую
     * задачу, — иначе «выполняется» висело бы вечно.
     */
    public static function recoverStale(): bool
    {
        if (!self::isStale()) {
            return false;
        }

        $state = self::read();
        $touched = $state['files_touched'];
        if (!$touched) {
            // Файлы целы — открываем сайт сами.
            self::releaseMaintenance();
        }

        self::finish(
            self::STATUS_FAILED,
            'процесс обновления оборвался (нет отклика дольше ' . (int) round(self::STALE_AFTER / 60) . ' мин). '
                . ($touched
                    ? 'Замена файлов уже шла, поэтому сайт остаётся закрытым: сверьте его работу и снимите '
                        . 'режим обслуживания кнопкой ниже, а при поломке разверните резервную копию, '
                        . 'снятую перед обновлением.'
                    : 'Файлы заменяться не начинали — сайт открыт, можно повторить обновление.')
        );

        return true;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{status:string, release:string, requested_by:string,
     *     requested_at:int, started_at:int, finished_at:int, heartbeat:int,
     *     step:string, error:string, log:list<array{at:int,level:string,text:string}>,
     *     maintenance_owned:bool, maintenance_before:string, files_touched:bool}
     */
    private static function normalize(array $data): array
    {
        $log = [];
        foreach ((array) ($data['log'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $log[] = [
                'at' => (int) ($line['at'] ?? 0),
                'level' => ($line['level'] ?? 'ok') === 'fail' ? 'fail' : 'ok',
                'text' => (string) ($line['text'] ?? ''),
            ];
        }

        $status = (string) ($data['status'] ?? self::STATUS_IDLE);
        $known = [self::STATUS_IDLE, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_DONE, self::STATUS_FAILED];

        return [
            'status' => in_array($status, $known, true) ? $status : self::STATUS_IDLE,
            'release' => (string) ($data['release'] ?? ''),
            'requested_by' => (string) ($data['requested_by'] ?? ''),
            'requested_at' => (int) ($data['requested_at'] ?? 0),
            'started_at' => (int) ($data['started_at'] ?? 0),
            'finished_at' => (int) ($data['finished_at'] ?? 0),
            'heartbeat' => (int) ($data['heartbeat'] ?? 0),
            'step' => (string) ($data['step'] ?? ''),
            'error' => (string) ($data['error'] ?? ''),
            'log' => $log,
            'maintenance_owned' => (bool) ($data['maintenance_owned'] ?? false),
            'maintenance_before' => ($data['maintenance_before'] ?? '0') === '1' ? '1' : '0',
            'files_touched' => (bool) ($data['files_touched'] ?? false),
        ];
    }
}
