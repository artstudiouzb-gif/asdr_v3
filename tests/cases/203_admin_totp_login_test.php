<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\TOTP;
use App\Models\User;

/**
 * Вход в админку приложением-аутентификатором. До этого второй фактор был
 * только через Telegram: недоступный бот или потерянный токен запирали
 * владельца в собственной панели. TOTP считается на устройстве и от сети не
 * зависит.
 */

/** Текущий код приложения: считаем тем же алгоритмом, что и проверка. */
function totp_current_code(string $secret): string
{
    $method = new ReflectionMethod(TOTP::class, 'calculateCode');

    return (string) $method->invoke(null, $secret, (int) floor(time() / 30));
}

function totp_admin_reset(): array
{
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.7';

    // Свой ключ шифрования: секрет приложения хранится зашифрованным, и тест
    // не должен зависеть от того, что ключ настроил соседний файл.
    if (!\App\Core\SecretBox::hasValidCurrentKey()) {
        \App\Core\Config::merge(['crypto' => ['encryption_key' => bin2hex(random_bytes(32)), 'previous_encryption_key' => '']]);
    }

    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_admin']);
    $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['totp_admin', 'totp@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), 'admin']);

    return (array) User::findByUsername('totp_admin');
}

test('Без второго фактора вход остаётся ограниченным', function () {
    totp_admin_reset();

    $result = Auth::attemptLogin('totp_admin', 'Str0ng-Pass!2026');
    assert_same('setup_required', $result['status'], 'один пароль полного доступа не даёт');
    assert_true(Auth::requiresTwoFactorSetup());
});

test('С приложением-аутентификатором вход просит код и принимает его', function () {
    $user = totp_admin_reset();
    $secret = TOTP::generateSecret();
    User::enableTotp((int) $user['id'], $secret);

    $result = Auth::attemptLogin('totp_admin', 'Str0ng-Pass!2026');
    assert_same('needs_code', $result['status']);

    $channels = Auth::pendingChannels();
    assert_true($channels['totp'], 'страница ввода кода должна знать про приложение');
    assert_false($channels['telegram'], 'Telegram не настроен — код туда не отправляли');

    // Чужой код не подходит, свой — открывает сессию.
    assert_false(Auth::completeTwoFactor('000000'));
    assert_true(Auth::completeTwoFactor(totp_current_code($secret)), 'код из приложения должен приниматься');
    assert_true(Auth::check());
    assert_false(Auth::requiresTwoFactorSetup(), 'второй фактор есть — сессия полная');
});

test('Отключение приложения возвращает ограниченную сессию', function () {
    $user = totp_admin_reset();
    User::enableTotp((int) $user['id'], TOTP::generateSecret());
    User::disableTotp((int) $user['id']);

    $fresh = (array) User::findById((int) $user['id']);
    assert_same(0, (int) $fresh['totp_enabled']);
    assert_true($fresh['totp_secret'] === null || $fresh['totp_secret'] === '');

    Auth::syncTwoFactorSetup($fresh);
    assert_true(Auth::requiresTwoFactorSetup(), 'без каналов доступ снова ограничен');

    assert_same('setup_required', Auth::attemptLogin('totp_admin', 'Str0ng-Pass!2026')['status']);
});

test('Секрет приложения хранится в базе зашифрованным', function () {
    $user = totp_admin_reset();
    $secret = TOTP::generateSecret();
    User::enableTotp((int) $user['id'], $secret);

    $stored = (string) \App\Core\Database::pdo()
        ->query('SELECT totp_secret FROM users WHERE username = ' . \App\Core\Database::pdo()->quote('totp_admin'))
        ->fetchColumn();
    assert_true($stored !== '' && $stored !== $secret, 'секрет не должен лежать открытым текстом');
    // Модель отдаёт расшифрованное значение — иначе проверка кода не сойдётся.
    assert_same($secret, (string) User::findById((int) $user['id'])['totp_secret']);

    \App\Core\Database::pdo()->prepare('DELETE FROM users WHERE username = ?')->execute(['totp_admin']);
});

test('Настройка приложения открыта ограниченной сессии', function () {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');

    assert_contains("'/admin/profile/totp/enable'", $routes);
    // Иначе админ без Telegram не дошёл бы до формы подключения.
    $guard = substr($routes, (int) strpos($routes, '$allowedSetupPaths'));
    assert_contains("'/admin/profile/totp/enable'", substr($guard, 0, 800));
});
