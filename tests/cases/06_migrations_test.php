<?php

declare(strict_types=1);

use App\Core\Database;

// Файловые проверки (без БД): каждая миграция непуста и содержит SQL-DDL/DML.
test('Миграции: все файлы непусты и содержат SQL', function () {
    $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
    assert_true($files !== [], 'нет ни одного файла миграции');
    foreach ($files as $f) {
        $sql = (string) file_get_contents($f);
        assert_true(trim($sql) !== '', basename($f) . ' пуст');
        assert_true(
            (bool) preg_match('/\b(CREATE|ALTER|INSERT|UPDATE|DROP)\b/i', $sql),
            basename($f) . ' не содержит SQL-операторов'
        );
    }
});

test('Миграции: schema.sql перечисляет базовые миграции, post-schema остаются отдельными', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    preg_match_all("/\\('([^']+\\.sql)'\\)/", $schema, $matches);
    $registered = array_count_values($matches[1] ?? []);

    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) {
        $name = basename($f);
        $sql = (string) file_get_contents($f);
        $isPostSchema = str_contains($sql, '-- @post-schema');

        if ($isPostSchema) {
            assert_same(0, $registered[$name] ?? 0, "post-schema миграция {$name} не должна помечаться до выполнения");
            continue;
        }

        assert_same(1, $registered[$name] ?? 0, "schema.sql должен отмечать {$name} ровно один раз");
    }
});

// БД-проверка (гейт по окружению): применяем schema.sql к чистой тестовой базе,
// затем выполняем явно помеченные post-schema миграции — тот же порядок,
// который используется при развёртывании релиза через database/migrate.php.
test('Миграции: применение schema.sql и post-schema обновлений (нужна тестовая БД)', function () {
    $db = getenv('TEST_DB_DATABASE');
    if ($db === false || $db === '') {
        skip_test('TEST_DB_* не заданы');
    }

    Database::init([
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TEST_DB_PORT') ?: '3306',
        'database' => $db,
        'username' => getenv('TEST_DB_USERNAME') ?: 'root',
        'password' => getenv('TEST_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ]);

    $pdo = Database::pdo();
    // Все таблицы полной schema.sql должны существовать.
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS\\s+`?([a-z0-9_]+)`?/i', $schema, $tableMatches);
    foreach (array_unique($tableMatches[1] ?? []) as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        assert_true($stmt->fetchColumn() !== false, "таблица {$table} отсутствует");
    }

    $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
    $applied = array_flip($applied);
    $record = $pdo->prepare(
        'INSERT INTO migrations (filename, applied_at) VALUES (:filename, NOW())
         ON DUPLICATE KEY UPDATE filename = VALUES(filename)'
    );

    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) {
        $name = basename($f);
        if (isset($applied[$name])) {
            continue;
        }

        $sql = (string) file_get_contents($f);
        assert_true(
            str_contains($sql, '-- @post-schema'),
            "неучтённая миграция {$name} должна быть включена в schema.sql или явно помечена @post-schema"
        );
        $pdo->exec($sql);
        $record->execute([':filename' => $name]);
        $applied[$name] = true;
    }

    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) {
        assert_true(isset($applied[basename($f)]), basename($f) . ' не отмечена применённой');
    }
});
