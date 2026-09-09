<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\FormSubmission;

test('GDPR: deleteOlderThan удаляет старые заявки, свежие сохраняет; 0 = отключено (БД, группа 6)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM form_submissions');
    $pdo->exec('DELETE FROM forms');

    $pdo->exec("INSERT INTO forms (name, slug, fields_json, created_at) VALUES ('Контакты', 'kontakty', '[]', NOW())");
    $formId = (int) $pdo->lastInsertId();

    // Старая заявка (100 дней назад) и свежая (сегодня).
    $pdo->exec("INSERT INTO form_submissions (form_id, data_json, created_at) VALUES ($formId, '{}', NOW() - INTERVAL 100 DAY)");
    $pdo->exec("INSERT INTO form_submissions (form_id, data_json, created_at) VALUES ($formId, '{}', NOW())");

    // days=0 → очистка отключена, ничего не удаляем.
    assert_same(0, FormSubmission::deleteOlderThan(0));
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn());

    // Порог 30 дней → удаляется только старая.
    $removed = FormSubmission::deleteOlderThan(30);
    assert_same(1, $removed, 'удалена одна старая заявка');
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn(), 'свежая осталась');

    $pdo->exec('DELETE FROM form_submissions');
    $pdo->exec('DELETE FROM forms');
});
