<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RbacGuard;
use App\Core\TOTP;
use App\Models\User;

/**
 * Финальный аудит авторизации, аутентификации и сессий.
 *
 * Здесь заперты выводы аудита, а не поведение «вообще»: заглушка WebAuthn
 * удалена целиком, роль в системе ровно две, код TOTP одноразовый, а
 * подключение второго фактора требует пароля.
 */

test('Заглушки WebAuthn/Passkey в коде не осталось', function () {
    // Вход подтверждался одним credential_id — публичным идентификатором, без
    // подписи, challenge и счётчика. Маршрутов не было, но класс лежал в одном
    // шаге от полного обхода пароля и второго фактора.
    assert_false(is_file(APP_ROOT . '/app/Core/WebAuthn.php'), 'App\Core\WebAuthn должен быть удалён');
    assert_false(
        is_file(APP_ROOT . '/app/Controllers/Admin/PasskeyController.php'),
        'PasskeyController должен быть удалён'
    );

    $login = (string) file_get_contents(APP_ROOT . '/app/Views/admin/auth/login.php');
    assert_not_contains('passkey', $login, 'кнопка входа по Passkey ведёт на несуществующий маршрут');
    assert_not_contains('/admin/passkey/', $login);

    $dashboard = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DashboardController.php');
    assert_not_contains(
        'user_passkeys',
        $dashboard,
        'таблицы нет в schema.sql — запрос ронял бы дашборд свежей установки'
    );

    $drop = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_23_drop_user_passkeys.sql');
    assert_contains('DROP TABLE IF EXISTS `user_passkeys`', $drop);
});

test('Таблица маршрутов одна — public/index.php', function () {
    // config/routes.php был копией, которую никто не подключал: она успела
    // разъехаться (4 несуществующих маршрута /admin/passkey/* против шести
    // недостающих настоящих) и попадала в релизный архив. Читающий её аудит
    // видел маршруты, которых в приложении нет.
    assert_false(is_file(APP_ROOT . '/config/routes.php'), 'вторая таблица маршрутов снова появилась');

    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_not_contains('/admin/passkey/', $routes);
});

test('Ролей ровно две: список Auth совпадает с ENUM users.role и RbacGuard', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    assert_contains("role            ENUM('admin', 'editor')", $schema);

    // 'super_admin' был недостижим (ENUM его не принимает), но Auth считал его
    // полным доступом, а RbacGuard — нет: такая роль не могла бы ничего.
    foreach ([
        '/app/Core/Auth.php',
        '/app/Core/RbacGuard.php',
        '/app/Core/NotificationCenter.php',
        '/app/Models/Notification.php',
        '/app/Controllers/Site/FormController.php',
        '/app/Views/admin/users/index.php',
    ] as $file) {
        assert_not_contains('super_admin', (string) file_get_contents(APP_ROOT . $file), "остался след super_admin в {$file}");
    }

    assert_true(RbacGuard::roleCan(RbacGuard::ROLE_ADMIN, 'manage_users'));
    assert_false(RbacGuard::roleCan(RbacGuard::ROLE_EDITOR, 'manage_users'));
    assert_true(RbacGuard::roleCan(RbacGuard::ROLE_EDITOR, 'edit_content'));
});

test('TOTP::matchStep возвращает шаг, verify остаётся булевым', function () {
    $secret = TOTP::generateSecret();
    $step = (int) floor(time() / 30);
    $code = TOTP::code($secret);

    assert_same($step, TOTP::matchStep($secret, $code), 'должен вернуться номер текущего шага');
    assert_true(TOTP::verify($secret, $code));

    // Код соседнего шага принимается (окно ±1) и отдаёт именно свой номер.
    assert_same($step - 1, TOTP::matchStep($secret, TOTP::code($secret, $step - 1)));
    // Код далеко за окном и мусор — не совпадение.
    assert_same(null, TOTP::matchStep($secret, TOTP::code($secret, $step - 10)));
    assert_same(null, TOTP::matchStep($secret, 'не-код'));
    assert_same(null, TOTP::matchStep($secret, '12345'));
});

test('Подключение второго фактора требует пароля (админка и портал)', function () {
    // Иначе угнанная сессия привязывает к аккаунту свой аутентификатор и
    // получает постоянный второй фактор — усиление вместо защиты.
    $profile = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ProfileController.php');
    $enable = substr($profile, (int) strpos($profile, 'public function enableTotp'));
    $enable = substr($enable, 0, (int) strpos($enable, 'public function disableTotp'));
    assert_contains("password_verify((string) (\$_POST['password'] ?? '')", $enable);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/profile/index.php');
    assert_contains('id="totp_enable_password"', $form, 'в форме подключения нет поля пароля');

    $portal = (string) file_get_contents(APP_ROOT . '/app/Controllers/Repo/PortalController.php');
    $portalEnable = substr($portal, (int) strpos($portal, 'public function enableTotp'));
    $portalEnable = substr($portalEnable, 0, (int) strpos($portalEnable, 'private static function totpIssuer'));
    assert_contains('password_verify', $portalEnable, 'портал включает 2FA без подтверждения паролем');
    assert_contains(
        'id="enable_totp_password"',
        (string) file_get_contents(APP_ROOT . '/app/Views/repo/security.php')
    );
});

test('CSRF проверяется у всех небезопасных методов, не только у POST', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Csrf.php');
    assert_contains("SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']", $source);
    assert_not_contains("=== 'POST'", $source, 'проверка не должна зависеть от единственного метода');

    // Токен постоянной длины и сравнивается hash_equals.
    @session_start();
    unset($_SESSION['csrf_token']);
    $token = Csrf::token();
    assert_same(64, strlen($token));
    assert_true(Csrf::verify($token));
    // Последний символ меняем на заведомо другой: приписанный '0' совпадал бы
    // с исходным токеном каждый шестнадцатый прогон, и тест падал случайно.
    assert_false(Csrf::verify(substr($token, 0, -1) . (substr($token, -1) === '0' ? '1' : '0')));
    assert_false(Csrf::verify(''));
});

test('Успешный вход не сбрасывает корзину перебора по IP', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Auth.php');
    assert_contains("CLEAR_ON_SUCCESS = ['pair', 'account']", $source);
    assert_not_contains('array_key_first($identifiers)', $source, 'корзины нельзя выбирать по порядку массива');

    $repo = (string) file_get_contents(APP_ROOT . '/app/Core/RepoAuth.php');
    assert_contains("CLEAR_ON_SUCCESS = ['pair', 'account']", $repo);
    assert_not_contains('array_key_last($identifiers)', $repo);
});

test('Смена пароля меняет идентификатор сессии и перерегистрирует её', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ProfileController.php');
    $change = substr($source, (int) strpos($source, 'public function changePassword'));
    $change = substr($change, 0, (int) strpos($change, 'public function revokeSession'));

    assert_contains('session_regenerate_id(true)', $change, 'утёкший sid должен умирать вместе со сменой пароля');
    // Реестр проверяется в Auth::check(): без перерегистрации автор смены
    // пароля вылетел бы из панели следующим же запросом.
    $registerAt = strpos($change, 'SessionRegistry::register');
    $revokeAt = strpos($change, 'SessionRegistry::revokeAllExcept');
    assert_true($registerAt !== false && $revokeAt !== false && $registerAt < $revokeAt);
});

test('Код TOTP засчитывается один раз (БД)', function () {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.9';

    if (!\App\Core\SecretBox::hasValidCurrentKey()) {
        \App\Core\Config::merge(['crypto' => ['encryption_key' => bin2hex(random_bytes(32)), 'previous_encryption_key' => '']]);
    }

    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_replay']);
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['totp_replay', 'replay@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), 'admin']);

    $user = (array) User::findByUsername('totp_replay');
    $secret = TOTP::generateSecret();
    User::enableTotp((int) $user['id'], $secret);

    assert_same('needs_code', Auth::attemptLogin('totp_replay', 'Str0ng-Pass!2026')['status']);
    $code = TOTP::code($secret);
    assert_true(Auth::completeTwoFactor($code), 'первый ввод кода должен пройти');

    // Тот же код второй раз: подсмотренный код не должен открывать сессию,
    // пока шаг ещё не сменился (RFC 6238 §5.2).
    $_SESSION = [];
    assert_same('needs_code', Auth::attemptLogin('totp_replay', 'Str0ng-Pass!2026')['status']);
    assert_false(Auth::completeTwoFactor($code), 'повтор кода TOTP должен отклоняться');

    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_replay']);
});

test('Без применённой миграции вход не падает, а теряет только защиту от повтора (БД)', function () {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }

    // Сценарий: код выложили, `php database/migrate.php` не запустили.
    // Второй фактор обязан продолжать работать — иначе владелец заперт
    // снаружи админки, и чинить придётся через прямой доступ к базе.
    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_nomigration']);
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['totp_nomigration', 'nomig@example.com', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $userId = (int) $pdo->lastInsertId();

    $pdo->exec('ALTER TABLE users DROP COLUMN totp_last_step');
    try {
        $step = (int) floor(time() / 30);
        // Не исключение и не отказ: код принимается, как до появления защиты.
        assert_true(User::consumeTotpStep($userId, $step), 'без колонки код должен приниматься');
        assert_true(User::consumeTotpStep($userId, $step), 'и повторно — защиты просто нет');
    } finally {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_last_step BIGINT NULL DEFAULT NULL AFTER totp_enabled');
        $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_nomigration']);
    }

    // Настоящая ошибка запроса по-прежнему обязана всплывать, а не глохнуть.
    $failed = false;
    try {
        User::consumeTotpStep($userId, 1);
        $pdo->query('SELECT nonexistent_column_zz FROM users LIMIT 1');
    } catch (\PDOException $e) {
        $failed = true;
    }
    assert_true($failed, 'посторонние ошибки БД не должны подавляться');
});
