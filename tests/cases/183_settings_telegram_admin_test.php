<?php

declare(strict_types=1);

use App\Core\Icon;

test('Настройки: после сохранения очищается публичный кэш и поля ограничены', function () {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/SettingsController.php');
    $view = (string) file_get_contents($root . '/app/Views/admin/settings/index.php');

    assert_contains("Cache::forgetPrefix('page:')", $controller);
    assert_contains('SettingsValidator::smtpHost', $controller);
    assert_contains('SettingsValidator::optionalEmail', $controller);
    assert_contains('id="settings-site"', $view);
    assert_contains('id="settings-maintenance"', $view);
    assert_contains('maxlength="320"', $view);
    assert_contains('max="65535"', $view);
    assert_contains('settings-jump-nav', $view);
});

test('Telegram: открытие страницы не вызывает внешний API, проверка выполняется явно', function () {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/TelegramController.php');
    $indexStart = strpos($controller, 'public function index(): void');
    $indexEnd = strpos($controller, '/** Шаг 1:', (int) $indexStart);
    assert_true($indexStart !== false && $indexEnd !== false);
    $indexMethod = substr($controller, (int) $indexStart, (int) $indexEnd - (int) $indexStart);

    assert_not_contains('TelegramBot::getMe(', $indexMethod);
    assert_contains('telegram_bot_verified_at', $indexMethod);
    assert_contains('getMeWithToken', $controller);
    assert_contains('normalizeTelegramChatId', $controller);
    assert_contains('confirm_clear_bot', $controller);
});

test('Telegram: новые действия зарегистрированы и недоступны ограниченной 2FA-сессии', function () {
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_contains("'/admin/telegram/extras/check'", $routes);

    assert_true(!\App\Core\Auth::twoFactorSetupAllows('/admin/telegram/extras/check'));
    assert_true(!\App\Core\Auth::twoFactorSetupAllows('/admin/telegram/channel/check'));
});

test('Telegram: локальная иконка бренда доступна через единый алиас', function () {
    assert_same('brand-telegram', Icon::resolvedName('telegram'));
});
