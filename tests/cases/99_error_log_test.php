<?php

declare(strict_types=1);

use App\Models\ErrorLog;

test('ErrorLog::explain переводит типовые ошибки на понятный язык', function (): void {
    assert_contains('подключиться к базе данных', ErrorLog::explain("SQLSTATE[HY000] [2002] Connection refused"));
    assert_contains('не применены миграции', ErrorLog::explain("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'is_featured'"));
    assert_contains('не хватило памяти', ErrorLog::explain('Allowed memory size of 134217728 bytes exhausted'));
    assert_contains('внешнему сервису', ErrorLog::explain('cURL error 28: Operation timed out after 15001 milliseconds'));
    assert_contains('несуществующей функции', ErrorLog::explain('Call to undefined method App\\Models\\News::foo()'));
    assert_contains('неожиданного типа', ErrorLog::explain('TypeError: strlen(): Argument #1 ($string) must be of type string, array given'));
    assert_contains('Внутренняя ошибка приложения', ErrorLog::explain('Something completely unexpected'));
});

test('ErrorLog: запись, поиск, срок хранения 7 дней и ручная очистка (БД)', function (): void {
    ensure_test_db();

    ErrorLog::clear();
    ErrorLog::record('ERROR', "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'", '/app/Models/Test.php', 42);

    $result = ErrorLog::search();
    assert_same(1, $result['total']);
    $row = $result['items'][0];
    assert_contains('не применены миграции', (string) $row['human']);
    assert_same('/app/Models/Test.php', (string) $row['file']);
    assert_same(42, (int) $row['line']);
    assert_same('ERROR', (string) $row['level']);

    // Фильтр по уровню.
    assert_same(0, ErrorLog::search(['level' => 'CRITICAL'])['total']);
    assert_same(1, ErrorLog::search(['q' => '42S22'])['total']);

    // Запись старше 7 дней удаляется авточисткой.
    \App\Core\Database::pdo()->exec(
        "INSERT INTO error_log (level, human, message, created_at) VALUES ('ERROR', 'старое', 'old', DATE_SUB(NOW(), INTERVAL 8 DAY))"
    );
    assert_same(2, ErrorLog::search()['total']);
    assert_true(ErrorLog::purgeExpired() >= 1);
    assert_same(1, ErrorLog::search()['total']);

    // Ручная очистка удаляет всё.
    assert_true(ErrorLog::clear() >= 1);
    assert_same(0, ErrorLog::search()['total']);
});

test('ErrorLog::record не бросает исключений и режет длинные значения', function (): void {
    ensure_test_db();
    ErrorLog::clear();

    ErrorLog::record('critical', str_repeat('x', 20000), str_repeat('f', 1000), -5);
    $row = ErrorLog::search()['items'][0];
    assert_same('CRITICAL', (string) $row['level']);
    assert_same(10000, mb_strlen((string) $row['message']));
    assert_same(500, mb_strlen((string) $row['file']));
    assert_same(0, (int) $row['line']);

    ErrorLog::clear();
});
