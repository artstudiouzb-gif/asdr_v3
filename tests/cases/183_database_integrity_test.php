<?php

declare(strict_types=1);

test('DB Doctor защищён CLI guard и проверяет схему, миграции и данные', function () {
    $doctor = (string) file_get_contents(APP_ROOT . '/database/doctor.php');

    assert_contains('Cli::assertCli()', $doctor);
    assert_contains('information_schema.TABLES', $doctor);
    assert_contains('information_schema.COLUMNS', $doctor);
    assert_contains('information_schema.STATISTICS', $doctor);
    assert_contains('information_schema.KEY_COLUMN_USAGE', $doctor);
    assert_contains('SELECT filename FROM migrations', $doctor);
    assert_contains('translation_group_id', $doctor);
    assert_contains('FOREIGN_KEY_CHECKS', $doctor);
});

test('Схема и миграция защищают очередь Web Push и авторов ревизий', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $migration = (string) file_get_contents(
        APP_ROOT . '/database/migrations/2026_07_30_database_integrity.sql'
    );

    foreach ([$schema, $migration] as $sql) {
        assert_contains('fk_webpush_queue_news', $sql);
        assert_contains('fk_block_revisions_user', $sql);
        assert_contains('ON DELETE CASCADE', $sql);
        assert_contains('ON DELETE SET NULL', $sql);
    }
});

/**
 * Колонки таблицы по schema.sql.
 *
 * @return list<string>
 */
function schema_columns(string $table): array
{
    static $schema = null;
    if ($schema === null) {
        $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    }

    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?' . preg_quote($table, '/') . '`?\s*\((.*?)\n\)\s*ENGINE/s', $schema, $m) !== 1) {
        return [];
    }

    $columns = [];
    foreach (explode("\n", $m[1]) as $line) {
        // Строки индексов и ограничений колонками не являются.
        if (preg_match('/^\s+(?!(?:PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN)\b)`?(\w+)`?\s+\S/i', $line, $col) === 1) {
            $columns[] = $col[1];
        }
    }

    return $columns;
}

test('Языковые батч-запросы используют реальные колонки базовых таблиц', function () {
    // Проверка была строковой: три модели сверялись с ожидаемым текстом
    // запроса. Она ловила настоящую ошибку — в запрос попадали колонки `lang`
    // и `deleted_at`, которых у базовых таблиц нет, — но покрывала ровно те
    // три строки, что в ней перечислены. Теперь запрос собирает один метод
    // (`Translations::availableLangs`), и проверяется само условие: каждая
    // названная колонка существует и в базовой таблице, и в таблице переводов.
    // Новый вызывающий попадает под проверку сам, без правки теста.
    $callers = [];
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app'));
    foreach ($dir as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match_all(
            "/Translations::availableLangs\(\s*'(\w+)'\s*,\s*\\$\w+\s*,\s*\[([^\]]*)\]/",
            $src,
            $found,
            PREG_SET_ORDER
        ) === 0) {
            continue;
        }
        foreach ($found as $call) {
            preg_match_all("/'(\w+)'/", $call[2], $cols);
            $callers[] = [basename($file->getPathname()), $call[1], $cols[1]];
        }
    }

    assert_true(count($callers) >= 3, 'вызовы availableLangs не найдены (' . count($callers) . ')');

    foreach ($callers as [$file, $table, $columns]) {
        $baseColumns = schema_columns($table);
        assert_true($baseColumns !== [], $file . ': таблицы ' . $table . ' нет в schema.sql');

        // Первый столбец уходит в SELECT по базовой таблице — там он обязан
        // быть; остальные проверяются в таблице переводов.
        assert_true(
            in_array($columns[0], $baseColumns, true),
            $file . ': у ' . $table . ' нет колонки ' . $columns[0]
        );

        $translationTable = match ($table) {
            'photo_albums' => 'photo_album_translations',
            'videos' => 'video_translations',
            'team_members' => 'team_member_translations',
            default => null,
        };
        assert_true($translationTable !== null, $file . ': не описана таблица переводов для ' . $table);

        $translationColumns = schema_columns((string) $translationTable);
        assert_true($translationColumns !== [], $translationTable . ' отсутствует в schema.sql');
        foreach ($columns as $column) {
            assert_true(
                in_array($column, $translationColumns, true),
                $file . ': у ' . $translationTable . ' нет колонки ' . $column
            );
        }
    }
});

test('Общий разбор языков не спрашивает колонок, которых у базовых таблиц нет', function () {
    // Прежняя ошибка была именно такой: в SELECT попадали `lang` и
    // `deleted_at`. У базовых таблиц механизма А их нет, и запрос падал.
    $core = (string) file_get_contents(APP_ROOT . '/app/Core/Translations.php');
    $start = strpos($core, 'public static function availableLangs(');
    assert_true($start !== false, 'метод availableLangs не найден');
    $body = substr($core, (int) $start);

    assert_not_contains('SELECT id, lang,', $body);
    // Мягкого удаления у сущностей механизма А нет: колонки deleted_at у их
    // базовых таблиц не существует, и запрос с ней падал.
    assert_not_contains('deleted_at', $body);
    assert_contains('SELECT id, {$baseColumn} FROM {$table}', $body);
});
