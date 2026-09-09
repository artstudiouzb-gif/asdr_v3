<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\TelegramBot;
use App\Core\TelegramLink;
use App\Models\Setting;
use App\Models\User;

// --- Юнит: сопоставление getUpdates с кодом привязки ---

test('TelegramBot::matchUpdates находит chat_id по коду (и /start CODE), берёт свежее', function () {
    $updates = [
        ['message' => ['text' => 'привет', 'chat' => ['id' => 1]]],
        ['message' => ['text' => 'link-abc123', 'chat' => ['id' => 42]]],
        ['message' => ['text' => '/start link-abc123', 'chat' => ['id' => 77]]],
        ['message' => ['text' => 'link-другой', 'chat' => ['id' => 99]]],
    ];
    // Самое свежее совпадение — /start от 77.
    assert_same(77, TelegramBot::matchUpdates($updates, 'link-abc123'));
    assert_true(TelegramBot::matchUpdates($updates, 'link-nope') === null);
    assert_true(TelegramBot::matchUpdates($updates, '') === null);
    assert_true(TelegramBot::matchUpdates([], 'link-abc123') === null);
});

test('TelegramBot::matchUpdates не привязывает 2FA к группе или каналу', function () {
    $updates = [
        ['message' => ['text' => 'link-safe', 'chat' => ['id' => -100123, 'type' => 'channel']]],
        ['message' => ['text' => '/start link-safe', 'chat' => ['id' => -456, 'type' => 'group']]],
        ['message' => ['text' => 'link-safe', 'chat' => ['id' => 321, 'type' => 'private']]],
    ];
    assert_same(321, TelegramBot::matchUpdates($updates, 'link-safe'));

    array_pop($updates);
    assert_true(TelegramBot::matchUpdates($updates, 'link-safe') === null);
});

test('TelegramLink: код привязки истекает и перевыпускается', function () {
    @session_start();
    $_SESSION = [];

    $first = TelegramLink::code();
    assert_same($first, TelegramLink::currentCode());
    assert_true((int) ($_SESSION['tg_link_code_expires'] ?? 0) > time());

    $_SESSION['tg_link_code_expires'] = time() - 1;
    assert_true(TelegramLink::currentCode() === null);
    $second = TelegramLink::code();
    assert_true($second !== $first);
});

// --- БД: users.telegram_chat_id + выбор канала при входе ---

test('users.telegram_chat_id присутствует в схеме (БД)', function () {
    ensure_test_db();
    $col = Database::pdo()->query("SHOW COLUMNS FROM users LIKE 'telegram_chat_id'")->fetch();
    assert_true($col !== false && $col !== null, 'колонка есть');
});

test('Приоритет канала: бот настроен + chat_id привязан → код шлёт бот (БД)', function () {
    ensure_test_db();
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.1.1';

    // Бот «настроен», но API недоступен → send_failed именно через бота
    // (шлюз не настроен вовсе — если бы канал выбрался неверно, был бы 'ok').
    Setting::set('telegram_bot_token', 'bot-test-token');
    Setting::set('telegram_gateway_token', '');
    putenv('TELEGRAM_BOT_URL=http://127.0.0.1:1');

    $login = 'bot-' . bin2hex(random_bytes(3));
    $uid = User::create($login, $login . '@test.local', 'Str0ng-Pass-2026!', 'admin');
    User::updateTelegramChatId($uid, 123456789);

    $res = Auth::attemptLogin($login, 'Str0ng-Pass-2026!');
    assert_same('send_failed', $res['status']);
    assert_true(empty($_SESSION['user_id']), 'сессия не установлена при сбое доставки');

    // Отвязали chat_id → каналов нет → только onboarding настройки 2FA.
    User::updateTelegramChatId($uid, null);
    $_SESSION = [];
    $res2 = Auth::attemptLogin($login, 'Str0ng-Pass-2026!');
    assert_same('setup_required', $res2['status']);
    assert_true(Auth::requiresTwoFactorSetup());
    Auth::logout();
    @session_start();

    putenv('TELEGRAM_BOT_URL');
    Setting::set('telegram_bot_token', '');
    User::delete($uid);
});
