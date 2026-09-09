<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\SocialPublisher;
use App\Models\SocialPost;

test('Telegram: ссылка стоит внутри своего языкового блока, а не в общем хвосте', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = json_decode($b, true);
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}'];
    };
    $post = [
        'message' => '', 'link' => 'https://site.uz/news/x',
        'langs' => [
            ['title' => 'Sarlavha', 'excerpt' => 'Qisqacha matn.', 'link' => 'https://site.uz/uz/news/x', 'read_more' => 'Saytda o‘qish →'],
            ['title' => 'Заголовок', 'excerpt' => 'Краткий текст.', 'link' => 'https://site.uz/news/x', 'read_more' => 'Читать на сайте →'],
        ],
    ];
    $sig = '🌐 <a href="https://site.uz">Сайт</a>';
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'signature' => $sig], $post);

    assert_true($res['ok']);
    $text = (string) $seen['text'];

    $uzLink = mb_strpos($text, 'https://site.uz/uz/news/x');
    $sepPos = mb_strpos($text, '———');
    $ruTitle = mb_strpos($text, 'Заголовок');
    $ruLink = mb_strpos($text, 'Читать на сайте');
    $sigPos = mb_strpos($text, '🌐');

    // Узбекская ссылка — до разделителя, русская — после своего заголовка.
    assert_true($uzLink < $sepPos, 'ссылка узбекской версии стоит в узбекском блоке');
    assert_true($sepPos < $ruTitle, 'разделитель перед русским блоком');
    assert_true($ruTitle < $ruLink, 'ссылка русской версии — после русского заголовка');
    // Подпись остаётся общим хвостом в самом конце.
    assert_true($ruLink < $sigPos, 'подпись — последней');
});

test('Telegram: одиночный язык не ломается (ссылка и подпись на месте)', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) {
        $seen = json_decode($b, true);
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}'];
    };
    (new SocialPublisher($http))->publish(
        'telegram',
        ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'signature' => 'подпись'],
        ['message' => 'Текст новости', 'link' => 'https://site.uz/news/x', 'title' => 'Заголовок']
    );

    $text = (string) $seen['text'];
    assert_contains('Заголовок', $text);
    assert_contains('https://site.uz/news/x', $text);
    assert_not_contains('———', $text, 'разделителя между блоками нет — язык один');
    assert_true(mb_strpos($text, 'https://site.uz/news/x') < mb_strpos($text, 'подпись'));
});

test('Telegram: длинный двуязычный текст доезжает целиком, ссылки на месте', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":[{"message_id":5}]}'];
    };
    $post = [
        'message' => '', 'link' => 'https://site.uz/news/x',
        'image_url' => 'https://site.uz/cover.jpg',
        'langs' => [
            ['title' => 'Sarlavha', 'excerpt' => str_repeat('u', 3000), 'link' => 'https://site.uz/uz/news/x', 'read_more' => 'Saytda o‘qish →'],
            ['title' => 'Заголовок', 'excerpt' => str_repeat('р', 3000), 'link' => 'https://site.uz/news/x', 'read_more' => 'Читать на сайте →'],
        ],
    ];
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'signature' => 'подпись'], $post);

    $text = (string) ($calls[1]['body']['text'] ?? '');
    assert_true(mb_strlen(strip_tags($text)) <= 4096, 'лимит обычного сообщения — 4096');
    assert_contains('Saytda o‘qish', $text);
    assert_contains('Читать на сайте', $text);
});

test('Повторная публикация: кнопка в админке отправляет заново, автопубликация — нет (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO news (title, slug, status, created_at) VALUES ('Repost', 'repost-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $newsId = (int) $pdo->lastInsertId();

    $status = static function (int $newsId) use ($pdo): array {
        $st = $pdo->prepare(
            'SELECT status, attempts, remote_id, last_error, sent_at, locked_until
             FROM social_posts WHERE news_id = :n AND network = :net'
        );
        $st->execute([':n' => $newsId, ':net' => 'telegram']);
        return $st->fetch() ?: [];
    };

    SocialPost::enqueue($newsId, 'telegram');
    assert_same('pending', $status($newsId)['status']);

    // Отправлено — обычная постановка в очередь больше не трогает запись,
    // иначе правка новости плодила бы посты в канале.
    $pdo->exec(
        "UPDATE social_posts SET status = 'sent', sent_at = NOW(), attempts = 1,
                remote_id = 'old-message', last_error = 'old-error',
                locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         WHERE news_id = {$newsId}"
    );
    SocialPost::enqueue($newsId, 'telegram');
    assert_same('sent', $status($newsId)['status'], 'автопубликация не переотправляет');

    // Явное «опубликовать заново» из админки возвращает запись в очередь.
    SocialPost::enqueue($newsId, 'telegram', true);
    $row = $status($newsId);
    assert_same('pending', $row['status'], 'кнопка публикации отправляет повторно');
    assert_same(0, (int) $row['attempts'], 'счётчик попыток сбрасывается');
    assert_same(null, $row['remote_id'], 'ID старого сообщения очищается');
    assert_same(null, $row['last_error'], 'старая ошибка очищается');
    assert_same(null, $row['sent_at'], 'дата старой отправки очищается');
    assert_same(null, $row['locked_until'], 'аренда старой попытки очищается');

    $pdo->exec("DELETE FROM social_posts WHERE news_id = {$newsId}");
    $pdo->exec("DELETE FROM news WHERE id = {$newsId}");
});

test('Кнопка публикации в админке вызывает enqueueForNews с force', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/NewsController.php');
    assert_contains("enqueueForNews((int) \$news['id'], \$only, true, \$scheduledAt)", $src);
    // А автопубликация при сохранении новости — без force: правки не должны
    // плодить посты. Четвёртым аргументом идёт время отложенной отправки.
    assert_contains('enqueueForNews(
                    $id,
                    null,
                    false,', $src);
});
