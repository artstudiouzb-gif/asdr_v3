<?php

declare(strict_types=1);

use App\Controllers\InstallController;

// Подсказки установщика к типичным ошибкам MySQL на шаге 2 (shared-хостинг).

test('Установщик: подсказка при 1044 (нет прав на базу)', function () {
    $hint = InstallController::dbErrorHint(
        "SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user 'u'@'localhost' to database 'u'"
    );
    assert_true(str_contains($hint, 'назначьте пользователя на базу'), 'совет назначить пользователя');
    assert_true(str_contains($hint, 'cPanel'), 'упомянута панель');
});

test('Установщик: подсказка при 1045 (неверный пароль)', function () {
    $hint = InstallController::dbErrorHint('SQLSTATE[HY000] [1045] Access denied for user (using password: YES)');
    assert_true(str_contains($hint, 'логин или пароль'), 'совет проверить учётные данные');
});

test('Установщик: подсказка при 2002 (сервер недоступен)', function () {
    $hint = InstallController::dbErrorHint('SQLSTATE[HY000] [2002] Connection refused');
    assert_true(str_contains($hint, 'localhost'), 'совет про хост localhost');
});

test('Установщик: без подсказки для незнакомых ошибок', function () {
    assert_same('', InstallController::dbErrorHint('SQLSTATE[42S02]: Base table not found'));
});
