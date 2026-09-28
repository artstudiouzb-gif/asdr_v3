<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\TelegramBot;
use App\Core\TOTP;
use App\Models\User;

/*
 * Второй фактор: перебор кода не обходится сменой адреса, неизвестное имя
 * не выдаёт себя временем ответа, код в Telegram называет, откуда запрошен
 * вход, и не пересылается.
 */

/** @return array<string, mixed> */
function tfa_hard_user(): array
{
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    @session_start();
    $_SESSION = [];
    \App\Core\LoginAlert::setTransport(static function (): void {});
    if (!\App\Core\SecretBox::hasValidCurrentKey()) {
        \App\Core\Config::merge(['crypto' => ['encryption_key' => bin2hex(random_bytes(32)), 'previous_encryption_key' => '']]);
    }

    $pdo = \App\Core\Database::pdo();
    // Имя своё на прогон: общий счётчик аккаунта живёт между прогонами.
    $username = 'tfa_hard_' . bin2hex(random_bytes(3));
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute([$username, $username . '@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), 'editor']);
    $user = (array) User::findByUsername($username);
    User::enableTotp((int) $user['id'], TOTP::generateSecret());

    return (array) User::findByUsername($username);
}

function tfa_hard_ip(): void
{
    $_SERVER['REMOTE_ADDR'] = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
}

function tfa_hard_cleanup(array $user): void
{
    \App\Core\Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $user['id']]);
    \App\Core\LoginAlert::setTransport(null);
}

test('Неверные коды в одном ожидании: после пяти — снова пароль', function (): void {
    $user = tfa_hard_user();
    tfa_hard_ip();
    assert_same('needs_code', Auth::attemptLogin((string) $user['username'], 'Str0ng-Pass!2026')['status']);

    for ($i = 1; $i < Auth::MAX_CODE_ATTEMPTS; $i++) {
        // Каждая попытка — с нового адреса: лимит пары «IP + аккаунт»
        // так не срабатывает, счётчик в сессии — срабатывает.
        tfa_hard_ip();
        assert_false(Auth::completeTwoFactor('000000'));
        assert_true(Auth::pendingUserId() !== null, 'ожидание ещё живо после ' . $i . '-й ошибки');
    }
    tfa_hard_ip();
    assert_false(Auth::completeTwoFactor('000000'));
    assert_same(null, Auth::pendingUserId(), 'ожидание сброшено — дальше только с паролем');
    assert_true(Auth::pendingResetForAttempts(), 'причина сброса известна форме');
    tfa_hard_cleanup($user);
});

test('Общий предел на аккаунт: с новых адресов и в новых сессиях верный код не проходит', function (): void {
    $user = tfa_hard_user();
    $secret = (string) $user['totp_secret'];
    $failures = 0;
    while ($failures < Auth::ACCOUNT_CODE_ATTEMPTS) {
        tfa_hard_ip();
        $_SESSION = [];
        assert_same('needs_code', Auth::attemptLogin((string) $user['username'], 'Str0ng-Pass!2026')['status']);
        for ($i = 0; $i < 2 && $failures < Auth::ACCOUNT_CODE_ATTEMPTS; $i++, $failures++) {
            assert_false(Auth::completeTwoFactor('000000'));
        }
    }

    tfa_hard_ip();
    $_SESSION = [];
    assert_same('needs_code', Auth::attemptLogin((string) $user['username'], 'Str0ng-Pass!2026')['status']);
    $code = (string) (new ReflectionMethod(TOTP::class, 'calculateCode'))->invoke(null, $secret, (int) floor(time() / 30));
    assert_false(Auth::completeTwoFactor($code), 'предел аккаунта исчерпан — даже верный код ждёт окна');
    tfa_hard_cleanup($user);
});

test('Неизвестное имя сверяется с хэшем той же стоимости, что настоящие', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Auth.php');
    assert_true(preg_match("/DUMMY_HASH = '([^']+)'/", $source, $m) === 1, 'хэш-заглушка объявлен');
    $info = password_get_info($m[1]);
    assert_same('bcrypt', $info['algoName']);
    assert_same(12, (int) ($info['options']['cost'] ?? 0), 'стоимость как у User::create/updatePassword');
    assert_contains("'cost' => 12", (string) file_get_contents(APP_ROOT . '/app/Models/User.php'));
    assert_contains('password_verify($password, $hash)', $source, 'сверка идёт и для неизвестного имени');
});

test('Код в Telegram называет, откуда запрошен вход, и не пересылается', function (): void {
    $text = TelegramBot::loginCodeText('123456', ['ip' => '203.0.113.5', 'device' => 'Chrome, <Windows>']);
    assert_contains('<code>123456</code>', $text);
    assert_contains('IP 203.0.113.5', $text);
    assert_contains('Chrome, &lt;Windows&gt;', $text, 'подпись устройства экранирована');
    assert_contains('не вводите код', $text);

    $bot = (string) file_get_contents(APP_ROOT . '/app/Core/TelegramBot.php');
    assert_contains("'protect_content' => true", $bot);
});
