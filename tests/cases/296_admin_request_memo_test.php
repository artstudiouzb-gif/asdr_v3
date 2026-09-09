<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\MigrationRunner;
use App\Models\User;

/*
 * Панель спрашивала одно и то же по нескольку раз за запрос.
 *
 * Замерено обходом всей админки: строка реестра сессий проверялась 5–8 раз на
 * страницу, запись пользователя читалась 3–4 раза, а бейдж «непринятые
 * миграции» в шапке при каждом рендере выполнял CREATE TABLE IF NOT EXISTS —
 * то есть DDL при отрисовке страницы. Секунд это не стоило, но на хостинге,
 * где у пользователя БД нет права CREATE, панель падала бы на каждой странице
 * из-за счётчика в углу.
 */

test('Счётчик миграций не создаёт таблицу при чтении', function (): void {
    $runner = (string) file_get_contents(APP_ROOT . '/app/Core/MigrationRunner.php');
    $start = strpos($runner, 'public static function pendingCount');
    assert_true($start !== false, 'счётчик на месте');
    $end = strpos($runner, 'public static function status', $start);
    $body = substr($runner, $start, ($end !== false ? $end : strlen($runner)) - $start);

    assert_true(
        !str_contains($body, 'ensureMigrationsTable'),
        'счётчик снова создаёт таблицу: шапка админки рисуется на каждой странице'
    );
    assert_contains('SELECT filename FROM migrations', $body, 'счётчик читает применённые');
});

test('Счётчик миграций честен и без таблицы (БД)', function (): void {
    ensure_test_db();
    $dir = APP_ROOT . '/database/migrations';
    $files = count(glob($dir . '/*.sql') ?: []);

    // Проверяем на отдельной пустой базе, а не роняя таблицу рабочей: тест,
    // оставивший базу без migrations, ронял бы соседей — проверено на себе.
    $scratch = 'asdr_probe_' . bin2hex(random_bytes(4));
    $pdo = Database::pdo();
    try {
        $pdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\Throwable $e) {
        skip_test('нет права создавать базу для пробы');
    }

    try {
        $probe = new PDO(
            'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';dbname=' . $scratch . ';charset=utf8mb4',
            (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
            (string) (getenv('TEST_DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Нет таблицы — значит не применено ничего; и создавать её чтение не смеет.
        assert_same($files, MigrationRunner::pendingCount($probe, $dir), 'без таблицы не применено ничего');
        $exists = $probe->query("SHOW TABLES LIKE 'migrations'")->fetchColumn();
        assert_true(
            $exists === false || $exists === null || $exists === '',
            'чтение создало таблицу — на хостинге без права CREATE это падение на каждой странице'
        );
    } finally {
        $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    }
});

test('Запись пользователя в пределах запроса читается один раз, а правка её сбрасывает (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    User::forgetCache();
    $pdo->exec("DELETE FROM users WHERE username = 'memo-probe'");
    $id = User::create('memo-probe', 'memo-probe@example.test', 'Memo-probe-1', 'editor');

    $first = User::findById($id);
    assert_true(is_array($first), 'запись найдена');

    // Правим в обход модели: пока ответ в памяти, он не должен измениться.
    $pdo->exec("UPDATE users SET email = 'changed@example.test' WHERE id = " . (int) $id);
    assert_same(
        'memo-probe@example.test',
        (string) (User::findById($id)['email'] ?? ''),
        'повторный вопрос отвечен из памяти, без запроса'
    );

    // Запись через модель сбрасывает память — дальше строка свежая.
    User::updateAdminLang($id, 'ru');
    assert_same(
        'changed@example.test',
        (string) (User::findById($id)['email'] ?? ''),
        'после записи память сброшена'
    );

    $pdo->exec("DELETE FROM users WHERE id = " . (int) $id);
    User::forgetCache();
});

test('Проверка сессии в реестре не повторяется за запрос', function (): void {
    // Мгновенность отзыва от памяти не страдает: запрос живёт миллисекунды,
    // а «немедленно» и означает «со следующего запроса». Ключ включает
    // идентификатор сессии, поэтому вход внутри запроса память не переиспользует.
    $auth = (string) file_get_contents(APP_ROOT . '/app/Core/Auth.php');

    assert_contains('sessionStillRegistered', $auth, 'ответ реестра спрашивается через память');
    assert_contains('$registryMemo', $auth);
    assert_true(
        substr_count($auth, 'SessionRegistry::exists(') === 1,
        'реестр опрашивается ровно в одном месте'
    );
    // Выход обязан сбрасывать память, иначе после logout() сессия «ещё жива».
    $logoutAt = strpos($auth, 'public static function logout(): void');
    assert_true($logoutAt !== false, 'выход на месте');
    assert_contains('self::$registryMemo = [];', substr($auth, $logoutAt, 400), 'выход сбрасывает память');
});
