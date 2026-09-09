<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\TelegramGateway;
use App\Models\Setting;
use App\Models\User;

// --- Юнит: без БД ---

test('TelegramGateway: normalizePhone приводит к E.164, отбраковывает мусор', function () {
    assert_same('+998901234567', TelegramGateway::normalizePhone('+998 90 123-45-67'));
    assert_same('+998901234567', TelegramGateway::normalizePhone('998901234567'));
    assert_true(TelegramGateway::normalizePhone('123') === null);
    assert_true(TelegramGateway::normalizePhone('abc') === null);
});

test('TelegramGateway: buildPayload и маскировка номера в логах', function () {
    $p = TelegramGateway::buildPayload('+998901234567', '123456');
    assert_same('+998901234567', $p['phone_number']);
    assert_same('123456', $p['code']);
    assert_same(300, $p['ttl']);

    $masked = TelegramGateway::maskPhone('+998901234567');
    assert_contains('*', $masked);
    assert_not_contains('12345', $masked);
});

// --- БД + сессия: полный поток входа ---

test('Вход без второго фактора: только ограниченная setup-сессия (БД)', function () {
    ensure_test_db();
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

    Setting::set('telegram_gateway_token', '');
    $login = 'tg-off-' . bin2hex(random_bytes(3));
    $uid = User::create($login, $login . '@test.local', 'Str0ng-Pass-2026!', 'admin');

    $res = Auth::attemptLogin($login, 'Str0ng-Pass-2026!');
    assert_same('setup_required', $res['status']);
    assert_same($uid, (int) ($_SESSION['user_id'] ?? 0));
    assert_true(Auth::requiresTwoFactorSetup(), 'полная админка остаётся закрытой');

    Auth::logout();
    @session_start();
    User::delete($uid);
});

test('Вход с шлюзом и телефоном: код отправляется, при недоступности шлюза — send_failed (БД)', function () {
    ensure_test_db();
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';

    // Шлюз «настроен», но URL указывает в никуда — отправка мгновенно падает.
    Setting::set('telegram_gateway_token', 'test-token');
    putenv('TELEGRAM_GATEWAY_URL=http://127.0.0.1:1/unreachable');

    $login = 'tg-on-' . bin2hex(random_bytes(3));
    $uid = User::create($login, $login . '@test.local', 'Str0ng-Pass-2026!', 'admin', '+998901234567');

    $res = Auth::attemptLogin($login, 'Str0ng-Pass-2026!');
    assert_same('send_failed', $res['status']);
    assert_true(empty($_SESSION['user_id']), 'сессия не установлена');
    assert_true(empty($_SESSION['pending_user_id']), 'pending очищен после сбоя');

    putenv('TELEGRAM_GATEWAY_URL');
    Setting::set('telegram_gateway_token', '');
    User::delete($uid);
});

test('completeTwoFactor: верный код пускает, неверный/просроченный — нет (БД)', function () {
    ensure_test_db();
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.3';

    $login = 'tg-code-' . bin2hex(random_bytes(3));
    $uid = User::create($login, $login . '@test.local', 'Str0ng-Pass-2026!', 'admin', '+998901234567');

    // Имитируем состояние «код отправлен».
    $makePending = static function () use ($uid): string {
        $code = '654321';
        $_SESSION['pending_user_id'] = $uid;
        $_SESSION['pending_since'] = time();
        $_SESSION['pending_code_hash'] = hash('sha256', $code);
        $_SESSION['pending_code_expires'] = time() + 300;
        return $code;
    };

    // Неверный код — отказ, pending сохраняется для повторной попытки.
    $makePending();
    assert_false(Auth::completeTwoFactor('000000'));
    assert_true(!empty($_SESSION['pending_user_id']), 'после неверного кода можно повторить');

    // Верный код (с пробелом — нормализуется) — вход.
    $code = $makePending();
    assert_true(Auth::completeTwoFactor(substr($code, 0, 3) . ' ' . substr($code, 3)));
    assert_same($uid, (int) ($_SESSION['user_id'] ?? 0));
    Auth::logout();
    @session_start();

    // Просроченный код — отказ и сброс pending.
    $makePending();
    $_SESSION['pending_code_expires'] = time() - 1;
    assert_false(Auth::completeTwoFactor('654321'));
    assert_true(empty($_SESSION['pending_user_id']), 'просроченный pending сброшен');

    Database::pdo()->prepare('DELETE FROM login_attempts WHERE identifier LIKE :i')
        ->execute([':i' => '%' . $login . '%']);
    User::delete($uid);
});

test('users.phone присутствует в схеме (БД)', function () {
    ensure_test_db();
    $col = Database::pdo()->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
    assert_true($col !== false && $col !== null, 'колонка users.phone существует');
});
