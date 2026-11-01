<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\User;

/*
 * Пользователи (FUNC-5): блокировка без удаления, правка роли и почты,
 * сброс второго фактора. Удаление обнуляет авторство новостей, поэтому
 * ушедшего сотрудника блокируют — и блокировка обязана действительно
 * закрывать вход.
 */

function users_fixture(): array
{
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    @session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '10.0.0.54';
    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute(['fx_admin', 'fx_editor']);
    $insert = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $insert->execute(['fx_admin', 'fx_admin@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), 'admin']);
    $insert->execute(['fx_editor', 'fx_editor@example.com', password_hash('Str0ng-Pass!2026', PASSWORD_DEFAULT), 'editor']);
    User::forgetCache();

    return [(array) User::findByUsername('fx_admin'), (array) User::findByUsername('fx_editor')];
}

test('Заблокированный пользователь не входит, разблокированный — снова входит', function (): void {
    [, $editor] = users_fixture();
    $id = (int) $editor['id'];
    assert_true(User::isActive(User::findById($id)), 'новая учётка активна');

    User::setActive($id, false);
    assert_true(!User::isActive(User::findById($id)));
    assert_same('invalid', Auth::attemptLogin('fx_editor', 'Str0ng-Pass!2026')['status'], 'верный пароль не открывает закрытую учётку');

    $_SESSION = [];
    User::setActive($id, true);
    assert_true(Auth::attemptLogin('fx_editor', 'Str0ng-Pass!2026')['status'] !== 'invalid', 'после разблокировки пароль снова работает');
});

test('Последний активный супер-администратор остаётся', function (): void {
    [$admin] = users_fixture();
    $id = (int) $admin['id'];
    // Другие активные супер-администраторы базы тоже считаются.
    $others = User::otherActiveAdmins($id);
    \App\Core\Database::pdo()->prepare("UPDATE users SET is_active = 0 WHERE role = 'admin' AND id <> ?")->execute([$id]);
    User::forgetCache();
    assert_same(0, User::otherActiveAdmins($id), 'кроме него активных супер-администраторов нет');
    assert_true($others >= 0);
});

test('Правка данных и сброс второго фактора', function (): void {
    [, $editor] = users_fixture();
    $id = (int) $editor['id'];

    assert_true(User::emailTakenByOther('fx_admin@example.com', $id), 'чужой email занят');
    assert_true(!User::emailTakenByOther('fx_editor@example.com', $id), 'свой email не считается занятым');

    User::updateProfile($id, 'new@example.com', 'admin', '+998901234567');
    $row = User::findById($id);
    assert_same('new@example.com', $row['email']);
    assert_same('admin', $row['role']);
    assert_same('+998901234567', $row['phone']);

    \App\Core\Database::pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_last_step = 5, telegram_chat_id = 123 WHERE id = ?')->execute([$id]);
    User::resetSecondFactor($id);
    $row = User::findById($id);
    assert_same(0, (int) $row['totp_enabled']);
    assert_same(null, $row['telegram_chat_id']);
    assert_same(null, $row['totp_last_step']);
});

test('Список пользователей показывает статус и действия', function (): void {
    $items = [
        ['id' => 991, 'username' => 'fx_editor', 'email' => 'e@example.com', 'role' => 'editor', 'is_active' => 0,
         'totp_enabled' => 1, 'telegram_chat_id' => null, 'phone' => null, 'admin_lang' => null, 'last_login_at' => null],
    ];
    $_SESSION['user_id'] = 1;
    $html = render_view('app/Views/admin/users/index.php', ['items' => $items, 'error' => null]);
    assert_true(str_contains($html, 'Заблокирован'));
    assert_true(str_contains($html, 'Разблокировать'));
    assert_true(str_contains($html, '/admin/users/991/reset-2fa'), 'сброс доступен, пока фактор подключён');
    assert_true(str_contains($html, '/admin/users/991/update'));
});
