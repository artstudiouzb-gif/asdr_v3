<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

/**
 * Редактируемые из админки переопределения системного словаря t().
 *
 * Исходные lang/*.php остаются частью релиза и безопасным fallback. В БД
 * храним только значения, которые редактор изменил через интерфейс.
 */
final class InterfaceTranslation
{
    /** @var array<string, array<string,string>>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function forLanguage(string $lang): array
    {
        $lang = self::cleanLang($lang);
        if ($lang === '' || !Database::isConnected()) {
            return [];
        }

        if (self::$cache === null) {
            self::$cache = self::loadAll();
        }

        return self::$cache[$lang] ?? [];
    }

    /** @return array<string, array<string,string>> */
    public static function all(): array
    {
        if (!Database::isConnected()) {
            return [];
        }
        if (self::$cache === null) {
            self::$cache = self::loadAll();
        }

        return self::$cache;
    }

    /**
     * Сохраняет только непустые переводы. Пустое значение удаляет override и
     * снова включает fallback на lang/<code>.php либо на русский ключ.
     *
     * @param array<string,mixed> $values
     */
    public static function saveLanguage(string $lang, array $values): void
    {
        $lang = self::cleanLang($lang);
        if ($lang === '' || !Database::isConnected()) {
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare(
                'INSERT INTO interface_translations (lang, translation_key, translation_value, updated_at)
                 VALUES (:lang, :translation_key, :translation_value, NOW())
                 ON DUPLICATE KEY UPDATE translation_value = VALUES(translation_value), updated_at = NOW()'
            );
            $delete = $pdo->prepare(
                'DELETE FROM interface_translations WHERE lang = :lang AND translation_key = :translation_key'
            );

            foreach ($values as $key => $value) {
                $key = trim((string) $key);
                $value = trim(is_scalar($value) ? (string) $value : '');
                if ($key === '' || mb_strlen($key) > 500 || mb_strlen($value) > 4000) {
                    continue;
                }
                if ($value === '') {
                    $delete->execute([':lang' => $lang, ':translation_key' => $key]);
                    continue;
                }
                $upsert->execute([
                    ':lang' => $lang,
                    ':translation_key' => $key,
                    ':translation_value' => $value,
                ]);
            }
            $pdo->commit();
            self::flush();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function renameLanguage(string $from, string $to): void
    {
        $from = self::cleanLang($from);
        $to = self::cleanLang($to);
        if ($from === '' || $to === '' || $from === $to || !Database::isConnected()) {
            return;
        }

        Database::pdo()->prepare(
            'UPDATE interface_translations SET lang = :to WHERE lang = :from'
        )->execute([':to' => $to, ':from' => $from]);
        self::flush();
    }

    public static function deleteLanguage(string $lang): void
    {
        $lang = self::cleanLang($lang);
        if ($lang === '' || !Database::isConnected()) {
            return;
        }

        Database::pdo()->prepare('DELETE FROM interface_translations WHERE lang = :lang')
            ->execute([':lang' => $lang]);
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string, array<string,string>> */
    private static function loadAll(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT lang, translation_key, translation_value
                 FROM interface_translations
                 ORDER BY lang, translation_key'
            )->fetchAll();
        } catch (\Throwable $e) {
            // Во время deploy код может появиться на секунды раньше миграции.
            // Публичный сайт в это окно обязан продолжить работать на файлах.
            Logger::swallowed('InterfaceTranslation: таблица переводов недоступна', $e);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $lang = self::cleanLang((string) ($row['lang'] ?? ''));
            $key = (string) ($row['translation_key'] ?? '');
            $value = (string) ($row['translation_value'] ?? '');
            if ($lang !== '' && $key !== '' && $value !== '') {
                $result[$lang][$key] = $value;
            }
        }

        return $result;
    }

    private static function cleanLang(string $lang): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($lang)) ?? '';
    }
}
