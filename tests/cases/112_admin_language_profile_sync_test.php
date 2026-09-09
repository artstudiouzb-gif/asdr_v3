<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Locale;
use App\Core\LocalePreference;
use App\Models\User;

test('Язык админки строго следует профилю авторизованного пользователя (БД)', function (): void {
    ensure_test_db();

    $users = User::all();
    if ($users === []) {
        return;
    }
    $user = $users[0];
    $userId = (int) $user['id'];

    // Эмулируем авторизацию
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|');

    // 1. Устанавливаем в профиле 'uz'
    User::updateAdminLang($userId, 'uz');
    $_SERVER['REQUEST_URI'] = '/admin/dashboard';
    unset($_GET['_lang']);

    Auth::requireLogin();
    assert_same('uz', Locale::current(), 'при входе в админку считывается admin_lang из профиля');

    // 2. Переключение языка в шапке админки (?_lang=ru) обновляет профиль в БД
    $_SERVER['REQUEST_URI'] = '/admin/dashboard?_lang=ru';
    $_GET['_lang'] = 'ru';

    Auth::requireLogin();
    assert_same('ru', Locale::current());
    $updatedUser = User::findById($userId);
    assert_same('ru', $updatedUser['admin_lang'], 'переключение в шапке обновляет admin_lang в БД');

    // 3. Сброс профиля ("системный по умолчанию")
    User::updateAdminLang($userId, null);
    unset($_GET['_lang']);
    $_SERVER['REQUEST_URI'] = '/admin/dashboard';

    Auth::requireLogin();
    assert_same(\App\Models\Language::defaultCode(), Locale::current());
});
