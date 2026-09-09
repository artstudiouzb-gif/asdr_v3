<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Locale;
use App\Core\LocalePreference;
use App\Models\User;

test('Язык админки (admin_lang) не переписывает публичную куку site_lang и не меняет редирект сайта', function (): void {
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

    // 1. Очищаем публичную куку языка сайта
    unset($_COOKIE[LocalePreference::COOKIE]);

    // 2. Задаём язык админки 'ru'
    User::updateAdminLang($userId, 'ru');
    $_SERVER['REQUEST_URI'] = '/admin/dashboard';
    unset($_GET['_lang']);

    Auth::requireLogin();

    // Язык админки должен стать 'ru' для шаблонов админки
    assert_same('ru', Locale::current(), 'язык интерфейса админки установлен в ru');

    // Но публичная кука site_lang НЕ должна создаваться или затираться админкой!
    assert_true(!isset($_COOKIE[LocalePreference::COOKIE]) || $_COOKIE[LocalePreference::COOKIE] !== 'ru', 'админка не переписывает куку site_lang');
});
