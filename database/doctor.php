<?php

declare(strict_types=1);

/*
 * Read-only диагностика установленной базы ASDR CMS.
 *
 *   php database/doctor.php
 *
 * Сверяет реальную БД с database/schema.sql, проверяет миграции, движок,
 * кодировку, внешние ключи и несколько важных инвариантов данных.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Config;
use App\Core\Database;

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "ОШИБКА: config/config.php не найден.\n");
    exit(2);
}

$config = require $configFile;
Config::set($config);

try {
    Database::init($config['db']);
    $pdo = Database::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, 'ОШИБКА: не удалось подключиться к БД: ' . $e->getMessage() . "\n");
    exit(2);
}

$errors = 0;
$warnings = 0;
$ok = static function (string $message): void {
    fwrite(STDOUT, "[OK] {$message}\n");
};
$error = static function (string $message) use (&$errors): void {
    $errors++;
    fwrite(STDOUT, "[ОШИБКА] {$message}\n");
};
$warn = static function (string $message) use (&$warnings): void {
    $warnings++;
    fwrite(STDOUT, "[ВНИМАНИЕ] {$message}\n");
};

$schemaSql = (string) file_get_contents(__DIR__ . '/schema.sql');
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
    $expectedIndexes[$table] = $indexMatches[1] ?? [];

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

$databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
fwrite(STDOUT, "ASDR DB Doctor — {$databaseName} ({$serverVersion})\n\n");

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
    $ok(count($expectedTables) . ' таблиц из schema.sql присутствуют');
} else {
    $error('Отсутствуют таблицы: ' . implode(', ', $missingTables));
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
$wrongEngines === []
    ? $ok('все штатные таблицы используют InnoDB')
    : $error('Неверный движок: ' . implode(', ', $wrongEngines));
$wrongCollations === []
    ? $ok('все штатные таблицы используют utf8mb4')
    : $warn('Неверная кодировка/collation: ' . implode(', ', $wrongCollations));

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
$missingColumnMessages === []
    ? $ok('структура колонок соответствует schema.sql')
    : $error('Отсутствуют колонки — ' . implode('; ', $missingColumnMessages));

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
$missingIndexMessages === []
    ? $ok('именованные индексы соответствуют schema.sql')
    : $warn('Отсутствуют индексы — ' . implode('; ', $missingIndexMessages));

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
$badForeignKeys === []
    ? $ok(count($expectedForeignKeys) . ' внешних ключей соответствуют schema.sql')
    : $error('Проблемы внешних ключей: ' . implode('; ', $badForeignKeys));

if (
    isset($actualTableMeta['migrations'])
    && in_array('filename', $actualColumns['migrations'] ?? [], true)
) {
    $appliedMigrations = $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
    $migrationFiles = array_map('basename', glob(__DIR__ . '/migrations/*.sql') ?: []);
    sort($migrationFiles, SORT_STRING);
    $pendingMigrations = array_values(array_diff($migrationFiles, $appliedMigrations));
    $unknownMigrations = array_values(array_diff($appliedMigrations, $migrationFiles));
    $pendingMigrations === []
        ? $ok(count($migrationFiles) . ' миграций применены')
        : $error('Не применены миграции: ' . implode(', ', $pendingMigrations));
    if ($unknownMigrations !== []) {
        $warn('В БД отмечены отсутствующие в репозитории миграции: ' . implode(', ', $unknownMigrations));
    }
}

$foreignKeyChecks = (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
$foreignKeyChecks === 1
    ? $ok('FOREIGN_KEY_CHECKS включён для текущего соединения')
    : $error('FOREIGN_KEY_CHECKS выключен для текущего соединения');

$count = static function (\PDO $pdo, string $sql): int {
    return (int) $pdo->query($sql)->fetchColumn();
};

if (
    isset($actualTableMeta['languages'])
    && in_array('is_default', $actualColumns['languages'] ?? [], true)
    && in_array('is_active', $actualColumns['languages'] ?? [], true)
) {
    $defaultLanguages = $count($pdo, 'SELECT COUNT(*) FROM languages WHERE is_default = 1');
    $activeDefaultLanguages = $count($pdo, 'SELECT COUNT(*) FROM languages WHERE is_default = 1 AND is_active = 1');
    if ($defaultLanguages === 1 && $activeDefaultLanguages === 1) {
        $ok('настроен ровно один активный язык по умолчанию');
    } else {
        $error("Языки: default={$defaultLanguages}, active default={$activeDefaultLanguages}; должно быть 1/1");
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
    $brokenGroups === 0
        ? $ok("{$table}: группы переводов целы")
        : $error("{$table}: повреждённых групп переводов — {$brokenGroups}");
}

if (isset($actualTableMeta['pages']) && in_array('parent_id', $actualColumns['pages'] ?? [], true)) {
    $selfParents = $count($pdo, 'SELECT COUNT(*) FROM pages WHERE parent_id = id');
    $selfParents === 0
        ? $ok('pages: нет самоссылок parent_id')
        : $error("pages: самоссылок parent_id — {$selfParents}");
}

if (
    isset($actualTableMeta['webpush_queue'], $actualTableMeta['news'])
    && in_array('news_id', $actualColumns['webpush_queue'] ?? [], true)
) {
    $orphans = $count(
        $pdo,
        'SELECT COUNT(*) FROM webpush_queue q LEFT JOIN news n ON n.id = q.news_id WHERE n.id IS NULL'
    );
    $orphans === 0
        ? $ok('webpush_queue: потерянных записей нет')
        : $error("webpush_queue: потерянных записей — {$orphans}");
}

if (
    isset($actualTableMeta['block_revisions'], $actualTableMeta['users'])
    && in_array('created_by', $actualColumns['block_revisions'] ?? [], true)
) {
    $orphans = $count(
        $pdo,
        'SELECT COUNT(*) FROM block_revisions r LEFT JOIN users u ON u.id = r.created_by
         WHERE r.created_by IS NOT NULL AND u.id IS NULL'
    );
    $orphans === 0
        ? $ok('block_revisions: ссылки на авторов целы')
        : $error("block_revisions: потерянных авторов — {$orphans}");
}

fwrite(STDOUT, "\nИтог: ошибок {$errors}, предупреждений {$warnings}.\n");
exit($errors > 0 ? 1 : 0);
