<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class DatabaseDoctor
{
    /**
     * @return array{
     *     database: string,
     *     version: string,
     *     errors_count: int,
     *     warnings_count: int,
     *     is_healthy: bool,
     *     checks: list<array{type: 'ok'|'warning'|'error', message: string}>
     * }
     */
    public static function diagnose(PDO $pdo, string $schemaSqlPath, string $migrationsDir): array
    {
        $checks = [];
        $errors = 0;
        $warnings = 0;

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $databaseName = 'unknown';
        $serverVersion = 'unknown';
        try {
            $dbStmt = $pdo->query('SELECT DATABASE()');
            $databaseName = (string) ($dbStmt ? $dbStmt->fetchColumn() : '');
            $dbStmt?->closeCursor();
        } catch (\Throwable) {
            $databaseName = $driver;
        }
        try {
            $verStmt = $pdo->query('SELECT VERSION()');
            $serverVersion = (string) ($verStmt ? $verStmt->fetchColumn() : '');
            $verStmt?->closeCursor();
        } catch (\Throwable) {
            $serverVersion = $driver;
        }

        if (!is_file($schemaSqlPath)) {
            return [
                'database' => $databaseName,
                'version' => $serverVersion,
                'errors_count' => 1,
                'warnings_count' => 0,
                'is_healthy' => false,
                'checks' => [
                    ['type' => 'error', 'message' => "Файл схемы {$schemaSqlPath} не найден."],
                ],
            ];
        }

        if ($driver !== 'mysql') {
            return [
                'database' => $databaseName,
                'version' => $serverVersion,
                'errors_count' => 0,
                'warnings_count' => 0,
                'is_healthy' => true,
                'checks' => [
                    ['type' => 'ok', 'message' => "Диагностика поддерживается для MySQL/MariaDB (текущий драйвер: {$driver})."],
                ],
            ];
        }

        $schemaSql = (string) file_get_contents($schemaSqlPath);
        $expectedTables = [];
        $expectedColumns = [];
        $expectedIndexes = [];
        $expectedForeignKeys = [];

        preg_match_all(
            '/CREATE TABLE IF NOT EXISTS\s+`?([a-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE=/si',
            $schemaSql,
            $tableMatches,
            PREG_SET_ORDER
        );

        foreach ($tableMatches as $tableMatch) {
            $table = $tableMatch[1];
            $body = $tableMatch[2];
            $expectedTables[] = $table;
            $expectedColumns[$table] = [];
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim($line);
                if (preg_match('/^`?([a-z][a-z0-9_]*)`?\s+/', $line, $columnMatch)) {
                    $keyword = strtoupper($columnMatch[1]);
                    if (!in_array($keyword, ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT'], true)) {
                        $expectedColumns[$table][] = $columnMatch[1];
                    }
                }
            }
            preg_match_all('/(?:UNIQUE\s+)?KEY\s+`?([a-z0-9_]+)`?\s*\(/i', $body, $indexMatches);
            $expectedIndexes[$table] = $indexMatches[1];

            preg_match_all(
                '/CONSTRAINT\s+`?([a-z0-9_]+)`?\s+FOREIGN KEY\s*\(\s*`?([a-z0-9_]+)`?\s*\)\s+REFERENCES\s+`?([a-z0-9_]+)`?\s*\(\s*`?([a-z0-9_]+)`?\s*\)(?:\s+ON DELETE\s+(CASCADE|SET NULL|RESTRICT|NO ACTION))?/i',
                $body,
                $foreignKeyMatches,
                PREG_SET_ORDER
            );
            foreach ($foreignKeyMatches as $foreignKeyMatch) {
                $expectedForeignKeys[$foreignKeyMatch[1]] = [
                    'table' => $table,
                    'column' => $foreignKeyMatch[2],
                    'referenced_table' => $foreignKeyMatch[3],
                    'referenced_column' => $foreignKeyMatch[4],
                    'delete_rule' => strtoupper($foreignKeyMatch[5] ?? 'RESTRICT'),
                ];
            }
        }
        $expectedTables = array_values(array_unique($expectedTables));

        $tableRows = $pdo->prepare(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE'"
        );
        $tableRows->execute([':schema' => $databaseName]);
        $actualTableMeta = [];
        foreach ($tableRows->fetchAll() as $row) {
            $actualTableMeta[(string) $row['TABLE_NAME']] = $row;
        }

        $missingTables = array_values(array_diff($expectedTables, array_keys($actualTableMeta)));
        if ($missingTables === []) {
            $checks[] = ['type' => 'ok', 'message' => count($expectedTables) . ' таблиц из schema.sql присутствуют в базе данных'];
        } else {
            $errors++;
            $checks[] = ['type' => 'error', 'message' => 'Отсутствуют таблицы: ' . implode(', ', $missingTables)];
        }

        $wrongEngines = [];
        $wrongCollations = [];
        foreach ($expectedTables as $table) {
            if (!isset($actualTableMeta[$table])) {
                continue;
            }
            if (strcasecmp((string) $actualTableMeta[$table]['ENGINE'], 'InnoDB') !== 0) {
                $wrongEngines[] = $table . '=' . (string) $actualTableMeta[$table]['ENGINE'];
            }
            if (!str_starts_with(strtolower((string) $actualTableMeta[$table]['TABLE_COLLATION']), 'utf8mb4_')) {
                $wrongCollations[] = $table . '=' . (string) $actualTableMeta[$table]['TABLE_COLLATION'];
            }
        }

        if ($wrongEngines === []) {
            $checks[] = ['type' => 'ok', 'message' => 'Все штатные таблицы используют движок InnoDB'];
        } else {
            $errors++;
            $checks[] = ['type' => 'error', 'message' => 'Неверный движок (требуется InnoDB): ' . implode(', ', $wrongEngines)];
        }

        if ($wrongCollations === []) {
            $checks[] = ['type' => 'ok', 'message' => 'Все штатные таблицы используют кодировку utf8mb4'];
        } else {
            $warnings++;
            $checks[] = ['type' => 'warning', 'message' => 'Нестандартная кодировка/сопоставление: ' . implode(', ', $wrongCollations)];
        }

        $columnRows = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema'
        );
        $columnRows->execute([':schema' => $databaseName]);
        $actualColumns = [];
        foreach ($columnRows->fetchAll() as $row) {
            $actualColumns[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        $missingColumnMessages = [];
        foreach ($expectedColumns as $table => $columns) {
            if (!isset($actualTableMeta[$table])) {
                continue;
            }
            $missing = array_values(array_diff($columns, $actualColumns[$table] ?? []));
            if ($missing !== []) {
                $missingColumnMessages[] = $table . ': ' . implode(', ', $missing);
            }
        }

        if ($missingColumnMessages === []) {
            $checks[] = ['type' => 'ok', 'message' => 'Структура колонок таблиц полностью соответствует schema.sql'];
        } else {
            $errors++;
            $checks[] = ['type' => 'error', 'message' => 'Отсутствуют обязательные колонки: ' . implode('; ', $missingColumnMessages)];
        }

        $indexRows = $pdo->prepare(
            'SELECT DISTINCT TABLE_NAME, INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :schema'
        );
        $indexRows->execute([':schema' => $databaseName]);
        $actualIndexes = [];
        foreach ($indexRows->fetchAll() as $row) {
            $actualIndexes[(string) $row['TABLE_NAME']][] = (string) $row['INDEX_NAME'];
        }

        $missingIndexMessages = [];
        foreach ($expectedIndexes as $table => $indexes) {
            if (!isset($actualTableMeta[$table])) {
                continue;
            }
            $missing = array_values(array_diff($indexes, $actualIndexes[$table] ?? []));
            if ($missing !== []) {
                $missingIndexMessages[] = $table . ': ' . implode(', ', $missing);
            }
        }

        if ($missingIndexMessages === []) {
            $checks[] = ['type' => 'ok', 'message' => 'Именованные индексы соответствуют schema.sql'];
        } else {
            $warnings++;
            $checks[] = ['type' => 'warning', 'message' => 'Отсутствуют индексы: ' . implode('; ', $missingIndexMessages)];
        }

        $foreignKeyRows = $pdo->prepare(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.CONSTRAINT_SCHEMA = :schema
               AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $foreignKeyRows->execute([':schema' => $databaseName]);
        $actualForeignKeys = [];
        foreach ($foreignKeyRows->fetchAll() as $row) {
            $actualForeignKeys[(string) $row['CONSTRAINT_NAME']] = [
                'table' => (string) $row['TABLE_NAME'],
                'column' => (string) $row['COLUMN_NAME'],
                'referenced_table' => (string) $row['REFERENCED_TABLE_NAME'],
                'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
                'delete_rule' => strtoupper((string) $row['DELETE_RULE']),
            ];
        }

        $badForeignKeys = [];
        foreach ($expectedForeignKeys as $name => $definition) {
            if (!isset($actualForeignKeys[$name])) {
                $badForeignKeys[] = $name . ' отсутствует';
                continue;
            }
            if ($actualForeignKeys[$name] !== $definition) {
                $badForeignKeys[] = $name . ' отличается от schema.sql';
            }
        }

        if ($badForeignKeys === []) {
            $checks[] = ['type' => 'ok', 'message' => count($expectedForeignKeys) . ' внешних ключей соответствуют эталонной схеме'];
        } else {
            $errors++;
            $checks[] = ['type' => 'error', 'message' => 'Проблемы внешних ключей: ' . implode('; ', $badForeignKeys)];
        }

        if (isset($actualTableMeta['migrations']) && in_array('filename', $actualColumns['migrations'] ?? [], true)) {
            $appliedMigrations = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $migrationFiles = array_map('basename', glob(rtrim($migrationsDir, '/') . '/*.sql') ?: []);
            sort($migrationFiles, SORT_STRING);
            $pendingMigrations = array_values(array_diff($migrationFiles, $appliedMigrations));
            $unknownMigrations = array_values(array_diff($appliedMigrations, $migrationFiles));

            if ($pendingMigrations === []) {
                $checks[] = ['type' => 'ok', 'message' => 'Все файлы миграций (' . count($migrationFiles) . ') применены'];
            } else {
                $errors++;
                $checks[] = ['type' => 'error', 'message' => 'Не применены миграции (' . count($pendingMigrations) . '): ' . implode(', ', $pendingMigrations)];
            }

            if ($unknownMigrations !== []) {
                $warnings++;
                $checks[] = ['type' => 'warning', 'message' => 'В БД отмечены отсутствующие в проекте миграции: ' . implode(', ', $unknownMigrations)];
            }
        }

        $fkStmt = $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS');
        $foreignKeyChecks = (int) ($fkStmt ? $fkStmt->fetchColumn() : 0);
        $fkStmt?->closeCursor();

        if ($foreignKeyChecks === 1) {
            $checks[] = ['type' => 'ok', 'message' => 'Режим проверки внешних ключей (FOREIGN_KEY_CHECKS) активен'];
        } else {
            $errors++;
            $checks[] = ['type' => 'error', 'message' => 'FOREIGN_KEY_CHECKS выключен для соединения с БД'];
        }

        $count = static function (PDO $pdo, string $sql): int {
            try {
                $cStmt = $pdo->query($sql);
                $val = (int) ($cStmt ? $cStmt->fetchColumn() : 0);
                $cStmt?->closeCursor();
                return $val;
            } catch (\Throwable) {
                return 0;
            }
        };

        if (
            isset($actualTableMeta['languages'])
            && in_array('is_default', $actualColumns['languages'] ?? [], true)
            && in_array('is_active', $actualColumns['languages'], true)
        ) {
            $defaultLanguages = $count($pdo, 'SELECT COUNT(*) FROM languages WHERE is_default = 1');
            $activeDefaultLanguages = $count($pdo, 'SELECT COUNT(*) FROM languages WHERE is_default = 1 AND is_active = 1');
            if ($defaultLanguages === 1 && $activeDefaultLanguages === 1) {
                $checks[] = ['type' => 'ok', 'message' => 'Настроен ровно один активный системный язык по умолчанию'];
            } else {
                $errors++;
                $checks[] = ['type' => 'error', 'message' => "Целостность языков: default={$defaultLanguages}, active default={$activeDefaultLanguages} (должно быть 1/1)"];
            }
        }

        foreach (['news', 'pages', 'projects'] as $table) {
            if (!isset($actualTableMeta[$table]) || !in_array('translation_group_id', $actualColumns[$table] ?? [], true)) {
                continue;
            }
            $brokenGroups = $count(
                $pdo,
                "SELECT COUNT(*)
                 FROM `{$table}` child_row
                 LEFT JOIN `{$table}` root_row ON root_row.id = child_row.translation_group_id
                 WHERE child_row.translation_group_id IS NULL
                    OR child_row.translation_group_id = 0
                    OR root_row.id IS NULL"
            );
            if ($brokenGroups === 0) {
                $checks[] = ['type' => 'ok', 'message' => "{$table}: группы переводов согласованы"];
            } else {
                $errors++;
                $checks[] = ['type' => 'error', 'message' => "{$table}: обнаружено повреждённых связей переводов — {$brokenGroups}"];
            }
        }

        if (isset($actualTableMeta['pages']) && in_array('parent_id', $actualColumns['pages'] ?? [], true)) {
            $selfParents = $count($pdo, 'SELECT COUNT(*) FROM pages WHERE parent_id = id');
            if ($selfParents === 0) {
                $checks[] = ['type' => 'ok', 'message' => 'pages: нет циклических самоссылок parent_id'];
            } else {
                $errors++;
                $checks[] = ['type' => 'error', 'message' => "pages: самоссылок parent_id — {$selfParents}"];
            }
        }

        return [
            'database' => $databaseName,
            'version' => $serverVersion,
            'errors_count' => $errors,
            'warnings_count' => $warnings,
            'is_healthy' => $errors === 0,
            'checks' => $checks,
        ];
    }
}
