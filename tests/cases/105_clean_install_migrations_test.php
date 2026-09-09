<?php

declare(strict_types=1);

use App\Core\Database;

$connectTestDb = static function (): \PDO {
    $db = (string) (getenv('TEST_DB_DATABASE') ?: '');
    if ($db === '') {
        skip_test('TEST_DB_* не заданы');
    }

    if (!Database::isConnected()) {
        Database::init([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => $db,
            'username' => getenv('TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
    }

    return Database::pdo();
};

test('Чистая установка доводит Hero-переводы до актуальной схемы', function () use ($connectTestDb): void {
    $pdo = $connectTestDb();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );
    $stmt->execute([':schema' => $database, ':table' => 'hero_slide_translations']);
    $columns = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

    foreach (['cta_url', 'cta2_url', 'link_url'] as $column) {
        assert_true(
            in_array($column, $columns, true),
            "после schema.sql + pending migrations отсутствует hero_slide_translations.{$column}"
        );
    }

    $applied = array_map(
        'strval',
        $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN) ?: []
    );
    foreach ([
        '2026_08_17_hero_translation_links.sql',
        '2026_08_18_repair_hero_translation_links.sql',
    ] as $migration) {
        assert_true(in_array($migration, $applied, true), "не отмечена миграция {$migration}");
    }
});

test('Демо Hero использует только существующие колонки переводов', function () use ($connectTestDb): void {
    $pdo = $connectTestDb();
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');

    assert_contains('seedHeroes($pdo, $c)', $source, 'демо-комплект должен включать Hero');
    assert_true(
        (bool) preg_match(
            '/INSERT INTO hero_slide_translations\s*\(([^)]+)\)/s',
            $source,
            $match
        ),
        'не найден INSERT демо-переводов Hero'
    );

    $insertColumns = array_values(array_filter(array_map(
        static fn (string $column): string => trim($column, " \t\r\n`"),
        explode(',', (string) ($match[1] ?? ''))
    )));

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );
    $stmt->execute([':schema' => $database, ':table' => 'hero_slide_translations']);
    $actualColumns = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

    foreach ($insertColumns as $column) {
        assert_true(
            in_array($column, $actualColumns, true),
            "DemoSeeder пишет в отсутствующую колонку hero_slide_translations.{$column}"
        );
    }
});

test('Эталонный демо-комплект реально разворачивается на чистой актуальной БД', function (): void {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }

    $output = [];
    $exitCode = 0;
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(APP_ROOT . '/tests/demo_seed_smoke.php')
        . ' 2>&1';
    exec($command, $output, $exitCode);

    $text = implode("\n", $output);
    assert_same(0, $exitCode, 'demo seed smoke failed: ' . $text);
    assert_contains('Demo seed smoke OK', $text, 'demo seed smoke did not finish');
});

test('Веб-установка и CI используют единый MigrationRunner', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $loader = (string) file_get_contents(APP_ROOT . '/tests/load_schema.php');
    $cli = (string) file_get_contents(APP_ROOT . '/database/migrate.php');

    assert_contains('MigrationRunner::applyPending', $bootstrap, 'установщик должен применять pending migrations');
    assert_contains('MigrationRunner::applyPending', $loader, 'fresh-schema CI должен применять pending migrations');
    assert_contains('MigrationRunner::applyPending', $cli, 'CLI должен использовать тот же runner');
});
