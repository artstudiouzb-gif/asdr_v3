<?php

declare(strict_types=1);

use App\Core\PasswordPolicy;

test('PasswordPolicy: короткий пароль отклоняется', function () {
    assert_false(PasswordPolicy::isValid('Ab1!'));
});

test('PasswordPolicy: односимвольный класс отклоняется', function () {
    // Только строчные буквы — одна группа.
    assert_false(PasswordPolicy::isValid('abcdefghijkl'));
});

test('PasswordPolicy: популярный пароль из словаря отклоняется', function () {
    assert_true(PasswordPolicy::isCompromised('password'));
    assert_false(PasswordPolicy::isValid('password'));
});

test('PasswordPolicy: пароль с логином внутри отклоняется', function () {
    assert_false(PasswordPolicy::isValid('artstudio-2026!', ['artstudio', 'a@b.com']));
});

test('PasswordPolicy: сильный уникальный пароль принимается', function () {
    // Достаточно длинный, разные классы, не в словаре, без личных данных.
    assert_true(PasswordPolicy::isValid('Qz7#kLm93pRt!v'));
});
