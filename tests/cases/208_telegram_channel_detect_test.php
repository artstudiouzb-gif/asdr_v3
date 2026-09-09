<?php

declare(strict_types=1);

use App\Core\TelegramBot;

/**
 * Определение id канала по getUpdates. У закрытого канала нет имени `@name` —
 * Telegram отвечает «chat not found», и числовой `-100…` приходится добывать
 * через сторонние боты. Бот видит свои каналы сам, как только стал админом.
 */

test('Каналы и супергруппы находятся, личная переписка — нет', function () {
    $channels = TelegramBot::matchChannels([
        ['channel_post' => ['chat' => ['id' => -1001234567890, 'title' => 'Черновик ASDR', 'type' => 'channel']]],
        ['message' => ['chat' => ['id' => 360874971, 'type' => 'private', 'first_name' => 'Админ']]],
        ['my_chat_member' => ['chat' => ['id' => -1009876543210, 'title' => 'Рабочая группа', 'type' => 'supergroup']]],
    ]);

    assert_same(2, count($channels));
    assert_same(-1001234567890, $channels[0]['id']);
    assert_same('Черновик ASDR', $channels[0]['title']);
    assert_same('supergroup', $channels[1]['type']);
    // Личный чат в списке не нужен: публикуют в канал, и id вида -100… только там.
    foreach ($channels as $chat) {
        assert_true($chat['id'] < 0, 'id канала отрицательный');
    }
});

test('Один канал в нескольких событиях — одна строка', function () {
    $channels = TelegramBot::matchChannels([
        ['my_chat_member' => ['chat' => ['id' => -100111, 'title' => 'Канал', 'type' => 'channel']]],
        ['channel_post' => ['chat' => ['id' => -100111, 'title' => 'Канал', 'type' => 'channel']]],
        ['channel_post' => ['chat' => ['id' => -100111, 'title' => 'Канал', 'type' => 'channel']]],
    ]);

    assert_same(1, count($channels));
});

test('Мусор в ответе не роняет разбор', function () {
    assert_same([], TelegramBot::matchChannels([]));
    assert_same([], TelegramBot::matchChannels([
        ['channel_post' => 'строка вместо объекта'],
        ['channel_post' => ['chat' => ['type' => 'channel']]],           // без id
        ['channel_post' => ['chat' => ['id' => 'abc', 'type' => 'channel']]], // id не число
        ['edited_message' => ['chat' => ['id' => -100222, 'type' => 'channel']]], // чужое событие
    ]));

    // Канал без названия всё равно годится — по id его и вставляют.
    $noTitle = TelegramBot::matchChannels([['channel_post' => ['chat' => ['id' => -100333, 'type' => 'channel']]]]);
    assert_same(1, count($noTitle));
    assert_contains('-100333', $noTitle[0]['title']);
});

test('Кнопка и маршрут определения канала на месте', function () {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/telegram/channel/detect'", $routes);

    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/telegram/index.php');
    assert_contains('/admin/telegram/channel/detect', $view);
    // Кнопка не должна требовать заполненного поля: её и жмут, когда id неизвестен.
    assert_contains('formnovalidate', $view);
});

test('Найденный ID возвращается в форму без автоматического сохранения', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/TelegramController.php');
    assert_contains('DETECTED_CHANNELS_SESSION_KEY', $controller);
    assert_contains("'detectedChannels' => \$detectedChannels", $controller);
    assert_contains('автоматически вставлен в поле «Канал»', $controller);
    assert_not_contains("Setting::set('social_telegram_chat_id', (string) \$chat['id'])", $controller);

    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/telegram/index.php');
    assert_contains("\$detectedChatId !== '' ? \$detectedChatId", $view);
    assert_contains('data-tg-use-channel-id', $view);

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains("closest('[data-tg-use-channel-id]')", $js);
    assert_contains("getElementById('tg_chat_id')", $js);
});
