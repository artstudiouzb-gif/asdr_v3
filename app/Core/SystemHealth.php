<?php

declare(strict_types=1);

namespace App\Core;


/**
 * Состояние системы одним списком проверяемых фактов.
 *
 * Механизмы самопроверки в проекте были написаны давно и работают: `Watchdog`
 * видит молчащие воркеры и кончающийся диск, `Heartbeat` помнит запуски,
 * `Integrity` сверяет файлы с эталоном, `RestoreDrill` репетирует
 * восстановление, `MigrationRunner` знает про неприменённые миграции. Беда в
 * том, где они живут: в CLI и cron на shared-хостинге, куда владелец не
 * заходит. `release_check` годами повторял «восстановление ни разу не
 * проверялось» — и не читал этого никто.
 *
 * То есть в системе, где тихий отказ считается худшим видом отказа, **молча
 * отказывали сами проверки**. Этот класс ничего не проверяет заново — он
 * приводит уже посчитанное к одному виду и отдаёт экрану.
 *
 * **В сеть не ходим.** Не потому, что нельзя (админке — можно), а потому, что
 * страница, которую открывают в тревоге, обязана отвечать сразу: полдесятка
 * запросов к чужим API растянули бы её на десятки секунд, а на упавшем канале
 * связи — до таймаута. Всё, что требует сети, приходит из памяти: воркеры
 * оставляют отметки, интеграции — свои (`IntegrationStatus`). Поэтому у
 * каждого факта есть «когда проверено», и «неизвестно» — это честный ответ,
 * а не пустая строка.
 */
final class SystemHealth
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const UNKNOWN = 'unknown';

    /** Эталон целостности старше этого срока — повод пересобрать. */
    private const BASELINE_STALE = 30 * 86400;

    /** Репетиция восстановления считается свежей столько. */
    private const DRILL_FRESH = 8 * 86400;

    /**
     * Все факты о состоянии, сгруппированные по разделам.
     *
     * @return list<array{title:string, checks:list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}>}>
     */
    public static function groups(): array
    {
        return [
            ['title' => 'Обслуживание', 'checks' => self::maintenance()],
            ['title' => 'Выкладка', 'checks' => self::deployment()],
            ['title' => 'Защита данных', 'checks' => self::protection()],
            ['title' => 'Интеграции', 'checks' => self::integrations()],
        ];
    }

    /** Худшее состояние по всем фактам — для строки-итога наверху. */
    public static function worst(): string
    {
        $worst = self::OK;
        foreach (self::groups() as $group) {
            foreach ($group['checks'] as $check) {
                if ($check['state'] === self::FAIL) {
                    return self::FAIL;
                }
                if ($check['state'] === self::WARN) {
                    $worst = self::WARN;
                }
            }
        }

        return $worst;
    }

    /** @return list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> */
    private static function maintenance(): array
    {
        $checks = [];

        foreach (Heartbeat::status() as $name => $state) {
            $last = $state['last'];
            if ($last === null) {
                // Никогда не запускавшийся воркер — это не поломка: возможно,
                // он просто не нужен на этой установке. Но и «в порядке» тут
                // сказать нельзя: если он нужен, молчание выглядит так же.
                $checks[] = self::check(
                    'worker:' . $name,
                    'Воркер «' . $name . '»',
                    self::UNKNOWN,
                    'ни разу не запускался',
                    'Если этот воркер нужен, добавьте его вызов в cron — иначе задача не выполняется вовсе.',
                    null
                );
                continue;
            }

            $stale = !empty($state['stale']);
            $checks[] = self::check(
                'worker:' . $name,
                'Воркер «' . $name . '»',
                $stale ? self::FAIL : self::OK,
                $stale
                    ? 'молчит ' . self::age((int) $state['age']) . ' при ожидаемых ' . self::age((int) $state['expected'])
                    : 'отработал ' . self::age((int) $state['age']) . ' назад',
                $stale ? 'Похоже, cron перестал его вызывать. Проверьте расписание на хостинге.' : '',
                $last
            );
        }

        // Очереди и диск считает сторож. Его же строки про воркеров пропускаем:
        // они уже разобраны выше поимённо, а второй раз тот же факт читается
        // как две разные проблемы.
        try {
            $problems = Watchdog::check();
        } catch (\Throwable $e) {
            // Сторож спрашивает очереди у базы. Открыть раздел при мёртвой базе
            // всё равно нельзя (панель требует входа), но падать здесь он не
            // вправе: экран состояния — последнее, что должно ломаться.
            Logger::swallowed('SystemHealth: сторож недоступен', $e);
            $problems = [];
        }

        foreach ($problems as $key => $text) {
            if (str_starts_with($key, 'worker:')) {
                continue;
            }
            $checks[] = self::check(
                'watchdog:' . $key,
                $key === 'disk' ? 'Место на диске' : 'Очередь задач',
                self::FAIL,
                $text,
                '',
                time()
            );
        }
        if (!self::hasKey($checks, 'watchdog:disk')) {
            $checks[] = self::check('watchdog:disk', 'Место на диске', self::OK, 'запаса хватает', '', time());
        }

        return $checks;
    }

    /** @return list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> */
    private static function deployment(): array
    {
        $checks = [];

        $version = Release::id();
        // По форме идентификатора видно способ выкладки: ветка `deploy` кладёт
        // короткий SHA, архив релиза — имя тега. Их нельзя смешивать на одном
        // сервере, и путаница уже случалась, поэтому способ назван прямо.
        $isSha = preg_match('/^[0-9a-f]{7,40}$/', $version) === 1;
        $checks[] = self::check(
            'release',
            'Установленная версия',
            $version === '' || $version === 'unknown' ? self::WARN : self::OK,
            $version === '' || $version === 'unknown'
                ? 'неизвестна'
                : $version . ($isSha ? ' — выложено из ветки deploy (git pull)' : ' — установлено из архива релиза'),
            $isSha
                ? 'Обновляйтесь через git pull. Кнопка в разделе «Обновление» развернула бы поверх git-дерева архив релиза и подменила бы способ выкладки.'
                : ($version === '' || $version === 'unknown'
                    ? 'В storage/release.json нет версии: панель обновления будет считать доступным любой релиз.'
                    : ''),
            null
        );

        $checks[] = self::migrations();

        return $checks;
    }

    /**
     * Миграции: три состояния вместо прежних двух.
     *
     * Панель показывала «неприменённых нет» и в том случае, когда файлов
     * миграций на сервере вовсе нет — она перечисляет их с диска, и о файле,
     * который не доехал при выкладке, знать не может. Отличить это можно по
     * записям в базе: если в `migrations` числятся файлы, которых на диске
     * больше нет, значит база новее выложенного кода.
     *
     * @return array{id:string,title:string,state:string,value:string,hint:string,at:?int}
     */
    private static function migrations(): array
    {
        $dir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/database/migrations';
        try {
            $status = MigrationRunner::status(Database::pdo(), $dir);
        } catch (\Throwable $e) {
            Logger::swallowed('SystemHealth: состояние миграций недоступно', $e);

            return self::check('migrations', 'Миграции базы', self::UNKNOWN, 'не удалось прочитать', '', null);
        }

        $onDisk = [];
        foreach ($status['all'] as $item) {
            $onDisk[$item['name']] = true;
        }
        $missing = [];
        foreach ($status['applied'] as $item) {
            if (!isset($onDisk[$item['name']])) {
                $missing[] = $item['name'];
            }
        }

        if ($status['total'] === 0) {
            return self::check(
                'migrations',
                'Миграции базы',
                self::FAIL,
                'файлов миграций на сервере нет',
                'Каталог database/migrations пуст — выкладка не довезла файлы. Пока их нет, панель не может знать о неприменённых миграциях.',
                null
            );
        }

        if ($missing !== []) {
            return self::check(
                'migrations',
                'Миграции базы',
                self::WARN,
                'база знает ' . count($missing) . ' миграций, которых нет в файлах',
                'База новее выложенного кода: похоже, релиз доехал не полностью. Первая из недостающих — ' . $missing[0] . '.',
                null
            );
        }

        $pending = (int) $status['pending_count'];

        return self::check(
            'migrations',
            'Миграции базы',
            $pending > 0 ? self::WARN : self::OK,
            $pending > 0
                ? 'не применено: ' . $pending
                : 'применены все ' . (int) $status['total'],
            $pending > 0 ? 'Примените их в разделе «Базы данных» — часть функций до этого работать не будет.' : '',
            null
        );
    }

    /** @return list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> */
    private static function protection(): array
    {
        $checks = [];

        $appUrl = (string) Config::get('app.url', '');
        $https = str_starts_with(strtolower(trim($appUrl)), 'https://');
        $checks[] = self::check(
            'https',
            'HTTPS',
            $https ? self::OK : self::WARN,
            $https ? 'адрес сайта объявлен по https' : 'адрес сайта объявлен по http',
            $https ? '' : 'Пока app.url начинается с http://, не работает ни принудительный переход на https, ни HSTS, а cookie админки уходит открытым текстом.',
            null
        );

        $baselineAge = Integrity::baselineAge();
        if (!Integrity::hasBaseline()) {
            $checks[] = self::check(
                'integrity',
                'Эталон целостности',
                self::WARN,
                'не собран',
                'Без эталона подмену файлов кода замечать нечем. Собирается при выпуске релиза и при обновлении.',
                null
            );
        } else {
            $stale = $baselineAge !== null && $baselineAge > self::BASELINE_STALE;
            $checks[] = self::check(
                'integrity',
                'Эталон целостности',
                $stale ? self::WARN : self::OK,
                $baselineAge !== null ? 'собран ' . self::age($baselineAge) . ' назад' : 'собран',
                $stale ? 'Эталон старше выложенного кода — сверка будет ругаться на ваши же обновления.' : '',
                $baselineAge !== null ? time() - $baselineAge : null
            );
        }

        $drill = @filemtime(RestoreDrill::markerPath());
        if ($drill === false) {
            $checks[] = self::check(
                'restore_drill',
                'Репетиция восстановления',
                self::WARN,
                'ни разу не проводилась',
                'Копия, которую ни разу не восстанавливали, бэкапом не является. Репетицию выполняет app/Console/restore_drill.php по cron.',
                null
            );
        } else {
            $age = time() - $drill;
            $checks[] = self::check(
                'restore_drill',
                'Репетиция восстановления',
                $age > self::DRILL_FRESH ? self::WARN : self::OK,
                'успешно ' . self::age($age) . ' назад',
                $age > self::DRILL_FRESH ? 'Давно не проверялось, что копия действительно разворачивается.' : '',
                $drill
            );
        }

        // Журнал держится отдельным разделом, но молчащий о себе журнал —
        // это тот же тихий отказ: за неделю владелец дважды скачивал файл и
        // отдавал его инженеру, чтобы узнать, что у него на сайте. Число за
        // сутки, а не за всё время: важно, что происходит сейчас.
        $errors = LogReader::countSince('error');
        $checks[] = self::check(
            'errors_24h',
            'Ошибки за сутки',
            $errors === 0 ? self::OK : ($errors >= 50 ? self::FAIL : self::WARN),
            $errors === 0 ? 'ни одной' : 'записей: ' . $errors,
            $errors === 0 ? '' : 'Разбор — в разделе «Журнал ошибок»: одинаковые записи там сведены, и видно, что повторяется.',
            time()
        );

        $ping = trim((string) getenv('MONITORING_HEARTBEAT_URL'));
        $checks[] = self::check(
            'heartbeat_ping',
            'Сигнал наружу',
            $ping !== '' ? self::OK : self::WARN,
            $ping !== '' ? 'настроен' : 'не настроен',
            $ping !== '' ? '' : 'Сторож живёт на этом же сервере: если упадёт сервер или cron, сообщить о простое будет некому. Адрес приёмника задаётся переменной MONITORING_HEARTBEAT_URL.',
            null
        );

        return $checks;
    }

    /** @return list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> */
    private static function integrations(): array
    {
        $checks = [];
        foreach (IntegrationStatus::all() as $row) {
            if (!$row['used']) {
                $checks[] = self::check(
                    'integration:' . $row['name'],
                    $row['title'],
                    self::UNKNOWN,
                    'ни разу не использовалась',
                    'Либо она не настроена, либо её ещё не вызывали. До первого вызова сказать о ней нечего.',
                    null
                );
                continue;
            }

            $okAt = $row['ok_at'];
            $failAt = $row['fail_at'];
            // Последняя по времени попытка и решает состояние. Обе отметки при
            // этом остаются: успех не стирает вчерашний сбой, а сбой — того,
            // что десять минут назад всё работало.
            $failing = $failAt !== null && ($okAt === null || $failAt > $okAt);
            $value = $okAt !== null
                ? 'работала ' . self::age(time() - $okAt) . ' назад'
                : 'ни разу не срабатывала успешно';
            if ($failAt !== null) {
                $value .= '; последний отказ ' . self::age(time() - $failAt) . ' назад';
            }

            $checks[] = self::check(
                'integration:' . $row['name'],
                $row['title'],
                $failing ? self::FAIL : self::OK,
                $value,
                $failing ? $row['error'] : '',
                $okAt ?? $failAt
            );
        }

        return $checks;
    }

    /** @return array{id:string,title:string,state:string,value:string,hint:string,at:?int} */
    private static function check(string $id, string $title, string $state, string $value, string $hint, ?int $at): array
    {
        return ['id' => $id, 'title' => $title, 'state' => $state, 'value' => $value, 'hint' => $hint, 'at' => $at];
    }

    /** @param list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> $checks */
    private static function hasKey(array $checks, string $id): bool
    {
        foreach ($checks as $check) {
            if ($check['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** Возраст словами: экран читают глазами, а не считают секунды. */
    public static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 90) {
            return 'меньше минуты';
        }
        if ($seconds < 5400) {
            return (int) round($seconds / 60) . ' мин';
        }
        if ($seconds < 172800) {
            return (int) round($seconds / 3600) . ' ч';
        }

        return (int) round($seconds / 86400) . ' дн';
    }
}
