<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\LoginAlert;
use App\Core\TOTP;
use App\Core\UserAgentLabel;
use App\Models\Setting;
use App\Models\User;

/*
 * Уведомление в Telegram о входе в панель (LoginAlert): успешный вход и
 * неудачная попытка уходят владельцу аккаунта с IP, временем и устройством;
 * попытка под чужим именем — супер-администраторам; повторные неудачи с того
 * же адреса гасятся; выключенная настройка молчит.
 */

/** @return list<array{0: int, 1: string}> */
function login_alert_capture(): array
{
    $GLOBALS['login_alert_sent'] = [];
    LoginAlert::setTransport(static function (int $chatId, string $text): void {
        $GLOBALS['login_alert_sent'][] = [$chatId, $text];
    });

    return [];
}

/** @return list<array{0: int, 1: string}> */
function login_alert_sent(): array
{
    return $GLOBALS['login_alert_sent'] ?? [];
}

/** @return array<string, mixed> */
function login_alert_user(string $username, int $chatId, string $role = 'editor'): array
{
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    @session_start();
    $_SESSION = [];
    // Свой адрес на прогон: и ограничитель попыток, и молчание о повторах
    // помнят адрес между прогонами.
    $_SERVER['REMOTE_ADDR'] = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 Edg/140.0';

    if (!\App\Core\SecretBox::hasValidCurrentKey()) {
        \App\Core\Config::merge(['crypto' => ['encryption_key' => bin2hex(random_bytes(32)), 'previous_encryption_key' => '']]);
    }
    Setting::set(LoginAlert::SUCCESS_KEY, '1');
    Setting::set(LoginAlert::FAILURE_KEY, '1');

    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role, telegram_chat_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$username, $username . '@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), $role, $chatId]);
    User::forgetCache();
    login_alert_capture();

    return (array) User::findByUsername($username);
}

test('Устройство называется по User-Agent, частные браузеры раньше общих', function (): void {
    $edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 Edg/140.0';
    assert_same('Edge, Windows', UserAgentLabel::describe($edge));
    $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/140.0 Mobile Safari/604.1';
    assert_same('Chrome, iPhone', UserAgentLabel::describe($iphone), 'iPhone — не macOS, хотя в строке есть «Mac OS X»');
    $yandex = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/138.0 YaBrowser/25.4 Mobile Safari/537.36';
    assert_same('Яндекс.Браузер, Android', UserAgentLabel::describe($yandex));
    assert_same('curl/8.5.0', UserAgentLabel::describe('curl/8.5.0'), 'незнакомый клиент показывается как есть');
    assert_same('Не указано', UserAgentLabel::describe(''));
});

test('Неверный пароль: владельцу аккаунта, с IP, временем и устройством; повтор гасится', function (): void {
    login_alert_user('alert_editor', 7001001);

    assert_same('invalid', Auth::attemptLogin('alert_editor', 'wrong')['status']);
    $sent = login_alert_sent();
    assert_same(1, count($sent), 'одно сообщение');
    [$chatId, $text] = $sent[0];
    assert_same(7001001, $chatId, 'в чат владельца аккаунта');
    assert_contains('Неудачная попытка входа', $text);
    assert_contains('неверный пароль', $text);
    assert_contains('<code>' . $_SERVER['REMOTE_ADDR'] . '</code>', $text);
    assert_contains('Edge, Windows', $text);
    assert_true(preg_match('/Время: \d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/u', $text) === 1, 'время входа');

    Auth::attemptLogin('alert_editor', 'wrong-again');
    assert_same(1, count(login_alert_sent()), 'повтор с того же адреса в течение 10 минут не присылается');

    $_SERVER['REMOTE_ADDR'] = '172.16.' . random_int(1, 250) . '.' . random_int(1, 250);
    Auth::attemptLogin('alert_editor', 'wrong');
    assert_same(2, count(login_alert_sent()), 'с другого адреса — новое сообщение');
});

test('Имя, которого нет: супер-администраторам, имя экранировано', function (): void {
    login_alert_user('alert_root', 7001002, 'admin');

    Auth::attemptLogin('<b>ghost</b>', 'x');
    $chats = array_column(login_alert_sent(), 0);
    assert_true(in_array(7001002, $chats, true), 'супер-администратор с привязанным Telegram получает сообщение');
    $text = (string) (login_alert_sent()[0][1] ?? '');
    assert_contains('&lt;b&gt;ghost&lt;/b&gt;', $text, 'присланное имя — данные, а не разметка');
    assert_not_contains('<b>ghost</b>', $text);
});

test('Вход с приложением: неверный код — «пароль уже известен», верный — сообщение об успехе', function (): void {
    $user = login_alert_user('alert_totp', 7001003);
    $secret = TOTP::generateSecret();
    User::enableTotp((int) $user['id'], $secret);

    assert_same('needs_code', Auth::attemptLogin('alert_totp', 'Str0ng-Pass!2026')['status']);
    assert_same([], login_alert_sent(), 'верный пароль сам по себе ещё не вход');

    assert_false(Auth::completeTwoFactor('000000'));
    $fail = login_alert_sent();
    assert_same(1, count($fail));
    assert_contains('неверный код второго фактора', $fail[0][1]);
    assert_contains('Пароль был введён верно', $fail[0][1]);

    $code = (string) (new ReflectionMethod(TOTP::class, 'calculateCode'))->invoke(null, $secret, (int) floor(time() / 30));
    assert_true(Auth::completeTwoFactor($code));
    $all = login_alert_sent();
    assert_same(2, count($all));
    [$chatId, $text] = $all[1];
    assert_same(7001003, $chatId);
    assert_contains('Вход в панель управления', $text);
    assert_contains('приложения-аутентификатора', $text, 'назван способ входа');
    assert_contains('/admin/profile', $text, 'куда идти, если это были не вы');
});

test('Выключенные настройки молчат; переключатели есть в разделе «Telegram»', function (): void {
    login_alert_user('alert_quiet', 7001004);
    Setting::set(LoginAlert::FAILURE_KEY, '0');
    Setting::set(LoginAlert::SUCCESS_KEY, '0');

    Auth::attemptLogin('alert_quiet', 'wrong');
    LoginAlert::success(['username' => 'alert_quiet', 'telegram_chat_id' => 7001004], LoginAlert::METHOD_TOTP);
    assert_same([], login_alert_sent());

    Setting::set(LoginAlert::FAILURE_KEY, '1');
    Setting::set(LoginAlert::SUCCESS_KEY, '1');
    LoginAlert::success(['username' => 'no_chat', 'telegram_chat_id' => 0], LoginAlert::METHOD_TOTP);
    assert_same([], login_alert_sent(), 'без привязанного чата сообщать некуда');

    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/telegram/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/TelegramController.php');
    foreach (['telegram_login_alert_success', 'telegram_login_alert_failure'] as $field) {
        assert_contains('name="' . $field . '"', $view);
        assert_contains("\$_POST['" . $field . "']", $controller);
    }
    LoginAlert::setTransport(null);
});
