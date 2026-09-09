<?php

declare(strict_types=1);

use App\Core\SocialSettings;
use App\Models\Setting;

test('Telegram: публикация берёт токен бота, если отдельный не задан (БД)', function () {
    ensure_test_db();
    $botBackup = Setting::get('telegram_bot_token', '');
    $ownBackup = Setting::get('social_telegram_token', '');

    Setting::set('telegram_bot_token', '111111:MAIN_BOT_TOKEN_VALUE_FOR_TESTS_1');
    Setting::set('social_telegram_token', '');
    $cfg = SocialSettings::configFor('telegram');
    assert_same('111111:MAIN_BOT_TOKEN_VALUE_FOR_TESTS_1', $cfg['token'], 'один бот на вход и публикацию');

    // Отдельный бот для публикаций — если задан, он в приоритете.
    Setting::set('social_telegram_token', '222222:OWN_PUBLISHER_TOKEN_VALUE_TEST');
    $cfg = SocialSettings::configFor('telegram');
    assert_same('222222:OWN_PUBLISHER_TOKEN_VALUE_TEST', $cfg['token']);

    // Ни того ни другого — пусто, публикатор сам сообщит о незаполненных настройках.
    Setting::set('telegram_bot_token', '');
    Setting::set('social_telegram_token', '');
    $cfg = SocialSettings::configFor('telegram');
    assert_same('', $cfg['token']);

    Setting::set('telegram_bot_token', (string) $botBackup);
    Setting::set('social_telegram_token', (string) $ownBackup);
});

test('Настройки сайта не трогают ключи Telegram (иначе стёрли бы токен)', function () {
    // Поля Telegram убраны из формы «Настройки»; если ключи останутся в списке
    // сохраняемых, каждое сохранение настроек обнулит токен и выключит вход.
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/SettingsController.php');
    $keysBlock = substr($src, (int) strpos($src, 'TEXT_KEYS'), 400);

    assert_not_contains('telegram_bot_token', $keysBlock);
    assert_not_contains('telegram_gateway_token', $keysBlock);
    assert_not_contains('telegram_notify_chat_ids', $keysBlock);
});

test('Соцсети не сохраняют Telegram: у него свой раздел', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/SocialController.php');
    // Форма соцсетей идёт по formNetworks(), где Telegram исключён — иначе
    // пустой POST обнулил бы chat_id и подпись канала.
    assert_contains('formNetworks', $src);
    assert_contains("net !== 'telegram'", $src);

    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/settings/social.php');
    assert_contains('/admin/telegram', $view, 'из соцсетей есть ссылка в раздел Telegram');
});

test('Раздел Telegram доступен до настройки второго фактора', function () {
    // Замкнутый круг, на который наступили: сессия без привязанного Telegram
    // пускает только в разрешённые пути, а поле токена бота — то есть сам
    // канал доставки кода — живёт в разделе Telegram. Не будет его в списке —
    // администратор не сможет настроить второй фактор вообще.
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $pos = strpos($routes, '$allowedSetupPaths');
    assert_true($pos !== false, 'список разрешённых путей на месте');
    $line = substr($routes, (int) $pos, 700);

    assert_contains("'/admin/telegram'", $line);
    assert_contains("'/admin/profile'", $line);
    assert_contains("'/admin/logout'", $line);
    // Остальная админка при этом обязана оставаться закрытой.
    assert_not_contains("'/admin/news'", $line);
    assert_not_contains("'/admin/social'", $line);
});

test('Раздел Telegram: все шаги подключения на одной странице', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/telegram/index.php');
    foreach ([
        '/admin/telegram/bot',            // 1. токен
        '/admin/telegram/bot/check',      // проверка бота
        '/admin/telegram/link',           // 2. привязка и коды входа
        '/admin/telegram/channel',        // 3. канал
        '/admin/telegram/channel/check',  // проверка канала и прав
        '/admin/telegram/extras',         // 4. уведомления и Gateway
        '/admin/telegram/extras/check',   // тест доставки уведомлений
    ] as $action) {
        assert_contains($action, $view);
    }

    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_contains("'/admin/telegram'", $routes);
    assert_contains("'/admin/telegram/channel/check'", $routes);
    // Старые точки входа из раздела соцсетей убраны — один путь, а не два.
    assert_not_contains('/admin/social/check-telegram', $routes);
    assert_not_contains('/admin/social/use-login-bot-token', $routes);
});

test('Telegram: канал, подпись и админская страница проходят защитные проверки', function () {
    assert_same('', SocialSettings::normalizeTelegramChatId(''));
    assert_same('@agency_news', SocialSettings::normalizeTelegramChatId(' @agency_news '));
    assert_same('-1001234567890', SocialSettings::normalizeTelegramChatId('-1001234567890'));
    assert_true(SocialSettings::normalizeTelegramChatId('@bad') === null);
    assert_true(SocialSettings::normalizeTelegramChatId('123456') === null);

    assert_true(SocialSettings::telegramSignatureError(
        '<b>Новости</b> <blockquote expandable>Подробности</blockquote> '
        . '<a href="https://example.uz/news">на сайте</a>'
    ) === null);
    assert_true(SocialSettings::telegramSignatureError('<b>Незакрытый тег') !== null);
    assert_true(SocialSettings::telegramSignatureError('<script>alert(1)</script>') !== null);
    assert_true(SocialSettings::telegramSignatureError('<a href="https://">ссылка</a>') !== null);

    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/telegram/index.php');
    assert_not_contains('<script', $view, 'нет inline-скрипта');
    assert_not_contains('onclick=', $view, 'нет inline-обработчиков');
    assert_contains('data-tg-clear-token', $view);
    assert_contains('confirm_clear_bot', $view);
    assert_contains('settings-jump-nav', $view);
});
