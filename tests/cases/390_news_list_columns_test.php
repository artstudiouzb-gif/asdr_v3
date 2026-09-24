<?php

declare(strict_types=1);

use App\Models\News;
use App\Models\NewsTranslation;

/**
 * Списки новостей выбирают колонки явно, без тела новости. Перечень ведётся
 * руками, поэтому сверяется со схемой: колонка, добавленная миграцией (а
 * schema.sql обязан с ней совпадать), должна попасть либо в перечень, либо в
 * исключения — иначе карточки молча остались бы без неё.
 *
 * @return list<string>
 */
function news_schema_columns(string $table): array
{
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $pattern = '/CREATE TABLE IF NOT EXISTS `?' . preg_quote($table, '/') . '`?\s*\((.*?)\n\)/s';
    assert_true(preg_match($pattern, $schema, $m) === 1, "таблица {$table} есть в schema.sql");

    $columns = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^\s+`?([a-z_]+)`?\s+[A-Z]/', $line, $c) === 1
            && !in_array($c[1], ['PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'FULLTEXT'], true)) {
            $columns[] = $c[1];
        }
    }

    return $columns;
}

test('Колонки списка новостей — это вся таблица news без тела новости', function (): void {
    $schema = news_schema_columns('news');
    $declared = [...News::LIST_COLUMNS, ...News::LIST_EXCLUDED_COLUMNS];
    sort($schema);
    sort($declared);

    assert_same($schema, $declared, 'каждая колонка news либо в списке, либо в исключениях');
    assert_same(array_values(array_unique(News::LIST_COLUMNS)), News::LIST_COLUMNS, 'без повторов');
    assert_true(in_array('content', News::LIST_EXCLUDED_COLUMNS, true));
    assert_false(in_array('content', News::LIST_COLUMNS, true), 'тело новости спискам не нужно');
});

test('Колонки перевода для списков — вся news_translations без тела новости', function (): void {
    $schema = news_schema_columns('news_translations');
    $declared = [...NewsTranslation::LIST_COLUMNS, ...NewsTranslation::LIST_EXCLUDED_COLUMNS];
    sort($schema);
    sort($declared);

    assert_same($schema, $declared, 'каждая колонка news_translations либо в списке, либо в исключениях');
    assert_false(in_array('content', NewsTranslation::LIST_COLUMNS, true));
});

test('Лента и «Похожие новости» не выбирают n.*', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    foreach (['published', 'related'] as $method) {
        $start = strpos($source, 'public static function ' . $method . '(');
        assert_true($start !== false, $method . '() есть');
        $end = strpos($source, "\n    }\n", (int) $start);
        $body = substr($source, (int) $start, (int) $end - (int) $start);
        assert_not_contains('SELECT n.*', $body, $method . '(): колонки выбираются явно');
        assert_contains("self::listColumns('n')", $body, $method . '(): перечень из LIST_COLUMNS');
    }

    $translations = (string) file_get_contents(APP_ROOT . '/app/Models/NewsTranslation.php');
    assert_not_contains('SELECT * FROM news_translations WHERE news_id IN', $translations);
});
