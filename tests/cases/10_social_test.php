<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\SocialPublisher;
use App\Core\SocialSettings;
use App\Models\SocialPost;

// --- Юнит-тесты адаптеров с поддельным HTTP-транспортом (без реальных сетей) ---

test('Social: Facebook публикует и возвращает remote_id', function () {
    $calls = [];
    $http = function ($method, $url, $body, $headers) use (&$calls) {
        $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        return ['status' => 200, 'body' => '{"id":"page_1_2"}', 'error' => ''];
    };
    $pub = new SocialPublisher($http);
    $res = $pub->publish('facebook', ['token' => 'T', 'page_id' => 'PAGE'], ['message' => 'Hi', 'link' => 'https://x/y']);
    assert_true($res['ok']);
    assert_same('page_1_2', $res['remote_id']);
    assert_contains('/PAGE/feed', $calls[0]['url']);
    assert_contains('access_token=T', $calls[0]['body']);
});

test('Social: ошибка Graph возвращает сообщение', function () {
    $http = fn ($m, $u, $b, $h) => ['status' => 400, 'body' => '{"error":{"message":"Invalid token"}}', 'error' => ''];
    $res = (new SocialPublisher($http))->publish('facebook', ['token' => 'T', 'page_id' => 'P'], ['message' => 'x', 'link' => 'https://x']);
    assert_false($res['ok']);
    assert_same('Invalid token', $res['error']);
});

test('Social: LinkedIn шлёт Bearer и ugcPosts', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'headers' => $h, 'body' => $b];
        return ['status' => 201, 'body' => '{"id":"urn:li:share:99"}', 'error' => ''];
    };
    $res = (new SocialPublisher($http))->publish('linkedin', ['token' => 'LT', 'author' => 'urn:li:organization:5'], ['message' => 'Post', 'link' => 'https://x/a']);
    assert_true($res['ok']);
    assert_same('urn:li:share:99', $res['remote_id']);
    assert_contains('/v2/ugcPosts', $seen['url']);
    assert_true(in_array('Authorization: Bearer LT', $seen['headers'], true));
    assert_contains('urn:li:organization:5', $seen['body']);
});

test('Social: Instagram — двухшаговая публикация', function () {
    $step = 0;
    $http = function ($m, $u, $b, $h) use (&$step) {
        $step++;
        if ($step === 1) { return ['status' => 200, 'body' => '{"id":"CONTAINER"}', 'error' => '']; }
        return ['status' => 200, 'body' => '{"id":"MEDIA_9"}', 'error' => ''];
    };
    $res = (new SocialPublisher($http))->publish('instagram', ['token' => 'T', 'user_id' => 'IG'], ['message' => 'x', 'link' => 'https://x', 'image_url' => 'https://x/i.jpg']);
    assert_same(2, $step, 'должно быть два запроса');
    assert_true($res['ok']);
    assert_same('MEDIA_9', $res['remote_id']);
});

test('Social: Instagram без картинки — ошибка', function () {
    $res = (new SocialPublisher())->publish('instagram', ['token' => 'T', 'user_id' => 'IG'], ['message' => 'x', 'link' => 'https://x', 'image_url' => '']);
    assert_false($res['ok']);
    assert_contains('изображение', $res['error']);
});

test('SocialSettings::buildPost собирает message/link/абсолютный image', function () {
    // app.url задан в bootstrap теста как http://localhost.
    $post = SocialSettings::buildPost(['slug' => 'hello', 'title' => 'Заголовок', 'excerpt' => 'Кратко', 'image' => '/uploads/public/c.jpg']);
    assert_contains('http://localhost/news/hello', $post['link']);
    assert_contains('Заголовок', $post['message']);
    assert_contains('Кратко', $post['message']);
    assert_same('http://localhost/uploads/public/c.jpg', $post['image_url']);
});

test('SocialSettings: конфигурация платформ проверяет ID, URN и длину подписи', function () {
    assert_same('123456789', SocialSettings::normalizeConfigField('facebook', 'page_id', ' 123456789 '));
    assert_true(SocialSettings::normalizeConfigField('facebook', 'page_id', 'page-name') === null);
    assert_same(
        'urn:li:organization:123',
        SocialSettings::normalizeConfigField('linkedin', 'author', 'urn:li:organization:123')
    );
    assert_true(SocialSettings::normalizeConfigField('linkedin', 'author', 'organization:123') === null);
    assert_same('987654321', SocialSettings::normalizeConfigField('instagram', 'user_id', '987654321'));
    assert_true(SocialSettings::normalizeConfigField('instagram', 'user_id', 'IG-1') === null);
    assert_true(SocialSettings::normalizeConfigField('instagram', 'signature', str_repeat('x', 2001)) === null);

    $errors = SocialSettings::configurationErrors('facebook', [
        'token' => '',
        'page_id' => 'not-id',
        'signature' => '',
    ]);
    assert_true(count($errors) >= 2, 'должны быть ошибки обязательного токена и Page ID');
});

// --- Очередь (нужна тестовая БД) ---

test('SocialPost: очередь — enqueue/pending/markSent/markFailed', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $nid = (int) ($pdo->query("INSERT INTO news (title,slug,status) VALUES ('N','soc-" . bin2hex(random_bytes(3)) . "','published')") !== false ? $pdo->lastInsertId() : 0);

    SocialPost::enqueue($nid, 'facebook');
    SocialPost::enqueue($nid, 'facebook'); // повтор не создаёт дубликат
    $rows = SocialPost::forNews($nid);
    assert_same(1, count($rows));

    $batch = SocialPost::pendingBatch(10);
    $mine = array_values(array_filter($batch, fn ($r) => (int) $r['news_id'] === $nid));
    assert_same(1, count($mine));

    SocialPost::markSent((int) $mine[0]['id'], 'REMOTE_1');
    $after = SocialPost::forNews($nid);
    assert_same('sent', $after[0]['status']);
    assert_same('REMOTE_1', $after[0]['remote_id']);
});

// --- Telegram-канал ---

test('Social: Telegram без фото — sendMessage с HTML-подписью и ссылкой', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'body' => $b];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":42}}', 'error' => ''];
    };
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'BOT:T', 'chat_id' => '@channel', 'format' => 'classic'], [
        'message' => "Заголовок\n\nАнонс новости", 'link' => 'https://site/news/a', 'title' => 'Заголовок',
    ]);
    assert_true($res['ok']);
    assert_same('42', $res['remote_id']);
    assert_contains('/sendMessage', $seen['url']);
    // Токен идёт в URL как есть: двоеточие НЕ кодируется, иначе Bot API → 404.
    assert_contains('/botBOT:T/sendMessage', $seen['url']);
    assert_true(!str_contains($seen['url'], '%3A'), 'двоеточие токена не должно быть закодировано');
    $payload = json_decode($seen['body'], true);
    assert_same('@channel', $payload['chat_id']);
    assert_same('HTML', $payload['parse_mode']);
    assert_contains('<b>Заголовок</b>', $payload['text']);
    assert_contains('Анонс новости', $payload['text']);
    assert_contains('href="https://site/news/a"', $payload['text']);
});

test('Social: Telegram с одним фото — sendPhoto', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'body' => $b];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":7}}', 'error' => ''];
    };
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '-100123', 'format' => 'classic', 'show_caption_above_media' => '1'], [
        'message' => 'Т', 'link' => 'https://site/news/b', 'title' => 'Т', 'image_url' => 'https://site/i.jpg',
    ]);
    assert_true($res['ok']);
    assert_contains('/sendPhoto', $seen['url']);
    $payload = json_decode($seen['body'], true);
    assert_same('https://site/i.jpg', $payload['photo']);
    assert_true($payload['show_caption_above_media'] === true, 'подпись отправлена НАД фото');
    assert_contains('href="https://site/news/b"', $payload['caption']);
});

test('Social: Telegram с галереей — sendMediaGroup, подпись у первого фото, максимум 10', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'body' => $b];
        return ['status' => 200, 'body' => '{"ok":true,"result":[{"message_id":11},{"message_id":12}]}', 'error' => ''];
    };
    $gallery = [];
    for ($i = 1; $i <= 12; $i++) {
        $gallery[] = "https://site/g{$i}.jpg";
    }
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@ch', 'format' => 'classic'], [
        'message' => 'Заг', 'link' => 'https://site/news/c', 'title' => 'Заг',
        'image_url' => 'https://site/cover.jpg', 'gallery' => $gallery,
    ]);
    assert_true($res['ok']);
    assert_same('11', $res['remote_id']);
    assert_contains('/sendMediaGroup', $seen['url']);
    $payload = json_decode($seen['body'], true);
    assert_same(10, count($payload['media']), 'лимит Bot API — 10 фото');
    assert_same('https://site/cover.jpg', $payload['media'][0]['media']);
    assert_contains('href="https://site/news/c"', $payload['media'][0]['caption']);
    assert_true(empty($payload['media'][1]['caption']), 'подпись только у первого элемента');
});

test('Social: Telegram — ошибка Bot API возвращает description', function () {
    $http = fn ($m, $u, $b, $h) => ['status' => 400, 'body' => '{"ok":false,"description":"Bad Request: chat not found"}', 'error' => ''];
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@x', 'format' => 'classic'], ['message' => 'x', 'link' => 'https://x']);
    assert_false($res['ok']);
    assert_contains('chat not found', $res['error']);
});

test('Social: Telegram без настроек — ошибка конфигурации', function () {
    $res = (new SocialPublisher())->publish('telegram', ['token' => '', 'chat_id' => '', 'format' => 'classic'], ['message' => 'x', 'link' => 'https://x']);
    assert_false($res['ok']);
    assert_contains('chat_id', $res['error']);
});
