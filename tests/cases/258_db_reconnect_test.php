<?php

declare(strict_types=1);

use App\Core\Database;

// Регрессия: импорт новостей качает каждую картинку до минуты, а MySQL за это
// время закрывает простаивающее соединение по wait_timeout — следующий запрос
// падал «SQLSTATE[HY000] 2006 MySQL server has gone away», и импорт обрывался
// на середине пачки. Database проверяет соединение после долгой паузы и
// переподключается сам.

test('Database переподключается после долгой паузы, а не падает с 2006', function () {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '' || !Database::isConnected()) {
        skip_test('TEST_DB_* не заданы');
        return;
    }

    $pdo = Database::pdo();
    $before = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    // Сервер закрывает соединение через секунду простоя — так же, как это
    // происходит на хостинге, пока импортёр тянет очередную картинку.
    $pdo->exec('SET SESSION wait_timeout = 1');
    usleep(2600000);

    $after = (int) Database::pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    assert_true($after > 0, 'запрос после паузы выполняется, а не падает с 2006');
    assert_true($after !== $before, 'соединение переподключено, а не взято мёртвым');
});

test('Внутри транзакции соединение не переподключается молча', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Database.php');

    assert_contains('inTransaction()', $source, 'проверка живости пропускается внутри транзакции');
    // Переподключение посреди транзакции потеряло бы незакоммиченные строки без
    // единого сообщения — там честнее упасть.
    $revive = substr($source, (int) strpos($source, 'private static function reviveIfDead'));
    $revive = substr($revive, 0, (int) strpos($revive, 'public static function transaction'));
    assert_not_contains('beginTransaction', $revive);
});
