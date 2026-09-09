<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Защита CLI-only скриптов (задача 1.1). Служебные утилиты в database/
 * (create_admin.php, backup.php, migrate.php) не должны исполняться через
 * веб-сервер: иначе, в обход public/index.php и всей security-логики, можно
 * было бы, например, создать администратора из браузера.
 *
 * assertCli() прерывает выполнение с HTTP 403, если скрипт запущен не из
 * командной строки. Логика вынесена в тестируемый метод isCli(), чтобы
 * покрыть поведение unit-тестом без реального выхода из процесса.
 */
final class Cli
{
    /** Запущен ли текущий процесс из командной строки. */
    public static function isCli(?string $sapi = null): bool
    {
        $sapi ??= PHP_SAPI;

        return $sapi === 'cli' || $sapi === 'phpdbg';
    }

    /**
     * Прерывает выполнение с 403, если скрипт вызван не через CLI.
     * Вызывать первой строкой в CLI-only скриптах.
     */
    public static function assertCli(): void
    {
        if (self::isCli()) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(403);
        }
        exit('CLI only');
    }
}
