<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\AuditLog;

/** Таблица журнала (идемпотентно — миграция с IF NOT EXISTS). */
function ensure_audit_table(): void
{
    ensure_test_db();
    Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_audit_log.sql'));
}

test('AuditLog::record пишет POST в /admin от залогиненного, игнорирует остальное (БД)', function () {
    ensure_audit_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM audit_log');

    $_SESSION['user_id'] = 777;
    $_SESSION['username'] = 'auditor';
    $_SERVER['REMOTE_ADDR'] = '10.1.2.3';

    // GET не пишется.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/pages';
    AuditLog::record();

    // POST вне /admin не пишется.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/forms/contact/submit';
    AuditLog::record();

    // POST на /admin/login не пишется (до аутентификации).
    $_SERVER['REQUEST_URI'] = '/admin/login';
    AuditLog::record();

    // Выход пишется отдельно как AUTH-событие, без дубля POST.
    $_SERVER['REQUEST_URI'] = '/admin/logout';
    AuditLog::record();

    // POST в панель — пишется (query-строка отбрасывается).
    $_SERVER['REQUEST_URI'] = '/admin/pages/5/edit?block_lang=ru';
    AuditLog::record();

    // Без сессии — не пишется.
    unset($_SESSION['user_id']);
    AuditLog::record();

    $rows = $pdo->query('SELECT * FROM audit_log')->fetchAll();
    assert_same(1, count($rows), 'ровно одна запись');
    assert_same(777, (int) $rows[0]['user_id']);
    assert_same('auditor', (string) $rows[0]['username']);
    assert_same('POST', (string) $rows[0]['method']);
    assert_same('/admin/pages/5/edit', (string) $rows[0]['path']);
    assert_same('10.1.2.3', (string) $rows[0]['ip']);

    $pdo->exec('DELETE FROM audit_log');
    unset($_SESSION['username']);
});

test('AuditLog::search фильтрует по пользователю, пути и датам; пагинация (БД)', function () {
    ensure_audit_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM audit_log');

    $ins = $pdo->prepare('INSERT INTO audit_log (user_id, username, method, path, ip, created_at) VALUES (?,?,?,?,?,?)');
    $ins->execute([1, 'anna', 'POST', '/admin/pages/1/edit', '1.1.1.1', '2026-07-01 10:00:00']);
    $ins->execute([1, 'anna', 'POST', '/admin/news/create', '1.1.1.1', '2026-07-03 10:00:00']);
    $ins->execute([2, 'boris', 'POST', '/admin/settings', '2.2.2.2', '2026-07-05 10:00:00']);
    $ins->execute([2, 'boris', 'AUTH', 'auth/login.failed', '2.2.2.2', '2026-07-06 10:00:00']);

    $all = AuditLog::search();
    assert_same(4, $all['total']);
    assert_same('auth/login.failed', (string) $all['items'][0]['path'], 'свежие сверху');

    assert_same(2, AuditLog::search(['user_id' => 1])['total']);
    assert_same(1, AuditLog::search(['q' => 'news'])['total']);
    assert_same(1, AuditLog::search(['method' => 'AUTH'])['total']);
    assert_same(3, AuditLog::search(['from' => '2026-07-02'])['total']);
    assert_same(1, AuditLog::search(['from' => '2026-07-02', 'to' => '2026-07-04'])['total']);
    // Мусорная дата игнорируется.
    assert_same(4, AuditLog::search(['from' => 'DROP TABLE'])['total']);

    // Пагинация: по одному на страницу.
    $p2 = AuditLog::search([], 2, 1);
    assert_same(4, $p2['total']);
    assert_same(1, count($p2['items']));
    assert_same('/admin/settings', (string) $p2['items'][0]['path']);

    // Список акторов для фильтра.
    $actors = AuditLog::actors();
    assert_same(2, count($actors));

    $pdo->exec('DELETE FROM audit_log');
});

test('AuditLog::authEventMeta переводит события входа для интерфейса', function (): void {
    $failed = AuditLog::authEventMeta('auth/login.failed');
    assert_same('Неверный логин или пароль', $failed['label']);
    assert_same('danger', $failed['tone']);

    $success = AuditLog::authEventMeta('auth/2fa');
    assert_same('Код входа подтверждён', $success['label']);
    assert_same('success', $success['tone']);
});

test('AuditLog формирует сводку и список событий входа (БД)', function (): void {
    ensure_audit_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM audit_log');

    $ins = $pdo->prepare(
        'INSERT INTO audit_log (user_id, username, method, path, ip, created_at) VALUES (?,?,?,?,?,?)'
    );
    $now = date('Y-m-d H:i:s');
    $ins->execute([1, 'anna', 'AUTH', 'auth/2fa', '1.1.1.1', $now]);
    $ins->execute([1, 'anna', 'AUTH', 'auth/2fa.failed', '1.1.1.1', $now]);
    $ins->execute([null, 'bot', 'AUTH', 'auth/login.locked', '2.2.2.2', $now]);
    $ins->execute([null, 'bot', 'AUTH', 'auth/login.send-failed', '2.2.2.2', $now]);
    $ins->execute([1, 'anna', 'POST', '/admin/pages', '1.1.1.1', $now]);
    $ins->execute([1, 'anna', 'AUTH', 'auth/login.failed', '1.1.1.1', date('Y-m-d H:i:s', time() - 48 * 3600)]);

    $summary = AuditLog::authSummary(24);
    assert_same(4, $summary['total']);
    assert_same(1, $summary['successful']);
    assert_same(1, $summary['failed']);
    assert_same(1, $summary['locked']);
    assert_same(1, $summary['delivery_failed']);

    $events = AuditLog::recentAuthEvents(2);
    assert_same(2, count($events));
    assert_same('AUTH', (string) $events[0]['method']);
    assert_same('AUTH', (string) $events[1]['method']);

    $pdo->exec('DELETE FROM audit_log');
});

test('AuditLog::purgeOlderThan удаляет только старые записи (БД)', function () {
    ensure_audit_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM audit_log');

    $ins = $pdo->prepare('INSERT INTO audit_log (user_id, username, method, path, ip, created_at) VALUES (?,?,?,?,?,?)');
    $ins->execute([1, 'anna', 'POST', '/admin/old', null, date('Y-m-d H:i:s', time() - 200 * 86400)]);
    $ins->execute([1, 'anna', 'POST', '/admin/new', null, date('Y-m-d H:i:s')]);

    assert_same(1, AuditLog::purgeOlderThan(180));
    $rows = $pdo->query('SELECT path FROM audit_log')->fetchAll();
    assert_same(1, count($rows));
    assert_same('/admin/new', (string) $rows[0]['path']);
    assert_same(0, AuditLog::purgeOlderThan(0), 'нулевой срок — ничего не трогаем');

    $pdo->exec('DELETE FROM audit_log');
});
