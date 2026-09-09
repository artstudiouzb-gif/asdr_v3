<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\FormSubmission;

test('Заявки: общий журнал доступен из маршрутов и списка форм', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FormController.php');
    $formsView = (string) file_get_contents(APP_ROOT . '/app/Views/admin/forms/index.php');

    assert_contains("'/admin/forms/submissions'", $routes);
    assert_contains("'/admin/forms/submissions/{id}'", $routes);
    assert_contains('allSubmissions', $controller);
    assert_contains('Открыть заявки', $formsView);
    assert_contains('Все заявки', $formsView);
});

test('Заявки: список, карточка и удаление защищены отдельным правом', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FormController.php');

    assert_true(substr_count($controller, "requirePermission('manage_submissions')") >= 5);
    assert_not_contains('HTTP_REFERER', $controller, 'возврат после удаления не доверяет внешнему Referer');
    assert_contains('cleanSubmissionQuery', $controller);
});

test('Заявки: сводка, фильтры и прочтение одной карточки работают в БД', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM form_submissions');
    $pdo->exec('DELETE FROM forms');
    $fields = json_encode([
        ['name' => 'name', 'label' => 'Имя', 'type' => 'text'],
        ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel'],
    ], JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'INSERT INTO forms (name, slug, fields_json, created_at) VALUES (:name, :slug, :fields, NOW())'
    );
    $stmt->execute([':name' => 'Обратная связь', ':slug' => 'feedback-test', ':fields' => $fields]);
    $formId = (int) $pdo->lastInsertId();

    $firstId = FormSubmission::create($formId, ['name' => 'Алишер', 'phone' => '+99890'], '203.0.113.1', 'Test');
    FormSubmission::create($formId, ['name' => 'Малика', 'phone' => '+99891'], '203.0.113.2', 'Test');

    $summary = FormSubmission::summary();
    assert_same(2, $summary['total']);
    assert_same(2, $summary['unread']);
    $counts = FormSubmission::countsByForm();
    assert_same(2, $counts[$formId]['total']);

    $result = FormSubmission::search(['form_id' => $formId, 'status' => 'unread', 'q' => 'Алишер']);
    assert_same(1, $result['total']);
    assert_same(0, (int) $result['items'][0]['is_read'], 'просмотр списка не отмечает заявку прочитанной');

    $detail = FormSubmission::findWithForm($firstId);
    assert_same('Обратная связь', (string) $detail['form_name']);
    assert_same('Имя', (string) $detail['fields'][0]['label']);
    FormSubmission::markRead($firstId);
    assert_same(1, FormSubmission::summary()['read']);

    $pdo->exec('DELETE FROM form_submissions');
    $pdo->exec('DELETE FROM forms');
});

test('Заявки: дашборд использует настоящее имя формы и ведёт в журнал', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DashboardController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/dashboard.php');

    assert_contains('f.name AS form_title', $controller);
    assert_not_contains('f.title AS form_title', $controller);
    assert_contains('/admin/forms/submissions', $view);
    assert_contains('/admin/forms/submissions/<?= (int) $sub[\'id\'] ?>', $view);
});

test('Заявки: разметка списка и карточки сбалансирована', function (): void {
    foreach (['index.php', 'submissions.php', 'submission.php'] as $file) {
        $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/forms/' . $file);
        foreach (['div', 'form', 'table', 'nav', 'section', 'aside', 'dl'] as $tag) {
            assert_same(
                substr_count($view, '<' . $tag),
                substr_count($view, '</' . $tag . '>'),
                $file . ': несбалансированный тег ' . $tag
            );
        }
    }
});
