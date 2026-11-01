<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Импорт YouTube и ленты Instagram без cron.
 *
 * Оба импорта написаны давно и работают — воркерами по cron. Но cron на
 * shared-хостинге настраивает человек, и если строку забыли (или потеряли при
 * переезде), импорт не происходит вовсе, а редактор жмёт «Обновить сейчас»
 * руками — или забывает. Поэтому сайт запускает их и сам: раз в час, после
 * того как ответ ушёл посетителю. Тот же приём, что у IndexNow.
 *
 * Правила, из которых выведено устройство:
 * - **Посетитель не ждёт.** Без `fastcgi_finish_request`/`litespeed_finish_request`
 *   ответ нельзя отпустить раньше импорта, и задача не запускается вовсе —
 *   остаётся cron.
 * - **Проверка «пора ли» почти ничего не стоит**: это чтение двух файлов-меток
 *   (попытка сайта и `Heartbeat` воркера), без базы. Настройки спрашиваются
 *   уже после ответа.
 * - **Метка ставится до запуска**, а не после: иначе каждый запрос, пришедший
 *   за время импорта, запускал бы ещё один. Второй слой — `ProcessLock`, тот
 *   же, что у воркера, поэтому cron и сайт не столкнутся.
 * - **Cron остаётся главнее**: воркер ставит ту же метку, и при живом cron
 *   сайт просто не находит задачи, которой пора.
 */
final class BackgroundTasks
{
    /** Задача → как часто её запускать (сек). */
    public const INTERVALS = [
        'youtube' => 3600,
        'instagram' => 3600,
    ];

    /** Предел времени для фонового прохода: импорт ходит в сеть и скачивает кадры. */
    private const TIME_LIMIT = 180;

    private static bool $scheduled = false;

    /**
     * Задачи, которым пора (по метке последнего запуска).
     *
     * @return list<string>
     */
    public static function due(?int $now = null): array
    {
        $now ??= time();
        $due = [];
        foreach (self::INTERVALS as $task => $interval) {
            // Пора, если давно не было ни попытки сайта, ни запуска воркера.
            $last = max(self::lastAttempt($task) ?? 0, Heartbeat::lastRun($task) ?? 0);
            if ($now - $last >= $interval) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /** Зовёт публичный роутер: если есть задача, которой пора, — запуск после ответа. */
    public static function afterResponse(string $method): void
    {
        if (self::$scheduled || PHP_SAPI === 'cli' || !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
            return;
        }
        if (self::due() === []) {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function([self::class, 'flush']);
    }

    /** @internal вызывается из register_shutdown_function */
    public static function flush(): void
    {
        self::$scheduled = false;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(self::TIME_LIMIT);

        foreach (self::due() as $task) {
            try {
                self::run($task);
            } catch (\Throwable $e) {
                Logger::swallowed('Фоновая задача «' . $task . '» не выполнена', $e);
            }
        }
    }

    /**
     * Один проход задачи — общий для сайта и воркера.
     *
     * @return array{ran:bool, ok:bool, message:string}
     */
    public static function run(string $task, bool $force = false): array
    {
        [$enabled, $configured, $missing] = match ($task) {
            'youtube' => [
                YoutubeImport::settings()['enabled'],
                YoutubeImport::isConfigured(),
                'Канал YouTube не указан или адрес не разобран',
            ],
            'instagram' => [
                InstagramFeed::settings()['enabled'],
                InstagramFeed::isConfigured(),
                'Не задан токен Instagram',
            ],
            default => throw new \InvalidArgumentException('Неизвестная задача: ' . $task),
        };

        // Отметка попытки ставится и у выключенной задачи: иначе на сайте без
        // импорта каждый запрос заново спрашивал бы настройки. Это не
        // Heartbeat — тот означает «импорт работает», и выключенный импорт не
        // должен выглядеть в «Состоянии системы» исправным.
        self::markAttempt($task);
        if (!$force && !$enabled) {
            return ['ran' => false, 'ok' => true, 'message' => 'выключено в админке'];
        }
        if (!$configured) {
            return ['ran' => false, 'ok' => true, 'message' => $missing];
        }

        $lock = ProcessLock::acquire($task . '_worker');
        if ($lock === null) {
            return ['ran' => false, 'ok' => true, 'message' => 'уже выполняется'];
        }
        Heartbeat::touch($task);
        try {
            $result = $task === 'youtube' ? YoutubeImport::sync(null) : InstagramFeed::sync(null);
        } finally {
            ProcessLock::release($lock);
        }
        if (!$result['ok']) {
            Logger::error(($task === 'youtube' ? 'YouTube-импорт не удался: ' : 'Лента Instagram не обновлена: ')
                . $result['error']);

            return ['ran' => true, 'ok' => false, 'message' => (string) $result['error']];
        }

        return ['ran' => true, 'ok' => true, 'message' => (string) $result['summary']];
    }

    private static function attemptFile(string $task): string
    {
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($task)) ?? '';

        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2))
            . '/storage/cache/background_task_' . $safe . '.txt';
    }

    /** @phpstan-impure */
    public static function lastAttempt(string $task): ?int
    {
        $file = self::attemptFile($task);
        if (!is_file($file)) {
            return null;
        }
        $ts = (int) trim((string) @file_get_contents($file));

        return $ts > 0 ? $ts : null;
    }

    public static function markAttempt(string $task, ?int $at = null): void
    {
        $file = self::attemptFile($task);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        @file_put_contents($file, (string) ($at ?? time()));
    }
}
