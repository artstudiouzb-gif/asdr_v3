<?php

declare(strict_types=1);

use App\Core\ProcessLock;

test('ProcessLock: второй захват той же блокировки отклоняется, после release — снова доступен (группа 6)', function () {
    $name = 'testlock_' . bin2hex(random_bytes(3));

    $a = ProcessLock::acquire($name);
    assert_true(is_resource($a), 'первый захват успешен');

    // Пока держим первую блокировку — вторая не берётся (LOCK_NB).
    $b = ProcessLock::acquire($name);
    assert_same(null, $b, 'повторный захват занятой блокировки отклонён');

    ProcessLock::release($a);

    // После освобождения — снова доступна.
    $c = ProcessLock::acquire($name);
    assert_true(is_resource($c), 'после release блокировка снова берётся');
    ProcessLock::release($c);

    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    @unlink($root . '/storage/cache/' . $name . '.lock');
});
