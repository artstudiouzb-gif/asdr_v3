<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Совместимое расширение таблицы files редакционными метаданными.
 *
 * Миграция остаётся основным способом обновления БД. Этот helper нужен как
 * страховка для установок, созданных из старого schema.sql: медиабиблиотека
 * сама один раз добавит недостающие колонки вместо фатальной ошибки.
 */
final class MediaMetadataSchema
{
    private static bool $ready = false;

    /** @var array<string, string> */
    private const COLUMNS = [
        'alt_text' => 'VARCHAR(255) NULL AFTER uploaded_by',
        'caption' => 'VARCHAR(255) NULL AFTER alt_text',
        'description' => 'TEXT NULL AFTER caption',
        'credit' => 'VARCHAR(255) NULL AFTER description',
        'focal_x' => 'TINYINT UNSIGNED NULL AFTER credit',
        'focal_y' => 'TINYINT UNSIGNED NULL AFTER focal_x',
        'metadata_updated_at' => 'DATETIME NULL AFTER focal_y',
    ];

    public static function ensure(?PDO $pdo = null): void
    {
        if (self::$ready) {
            return;
        }

        $pdo ??= Database::pdo();
        foreach (self::COLUMNS as $column => $definition) {
            // MySQL не поддерживает placeholder в SHOW ... LIKE. Значение
            // всё равно экранируется PDO, а имя колонки берётся только из
            // закрытой константы приложения.
            $check = $pdo->query('SHOW COLUMNS FROM `files` LIKE ' . $pdo->quote($column));
            if ($check !== false && $check->fetchColumn() !== false) {
                continue;
            }

            // Имена и определения колонок заданы константой приложения, не
            // поступают от пользователя и потому безопасны для DDL.
            $pdo->exec('ALTER TABLE `files` ADD COLUMN `' . $column . '` ' . $definition);
        }

        self::$ready = true;
    }
}
