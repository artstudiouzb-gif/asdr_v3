<?php

declare(strict_types=1);

use App\Models\TeamMember;

/*
 * Необязательные поля сотрудника. В схеме `team_members` NULL-able всё, кроме
 * имени и статуса, но модель читала ключи массива напрямую — вызов без фото,
 * почты и телефона печатал «Undefined array key». В тестах это шум, а в
 * рабочем режиме ErrorHandler превращает предупреждение в исключение: форма,
 * где поле просто не заполнили, отдала бы 500.
 */

test('Сотрудник заводится и правится без необязательных полей, без предупреждений (БД)', function () {
    ensure_test_db();

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_WARNING | E_NOTICE);

    $id = 0;
    try {
        $id = TeamMember::create(['name' => 'Без контактов ' . uniqid(), 'status' => 'draft']);
        TeamMember::update($id, ['name' => 'Всё ещё без контактов', 'status' => 'published']);
    } finally {
        restore_error_handler();
    }

    assert_same([], $warnings, 'необязательные поля не должны требовать ключа: ' . implode('; ', $warnings));

    $row = TeamMember::findById($id);
    assert_true(is_array($row), 'запись создана');
    foreach (['position', 'department', 'unit', 'photo', 'email', 'phone'] as $field) {
        // Именно array_key_exists: `?? ` сработал бы на самом NULL и проверка
        // стала бы бессмысленной.
        assert_true(array_key_exists($field, $row), "колонка {$field} есть в выборке");
        assert_same(null, $row[$field], "пустое {$field} сохраняется как NULL");
    }
    assert_same('published', (string) $row['status'], 'правка дошла до базы');

    \App\Core\Database::pdo()->prepare('DELETE FROM team_members WHERE id = :id')->execute([':id' => $id]);
});
