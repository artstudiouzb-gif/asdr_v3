<?php

declare(strict_types=1);

use App\Core\WeeklyRoundup;

/**
 * Итоги недели одним постом в канал. Подписчик, пропустивший будни, получает
 * сводку со ссылками и не листает ленту назад.
 */

test('Сводка собирает новости недели по языкам (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();
    $now = new \DateTimeImmutable();

    $fresh = \App\Models\News::create([
        'title' => 'Свежая новость', 'slug' => 'rd-fresh-' . $suffix, 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published',
        'published_at' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
    ]);
    $old = \App\Models\News::create([
        'title' => 'Старая новость', 'slug' => 'rd-old-' . $suffix, 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published',
        'published_at' => $now->modify('-20 days')->format('Y-m-d H:i:s'),
    ]);
    $draft = \App\Models\News::create([
        'title' => 'Черновик', 'slug' => 'rd-draft-' . $suffix, 'excerpt' => 'А',
        'content' => 'т', 'status' => 'draft',
        'published_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
    ]);

    try {
        $slugs = array_map(
            static fn (array $i): string => (string) $i['slug'],
            WeeklyRoundup::collect(7, $now)['ru'] ?? []
        );

        assert_true(in_array('rd-fresh-' . $suffix, $slugs, true), 'новость недели попадает в сводку');
        assert_false(in_array('rd-old-' . $suffix, $slugs, true), 'прошлый месяц в сводку не тянем');
        assert_false(in_array('rd-draft-' . $suffix, $slugs, true), 'черновик не публикуем');
    } finally {
        $pdo->prepare('DELETE FROM news WHERE id IN (:a, :b, :c)')
            ->execute([':a' => $fresh, ':b' => $old, ':c' => $draft]);
    }
});

test('Пост — список ссылок с рубриками, по разделу на язык', function () {
    $now = new \DateTimeImmutable('2026-08-03 09:00:00');
    $html = WeeklyRoundup::buildHtml([
        'ru' => [['title' => 'Русский заголовок', 'slug' => 'ru-slug', 'badge' => 'Мероприятия']],
        'uz' => [['title' => 'Uzbek sarlavha', 'slug' => 'uz-slug', 'badge' => 'Tadbirlar']],
    ], $now);

    assert_contains('Итоги недели', $html);
    assert_contains('Hafta yakunlari', $html);
    assert_contains('/news/ru-slug', $html);
    // У неосновного языка адрес со своим префиксом.
    assert_contains('/uz/news/uz-slug', $html);
    assert_contains('<i>Мероприятия</i>', $html);
    assert_contains('———', $html, 'языки разделены, а не свалены в один список');

    // Разметка экранируется: заголовок пишет редактор.
    $evil = WeeklyRoundup::buildHtml(['ru' => [['title' => '<script>x</script>', 'slug' => 's', 'badge' => '']]], $now);
    assert_not_contains('<script>', $evil);

    // Пустая неделя — пустой пост, отправлять нечего.
    assert_same('', WeeklyRoundup::buildHtml([], $now));
});

test('Пустую неделю и повторную отправку пропускаем молча (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set(WeeklyRoundup::ENABLED_KEY, '0');
    \App\Models\Setting::set(WeeklyRoundup::LAST_SENT_KEY, '');

    try {
        // Выключено — ничего не делаем.
        $off = WeeklyRoundup::send();
        assert_false($off['sent']);
        assert_contains('выключена', $off['reason']);

        // Включено, но канал не настроен — тоже не отправляем.
        \App\Models\Setting::set(WeeklyRoundup::ENABLED_KEY, '1');
        \App\Models\Setting::set('social_telegram_enabled', '0');
        $noChannel = WeeklyRoundup::send();
        assert_false($noChannel['sent']);
        assert_contains('не настроен', $noChannel['reason']);

        // Уже отправляли на этой неделе — автоматический повтор не нужен.
        \App\Models\Setting::set(WeeklyRoundup::LAST_SENT_KEY, date('Y-m-d H:i:s'));
        \App\Models\Setting::set('social_telegram_enabled', '1');
        \App\Models\Setting::set('social_telegram_chat_id', '-1001234567890');
        \App\Models\Setting::set('telegram_bot_token', '123456:AAtest');
        $again = WeeklyRoundup::send();
        assert_false($again['sent']);
        assert_contains('уже отправлена', $again['reason']);
    } finally {
        foreach ([WeeklyRoundup::ENABLED_KEY, WeeklyRoundup::LAST_SENT_KEY,
                  'social_telegram_enabled', 'social_telegram_chat_id', 'telegram_bot_token'] as $key) {
            \App\Models\Setting::set($key, '');
        }
    }
});

test('Без обложки сводка уходит обычным сообщением', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":11}}'];
    };
    $res = (new \App\Core\SocialPublisher($http))->sendChannelMessage(
        ['token' => 'T', 'chat_id' => '-1001234567890'],
        '<b>Итоги недели</b>'
    );

    assert_true($res['ok']);
    assert_contains('/sendMessage', (string) $seen['url']);
    assert_same('HTML', (string) $seen['body']['parse_mode']);

    // Пустое сообщение не отправляем.
    assert_false((new \App\Core\SocialPublisher($http))->sendChannelMessage(['token' => 'T', 'chat_id' => 'c'], '  ')['ok']);
});

test('Короткая сводка уходит подписью к обложке, длинная — двумя сообщениями', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":12}}'];
    };
    $cfg = ['token' => 'T', 'chat_id' => '-1001234567890'];
    $cover = 'https://site.uz/uploads/public/collage.jpg';

    (new \App\Core\SocialPublisher($http))->sendChannelMessage($cfg, '<b>Итоги недели</b>', $cover);
    assert_same(1, count($calls), 'короткий список — одно сообщение с картинкой');
    assert_contains('/sendPhoto', (string) $calls[0]['url']);
    assert_same($cover, (string) $calls[0]['body']['photo']);
    assert_contains('Итоги недели', (string) $calls[0]['body']['caption']);

    // Список из полутора десятков ссылок в подпись не влезает: обложка
    // уходит первой, текст следом — резать список ради картинки нельзя.
    $calls = [];
    $long = '<b>Итоги</b> ' . str_repeat('очень длинная строка сводки ', 60);
    (new \App\Core\SocialPublisher($http))->sendChannelMessage($cfg, $long, $cover);
    assert_same(2, count($calls));
    assert_contains('/sendPhoto', (string) $calls[0]['url']);
    assert_contains('/sendMessage', (string) $calls[1]['url']);
    assert_contains('очень длинная строка', (string) $calls[1]['body']['text']);

    // Адрес не по https Telegram не заберёт — отправляем текстом, без обложки.
    $calls = [];
    (new \App\Core\SocialPublisher($http))->sendChannelMessage($cfg, '<b>Итоги</b>', 'http://site.uz/a.jpg');
    assert_same(1, count($calls));
    assert_contains('/sendMessage', (string) $calls[0]['url']);
});

test('Сводка отправляется кнопкой, а не расписанием', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/telegram/index.php');
    assert_contains('name="telegram_roundup"', $view);
    assert_contains('/admin/telegram/roundup/send', $view);
    assert_contains('telegram_roundup_image', $view, 'обложку задаёт редактор');
    // Крон для сводки не нужен: момент отправки выбирает человек.
    assert_not_contains('weekly_roundup.php', $view);
    assert_false(is_file(APP_ROOT . '/app/Console/weekly_roundup.php'));

    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/telegram/roundup/send'", $routes);

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/TelegramController.php');
    assert_contains('public function sendRoundup', $controller);
    assert_contains('WeeklyRoundup::ENABLED_KEY', $controller);
});

test('Кнопка отправляет и при выключенной сводке, и повторно (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set(WeeklyRoundup::ENABLED_KEY, '0');
    \App\Models\Setting::set(WeeklyRoundup::LAST_SENT_KEY, date('Y-m-d H:i:s'));
    \App\Models\Setting::set('social_telegram_enabled', '1');
    \App\Models\Setting::set('social_telegram_chat_id', '-1001234567890');
    \App\Models\Setting::set('telegram_bot_token', '123456:AAtest');

    $newsId = \App\Models\News::create([
        'title' => 'Для кнопки', 'slug' => 'rd-btn-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $http = fn ($m, $u, $b, $h) => ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":13}}'];
        $forced = WeeklyRoundup::send(null, new \App\Core\SocialPublisher($http), true);
        assert_true($forced['sent'], 'нажатие человека сильнее расписания и защиты от повторов');

        // Но и кнопка не отправляет пустую сводку: берём неделю, в которой
        // новостей заведомо не было.
        $empty = WeeklyRoundup::send(new \DateTimeImmutable('2020-01-15 09:00:00'), new \App\Core\SocialPublisher($http), true);
        assert_false($empty['sent']);
        assert_contains('новостей не было', $empty['reason']);
    } finally {
        \App\Core\Database::pdo()->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
        foreach ([WeeklyRoundup::ENABLED_KEY, WeeklyRoundup::LAST_SENT_KEY,
                  'social_telegram_enabled', 'social_telegram_chat_id', 'telegram_bot_token'] as $key) {
            \App\Models\Setting::set($key, '');
        }
    }
});

test('Обложка: своя картинка, иначе общая для соцсетей, иначе ничего (БД)', function () {
    ensure_test_db();
    // Адрес сайта в CLI берётся из конфигурации: относительный путь без него
    // не превратить в https-ссылку, которую заберёт Telegram.
    $appConfig = (array) \App\Core\Config::get('app', []);
    \App\Core\Config::merge(['app' => ['url' => 'https://site.uz'] + $appConfig]);
    \App\Models\Setting::set(WeeklyRoundup::COVER_KEY, '');
    \App\Models\Setting::set('default_og_image', '');

    try {
        assert_same('', WeeklyRoundup::coverUrl(), 'нет картинки — пост уйдёт без неё');

        \App\Models\Setting::set('default_og_image', '/uploads/public/og.jpg');
        assert_same('https://site.uz/uploads/public/og.jpg', WeeklyRoundup::coverUrl());

        // Своя обложка важнее общей.
        \App\Models\Setting::set(WeeklyRoundup::COVER_KEY, '/uploads/public/collage.jpg');
        assert_same('https://site.uz/uploads/public/collage.jpg', WeeklyRoundup::coverUrl());
    } finally {
        \App\Core\Config::merge(['app' => $appConfig]);
        \App\Models\Setting::set(WeeklyRoundup::COVER_KEY, '');
        \App\Models\Setting::set('default_og_image', '');
    }
});
