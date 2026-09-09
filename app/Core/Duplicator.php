<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Универсальное копирование записей и их дочерних строк для функции
 * «Дублировать» (задача 80). Имена таблиц/колонок передаются только из кода
 * (не из пользовательского ввода), поэтому подставляются в SQL напрямую;
 * значения — через prepared statements.
 */
final class Duplicator
{
    private const DROP = ['id', 'created_at', 'updated_at'];

    /**
     * Вставляет копию строки, отбрасывая PK/таймстемпы и применяя переопределения.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     * @param array<int, string> $drop
     */
    public static function copyRow(string $table, array $row, array $overrides = [], array $drop = self::DROP): int
    {
        foreach ($drop as $d) {
            unset($row[$d]);
        }
        $row = array_merge($row, $overrides);

        $cols = array_keys($row);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $placeholders) . ')';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($row as $col => $value) {
            $stmt->bindValue(':' . $col, $value);
        }
        $stmt->execute();

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Копирует все дочерние строки, перевешивая их внешний ключ на нового родителя.
     *
     * @param array<int, string> $drop
     */
    public static function copyChildren(string $table, string $fkColumn, int $oldParentId, int $newParentId, array $drop = self::DROP): void
    {
        $sel = Database::pdo()->prepare('SELECT * FROM `' . $table . '` WHERE `' . $fkColumn . '` = :p');
        $sel->execute([':p' => $oldParentId]);

        foreach ($sel->fetchAll() as $child) {
            self::copyRow($table, $child, [$fkColumn => $newParentId], $drop);
        }
    }

    /** Генерирует уникальный slug вида "<slug>-copy", "<slug>-copy-2" и т.д. */
    public static function uniqueCopySlug(string $slug, callable $exists): string
    {
        $base = $slug . '-copy';
        if (!$exists($base)) {
            return $base;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . '-' . $i;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(3));
    }
}
