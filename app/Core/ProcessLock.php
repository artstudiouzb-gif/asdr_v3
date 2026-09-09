<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Межпроцессная блокировка для cron-воркеров (группа 6). Защищает от запуска
 * нового экземпляра воркера поверх ещё не завершившегося предыдущего
 * (например, если задача выполняется дольше интервала cron).
 *
 * Использование:
 *   $lock = ProcessLock::acquire('mail_worker');
 *   if ($lock === null) { exit(0); }   // уже выполняется
 *   try { ... } finally { ProcessLock::release($lock); }
 */
final class ProcessLock
{
    private static function dir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/cache';
    }

    /**
     * Пытается получить эксклюзивную блокировку. Возвращает дескриптор файла
     * при успехе или null, если блокировка уже занята другим процессом.
     *
     * @return resource|null
     */
    public static function acquire(string $name)
    {
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? 'lock';
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $handle = fopen($dir . '/' . $safe . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public static function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
