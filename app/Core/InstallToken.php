<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Одноразовый токен веб-установщика.
 *
 * Пока сайт не установлен, `/install` открыт всем: кто первым дойдёт до
 * страницы на свежем сервере, тот и укажет свою базу и создаст своего
 * администратора. Токен закрывает это окно: при первом заходе установщик
 * кладёт случайную строку в `storage/install.token` (права 0600), и дальше
 * пускает только того, кто её введёт. Прочитать файл может лишь владелец
 * хостинга — файловым менеджером или по FTP, — то есть токен доказывает
 * доступ к серверу, а не к адресу сайта.
 *
 * Файл, а не строка на экране и не письмо: показать токен посетителю значит
 * отдать его первому встречному, а почты на свежей установке ещё нет.
 * После завершения установки файл удаляется.
 */
final class InstallToken
{
    /** 128 бит: перебор по сети бессмыслен и без ограничителя частоты. */
    private const BYTES = 16;

    public static function path(): string
    {
        return APP_ROOT . '/storage/install.token';
    }

    /**
     * Создаёт файл токена, если его ещё нет. Ложь — записать не удалось
     * (нет прав на storage): установщик говорит об этом, а не пускает без токена.
     */
    public static function ensure(?string $path = null): bool
    {
        $path ??= self::path();
        if (self::read($path) !== null) {
            return true;
        }
        $token = bin2hex(random_bytes(self::BYTES));
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            // Файл мог появиться в соседнем запросе — тогда он и действует.
            return self::read($path) !== null;
        }
        $written = fwrite($handle, $token . "\n");
        fclose($handle);
        @chmod($path, 0600);

        return $written !== false && self::read($path) !== null;
    }

    public static function verify(string $candidate, ?string $path = null): bool
    {
        $expected = self::read($path ?? self::path());
        $candidate = strtolower(trim($candidate));

        return $expected !== null && $candidate !== '' && hash_equals($expected, $candidate);
    }

    public static function remove(?string $path = null): void
    {
        $path ??= self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $value = strtolower(trim((string) @file_get_contents($path)));

        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }
}
