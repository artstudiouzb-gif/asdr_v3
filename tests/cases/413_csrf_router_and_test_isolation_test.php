<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Router;

/*
 * Две страховки, которые закрывают целый класс ошибок, а не одну.
 *
 * CSRF проверяет роутер: прежде каждое действие звало проверку само, и новое
 * действие без этой строки молча оставалось без защиты.
 *
 * Каждый тест идёт в своей транзакции (tests/isolated_pdo.php): записи,
 * которые сценарий забыл убрать, не доживают до соседей и до следующего
 * прогона.
 */

/**
 * Прогон роутера в отдельном процессе: проверка токена завершает процесс
 * ответом 419, и в самом раннере это оборвало бы весь набор.
 */
function csrf_router_probe(string $path, bool $withToken): string
{
    $script = sprintf(
        'require %s; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REQUEST_URI"] = %s;'
        . 'if (%s) { App\\Core\\Session::start(); $_POST["csrf_token"] = App\\Core\\Csrf::token(); }'
        . '$r = new App\\Core\\Router(); $r->post(%s, static function (): void { echo "HANDLER"; });'
        . '$r->dispatch("POST", %s);',
        var_export(APP_ROOT . '/tests/bootstrap.php', true),
        var_export($path, true),
        $withToken ? 'true' : 'false',
        var_export($path, true),
        var_export($path, true)
    );
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg(sys_get_temp_dir())
        . ' -r ' . escapeshellarg($script) . ' 2>&1', $out);

    return implode("\n", $out);
}

test('CSRF: роутер не пускает POST в панель без токена и пускает с токеном', function (): void {
    $blocked = csrf_router_probe('/admin/csrf-probe', false);
    assert_not_contains('HANDLER', $blocked, 'действие не должно выполниться без токена');
    assert_contains('CSRF', $blocked);

    assert_contains('HANDLER', csrf_router_probe('/admin/csrf-probe', true), 'с верным токеном действие выполняется');
    assert_not_contains('HANDLER', csrf_router_probe('/repo/csrf-probe', false), 'портал репозитория тоже под защитой');
});

test('CSRF: публичные приёмники и похожие адреса роутер не трогает', function (): void {
    assert_contains('HANDLER', csrf_router_probe('/push/csrf-probe', false), 'анонимный маяк токена не несёт');
    assert_true(Router::requiresCsrf('/admin'));
    assert_true(Router::requiresCsrf('/admin/pages/1/edit'));
    assert_false(Router::requiresCsrf('/administration'), 'граница — сегмент адреса, а не начало строки');
    assert_false(Router::requiresCsrf('/_vitals'));
});

test('Изоляция: сценарий оставляет запись (её уберёт откат)', function (): void {
    ensure_test_db();
    Database::pdo()->prepare("INSERT INTO settings (`key`, `value`) VALUES ('isolation_probe', '1')
        ON DUPLICATE KEY UPDATE `value` = '1'")->execute();
});

test('Изоляция: запись прошлого сценария до следующего не доживает', function (): void {
    ensure_test_db();
    $n = (int) Database::pdo()->query("SELECT COUNT(*) FROM settings WHERE `key` = 'isolation_probe'")->fetchColumn();
    assert_same(0, $n, 'откат после сценария не сработал');
});

test('Изоляция: транзакция кода внутри теста — точка сохранения', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    assert_false($pdo->inTransaction(), 'транзакция теста коду не видна');

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('isolation_rolled', '1')")->execute();
    $pdo->rollBack();
    $n = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE `key` = 'isolation_rolled'")->fetchColumn();
    assert_same(0, $n, 'rollBack кода откатывает свою часть');

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO redirects (from_path, to_url, code) VALUES ('/isolation-probe', '/', 301)")->execute();
    $pdo->commit();
    assert_true((int) $pdo->lastInsertId() > 0, 'commit не обнуляет lastInsertId');
    assert_false($pdo->inTransaction());
});
