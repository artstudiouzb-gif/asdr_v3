<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Проверка системных требований и прав доступа для веб-инсталлятора.
 */
final class EnvironmentCheck
{
    /**
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public static function requirements(): array
    {
        $checks = [];

        // Анализатор считает эту проверку лишней, и по-своему прав: пакет
        // объявляет требование PHP 8.4+, значит на любой поддерживаемой версии
        // сравнение истинно. Но установщик для того и существует, что
        // запускается на **чужом** сервере — на той версии, какая там стоит, и
        // именно её должен назвать в отчёте. Проверка на заявленный минимум
        // здесь не тавтология, а единственное место, где расхождение видно
        // человеку.
        // @phpstan-ignore greaterOrEqual.alwaysTrue
        $phpOk = PHP_VERSION_ID >= 80400;
        $checks[] = [
            'label' => 'PHP 8.4 или новее (текущая: ' . PHP_VERSION . ')',
            'ok' => $phpOk,
            // @phpstan-ignore ternary.alwaysTrue
            'hint' => $phpOk ? '' : 'Обновите PHP до версии 8.4+.',
        ];

        foreach ([
            'pdo_mysql',
            'mbstring',
            'json',
            'gd',
            'curl',
            'dom',
            'fileinfo',
            'openssl',
            'zip',
        ] as $ext) {
            $ok = extension_loaded($ext);
            $checks[] = [
                'label' => 'Расширение PHP: ' . $ext,
                'ok' => $ok,
                'hint' => $ok ? '' : 'Установите/включите расширение ' . $ext . '.',
            ];
        }

        return $checks;
    }

    /**
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public static function permissions(): array
    {
        // Некоторые ZIP-распаковщики и панели shared-хостинга не сохраняют
        // пустые каталоги. Перед проверкой прав безопасно восстанавливаем
        // runtime-структуру; если у PHP нет прав, обычная проверка ниже
        // покажет понятную ошибку и подсказку по chmod.
        foreach ([
            APP_ROOT . '/storage/logs',
            APP_ROOT . '/storage/cache',
            APP_ROOT . '/storage/sessions',
            APP_ROOT . '/storage/backups',
            APP_ROOT . '/storage/protected_uploads',
            APP_ROOT . '/public/uploads',
            APP_ROOT . '/public/uploads/public',
        ] as $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            } else {
                $dMode = @fileperms($directory);
                if ($dMode !== false && ($dMode & 0005) !== 0005) {
                    @chmod($directory, 0755);
                }
            }
        }

        $paths = [
            'config/' => APP_ROOT . '/config',
            'storage/' => APP_ROOT . '/storage',
            'storage/logs/' => APP_ROOT . '/storage/logs',
            'storage/sessions/' => APP_ROOT . '/storage/sessions',
            'public/uploads/public/' => APP_ROOT . '/public/uploads/public',
            'storage/protected_uploads/' => APP_ROOT . '/storage/protected_uploads',
        ];

        $checks = [];
        foreach ($paths as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $checks[] = [
                'label' => 'Доступна на запись: ' . $label,
                'ok' => $ok,
                'hint' => $ok ? '' : 'Дайте веб-серверу права на запись в ' . $label . ' (chmod 755/775).',
            ];
        }

        return $checks;
    }

    public static function allPassed(): bool
    {
        foreach ([...self::requirements(), ...self::permissions()] as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }
}
