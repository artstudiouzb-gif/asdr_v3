<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Одна строка cron вместо полутора десятков (FUNC-3).
 *
 * Фоновых воркеров у сайта почти двадцать, и каждому полагалась своя строка
 * cron. На shared-хостинге её заводит человек — забыть одну проще простого, и
 * отказ тихий: очередь писем копится, бэкап не снимается, а сторож молчит,
 * если и его строку забыли. Теперь строка одна:
 *
 *   * * * * * php /path/to/app/Console/scheduler.php
 *
 * а расписание каждого воркера объявлено здесь, списком `TASKS`, и видно в
 * «Состоянии системы» вместе с последним запуском.
 *
 * Правила, из которых выведено устройство:
 * - **Воркер запускается отдельным процессом в фоне.** Подключить его в тот
 *   же процесс нельзя: воркеры заканчиваются `exit()`, и первый же оборвал бы
 *   проход по остальным. Ждать каждого тоже нельзя: бэкап идёт минутами, и
 *   письма той же минуты ушли бы после него.
 * - **Пропущенная минута не теряет задачу.** Задача должна, если с её
 *   прошлого запуска наступил хотя бы один момент по расписанию — так ночной
 *   бэкап, чья минута пришлась на перезагрузку сервера, уйдёт со следующим
 *   проходом, а не через сутки. Первый проход ничего не наверстывает.
 * - **Отдельные строки cron продолжают работать.** Воркеры сами держат
 *   блокировку (`ProcessLock`), поэтому двойной запуск безопасен, а переход
 *   на одну строку можно делать постепенно.
 */
final class Scheduler
{
    /** Задача → [скрипт в app/Console, расписание cron]. */
    public const TASKS = [
        'mail' => ['mail_worker.php', '* * * * *'],
        'notification' => ['notification_worker.php', '* * * * *'],
        'queue' => ['queue_worker.php', '* * * * *'],
        'webhook' => ['webhook_worker.php', '* * * * *'],
        'update' => ['update_worker.php', '* * * * *'],
        'migration' => ['migration_worker.php', '* * * * *'],
        'social' => ['social_worker.php', '*/5 * * * *'],
        'push' => ['push_worker.php', '*/5 * * * *'],
        'indexnow' => ['indexnow_worker.php', '*/15 * * * *'],
        'watchdog' => ['watchdog.php', '*/15 * * * *'],
        'integrity' => ['integrity_check.php', '17 * * * *'],
        'youtube' => ['youtube_worker.php', '0 * * * *'],
        'instagram' => ['instagram_worker.php', '5 * * * *'],
        'backup' => ['backup_worker.php', '0 3 * * *'],
        'gdpr' => ['gdpr_cleanup.php', '30 3 * * *'],
        'restore_drill' => ['restore_drill.php', '40 3 * * 0'],
        'seo' => ['seo_worker.php', '25 4 * * *'],
        'links' => ['link_worker.php', '40 4 * * *'],
        'digest' => ['digest_worker.php', '0 9 * * 1'],
    ];

    public const LABELS = [
        'mail' => 'Отправка писем',
        'notification' => 'Уведомления панели',
        'queue' => 'Очередь задач',
        'webhook' => 'Вебхуки',
        'update' => 'Обновление системы',
        'migration' => 'Переезд на другой хостинг',
        'social' => 'Публикации в соцсети',
        'push' => 'Push-уведомления',
        'indexnow' => 'Уведомление поисковиков (IndexNow)',
        'watchdog' => 'Сторож',
        'integrity' => 'Сверка целостности файлов',
        'youtube' => 'Импорт с YouTube',
        'instagram' => 'Лента Instagram',
        'backup' => 'Резервная копия',
        'gdpr' => 'Ночная уборка и индекс поиска',
        'restore_drill' => 'Репетиция восстановления',
        'seo' => 'Проверка индексации',
        'links' => 'Проверка ссылок',
        'digest' => 'Дайджест подписчикам',
    ];

    /** Дольше этого назад не заглядываем: после простоя в неделю запуск один, а не тысяча. */
    private const CATCH_UP_MINUTES = 1440;

    public static function stateFile(): string
    {
        return APP_ROOT . '/storage/cache/scheduler.json';
    }

    /**
     * Минута подходит под выражение cron (пять полей: `*`, `*\/n`, `a-b`,
     * списки через запятую). День месяца и день недели — по правилу cron:
     * если ограничены оба, достаточно совпасть одному.
     */
    public static function matches(string $expression, int $timestamp): bool
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return false;
        }
        [$minute, $hour, $dom, $month, $dow] = $parts;
        $t = getdate($timestamp);

        if (!self::field($minute, $t['minutes'], 0, 59)
            || !self::field($hour, $t['hours'], 0, 23)
            || !self::field($month, $t['mon'], 1, 12)) {
            return false;
        }
        $domOk = self::field($dom, $t['mday'], 1, 31);
        $dowOk = self::field($dow, $t['wday'], 0, 7) || ($t['wday'] === 0 && self::field($dow, 7, 0, 7));
        if ($dom !== '*' && $dow !== '*') {
            return $domOk || $dowOk;
        }

        return $domOk && $dowOk;
    }

    private static function field(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = explode('/', $part, 2);
                if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                    return false;
                }
                $step = (int) $stepRaw;
            }
            if ($part === '*') {
                [$from, $to] = [$min, $max];
            } elseif (preg_match('/^(\d+)-(\d+)$/', $part, $m) === 1) {
                [$from, $to] = [(int) $m[1], (int) $m[2]];
            } elseif (ctype_digit($part)) {
                $from = (int) $part;
                $to = $step > 1 ? $max : $from;
            } else {
                return false;
            }
            if ($value >= $from && $value <= $to && ($value - $from) % $step === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Пора ли задаче: наступил ли момент по расписанию после прошлого запуска.
     * Без прошлого запуска — только если подходит текущая минута.
     */
    public static function isDue(string $expression, ?int $lastRun, int $now): bool
    {
        $current = $now - ($now % 60);
        if ($lastRun === null) {
            return self::matches($expression, $current);
        }
        $from = max($lastRun - ($lastRun % 60) + 60, $current - (self::CATCH_UP_MINUTES - 1) * 60);
        for ($minute = $from; $minute <= $current; $minute += 60) {
            if (self::matches($expression, $minute)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> задача → время последнего запуска планировщиком */
    public static function lastRuns(): array
    {
        $file = self::stateFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $task => $ts) {
            if (is_string($task) && isset(self::TASKS[$task]) && is_int($ts)) {
                $out[$task] = $ts;
            }
        }

        return $out;
    }

    /** @param array<string, int> $runs */
    private static function saveRuns(array $runs): void
    {
        $file = self::stateFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        @file_put_contents($file, json_encode($runs, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * Один проход: запустить всё, чему пора. `$spawn` подменяется в тестах.
     *
     * @param callable(string): bool|null $spawn
     * @return list<string> запущенные задачи
     */
    public static function tick(int $now, ?callable $spawn = null): array
    {
        $spawn ??= [self::class, 'spawn'];
        $runs = self::lastRuns();
        $started = [];
        foreach (self::TASKS as $task => [, $expression]) {
            if (!self::isDue($expression, $runs[$task] ?? null, $now)) {
                continue;
            }
            // Отметка ставится и при неудаче запуска: иначе каждая следующая
            // минута пыталась бы снова, а причина та же.
            $runs[$task] = $now;
            if ($spawn($task)) {
                $started[] = $task;
            }
        }
        self::saveRuns($runs);

        return $started;
    }

    /** Можно ли запускать процессы: `proc_open` бывает отключён хостингом. */
    public static function canSpawn(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * Запуск воркера в фоне: оболочка возвращается сразу, вывод уходит в
     * журнал `storage/logs/<задача>_worker.log`. Имя задачи сверяется со
     * списком — в командную строку попадают только свои пути.
     */
    public static function spawn(string $task): bool
    {
        if (!isset(self::TASKS[$task]) || !self::canSpawn()) {
            return false;
        }
        $script = APP_ROOT . '/app/Console/' . self::TASKS[$task][0];
        if (!is_file($script)) {
            return false;
        }
        $php = self::phpBinary();
        $log = APP_ROOT . '/storage/logs/' . $task . '_worker.log';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r']], $pipes, APP_ROOT);
        if (!is_resource($process)) {
            return false;
        }
        proc_close($process);

        return true;
    }

    /**
     * Интерпретатор для воркеров. В вебе `PHP_BINARY` — это php-fpm или
     * модуль сервера, воркер им не запустить; рядом обычно лежит php-cli.
     */
    private static function phpBinary(): string
    {
        $binary = PHP_BINARY;
        if (PHP_SAPI === 'cli' && $binary !== '') {
            return $binary;
        }
        foreach ([dirname($binary) . '/php', PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
