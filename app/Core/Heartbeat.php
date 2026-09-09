<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Heartbeat фоновых воркеров (группа 2.1). Каждый воркер при старте отмечает
 * время последнего запуска в файле storage/cache/worker_heartbeat_{name}.txt.
 * /health сравнивает возраст метки с ожидаемой частотой и алертит, если воркер
 * замолчал (cron тихо отвалился).
 */
final class Heartbeat
{
    /**
     * Ожидаемый максимальный возраст heartbeat в секундах для известных
     * воркеров. Если метка старше — считаем воркер «залипшим»/остановленным.
     */
    public const EXPECTATIONS = [
        'mail' => 900,       // ~каждую минуту, тревога после 15 мин тишины
        'webhook' => 900,    // ~каждую минуту, тревога после 15 мин
        'social' => 1800,    // ~каждые 5 мин, тревога после 30 мин
        'backup' => 93600,   // раз в сутки, тревога после 26 часов
        'youtube' => 93600,  // импорт с канала: обычно раз в час, тревога после 26 часов
    ];

    private static function dir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/cache';
    }

    private static function file(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '';

        return self::dir() . '/worker_heartbeat_' . $safe . '.txt';
    }

    /** Отмечает факт запуска воркера (текущее время). */
    public static function touch(string $name): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(self::file($name), (string) time());
    }

    /** Время последнего запуска воркера (unix ts) или null, если ни разу не запускался. */
    public static function lastRun(string $name): ?int
    {
        $file = self::file($name);
        if (!is_file($file)) {
            return null;
        }
        $ts = (int) trim((string) file_get_contents($file));

        return $ts > 0 ? $ts : null;
    }

    /**
     * Статус всех известных воркеров. Для воркеров, которые ни разу не
     * запускались (нет файла), stale = false (возможно, просто не настроены —
     * не поднимаем ложную тревогу). stale = true только если метка есть, но
     * устарела относительно ожидаемой частоты.
     *
     * @return array<string,array{last:?int,age:?int,stale:bool,expected:int}>
     */
    public static function status(): array
    {
        $now = time();
        $out = [];
        foreach (self::EXPECTATIONS as $name => $maxAge) {
            $last = self::lastRun($name);
            $age = $last !== null ? $now - $last : null;
            $out[$name] = [
                'last' => $last,
                'age' => $age,
                'stale' => $last !== null && $age > $maxAge,
                'expected' => $maxAge,
            ];
        }

        return $out;
    }
}
