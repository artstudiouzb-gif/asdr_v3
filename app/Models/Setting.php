<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\SecretBox;

final class Setting
{
    private static ?array $cache = null;
    private static bool $cacheFromDatabase = false;

    public static function all(): array
    {
        // Чтение настроек используется и до подключения БД: в установщике,
        // CLI-проверках и автономных unit-тестах. В этих режимах отсутствие
        // строк эквивалентно пустому набору настроек, поэтому вызывающий код
        // должен получить переданные ему значения по умолчанию.
        //
        // Не кешируем этот результат: если соединение появится позже в том же
        // процессе (например, на шаге установщика), следующий вызов прочитает
        // уже реальные значения из таблицы settings.
        if (!Database::isConnected()) {
            return self::$cache ?? [];
        }

        if (self::$cache === null || !self::$cacheFromDatabase) {
            $stmt = Database::pdo()->query('SELECT `key`, `value` FROM settings');
            self::$cache = [];
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) $row['key'];
                $value = (string) $row['value'];
                if (self::isSecret($key)) {
                    try {
                        $value = SecretBox::decrypt($value, 'settings.' . $key) ?? '';
                    } catch (\Throwable $e) {
                        Logger::error('Не удалось расшифровать настройку ' . $key . ': ' . $e->getMessage());
                        $value = '';
                    }
                }
                self::$cache[$key] = $value;
            }
            self::$cacheFromDatabase = true;
        }

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    /**
     * Возвращает значение настройки с учётом выбранного языка (site_name_uz, site_name_ru и т.д.).
     * Если перевода для языка нет, возвращается базовое значение этой настройки.
     */
    public static function getLocalized(string $key, ?string $lang = null, string $default = ''): string
    {
        $lang = strtolower(trim((string) ($lang ?? \App\Core\Locale::current())));
        $defaultLang = strtolower(Language::defaultCode());

        if ($lang !== '' && $lang !== $defaultLang) {
            $localizedVal = trim(self::get($key . '_' . $lang, ''));
            if ($localizedVal !== '') {
                return $localizedVal;
            }
        }

        $baseVal = trim(self::get($key, ''));
        if ($baseVal !== '') {
            return $baseVal;
        }

        return $default;
    }

    public static function set(string $key, string $value): void
    {
        // Для CLI/unit-режима поддерживаем настройки в памяти. Это позволяет
        // тестировать компоненты конфигурации без подключения к рабочей БД;
        // после появления соединения all() отбросит этот временный кэш.
        if (!Database::isConnected()) {
            self::$cache ??= [];
            self::$cache[$key] = $value;
            self::$cacheFromDatabase = false;
            return;
        }

        $stored = self::isSecret($key) && $value !== ''
            ? SecretBox::encrypt($value, 'settings.' . $key)
            : $value;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([':key' => $key, ':value' => $stored]);
        self::$cache = null;
        self::$cacheFromDatabase = false;
    }

    /**
     * Переопределяет значение ТОЛЬКО в памяти текущего запроса (в БД не
     * пишется). Используется живым превью настроек дизайна: страница
     * рендерится с «примеренными» значениями без их сохранения.
     */
    public static function overrideInMemory(string $key, string $value): void
    {
        self::all(); // прогреваем кэш
        self::$cache[$key] = $value;
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, [
            'ai_api_key',
            'cf_api_token',
            's3_access_key',
            's3_secret_key',
            'smtp_password',
            'telegram_gateway_token',
            'telegram_bot_token',
            'webpush_vapid_private',
            'youtube_api_key',
        ], true) || preg_match('/^social_(telegram|facebook|linkedin|instagram)_token$/', $key) === 1;
    }
}
