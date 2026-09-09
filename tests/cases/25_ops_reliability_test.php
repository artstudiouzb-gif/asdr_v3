<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Heartbeat;
use App\Models\SocialPost;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

test('Heartbeat: touch/lastRun и определение «залипшего» воркера (группа 2.1)', function () {
    $name = 'testworker_' . bin2hex(random_bytes(3));
    // Нет файла → lastRun null.
    assert_same(null, Heartbeat::lastRun($name));

    Heartbeat::touch($name);
    $last = Heartbeat::lastRun($name);
    assert_true($last !== null && abs(time() - $last) < 5, 'lastRun должен быть ~сейчас');

    // Прямой доступ к статусу известных воркеров: свежий touch → не stale.
    Heartbeat::touch('mail');
    $status = Heartbeat::status();
    assert_true(isset($status['mail']), 'mail присутствует в статусе');
    assert_false($status['mail']['stale'], 'только что тронутый воркер не залип');

    // Никогда не запускавшийся воркер (нет файла) — не считается залипшим.
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    @unlink($root . '/storage/cache/worker_heartbeat_backup.txt');
    $status2 = Heartbeat::status();
    assert_false($status2['backup']['stale'], 'ни разу не запускавшийся воркер — не залип');

    @unlink($root . '/storage/cache/worker_heartbeat_' . $name . '.txt');
});

test('Dead-letter: вебхук помечается failed после исчерпания ретраев и виден в фильтре (БД, группа 2.2)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM webhook_deliveries');
    $pdo->exec('DELETE FROM webhooks');

    $hookId = Webhook::create('form.submitted', 'https://example.com/hook', 'secret', true);
    $delId = WebhookDelivery::enqueue($hookId, 'form.submitted', ['a' => 1]);

    // Три неудачи (MAX_ATTEMPTS=3) → статус failed.
    WebhookDelivery::markFailed($delId, 500, 'err1');
    WebhookDelivery::markFailed($delId, 500, 'err2');
    WebhookDelivery::markFailed($delId, 500, 'err3');

    $row = WebhookDelivery::find($delId);
    assert_same('failed', (string) $row['status'], 'после 3 неудач — failed');

    $failedOnly = WebhookDelivery::recent(30, 'failed');
    assert_same(1, count($failedOnly), 'фильтр failed возвращает запись');
    $sentOnly = WebhookDelivery::recent(30, 'sent');
    assert_same(0, count($sentOnly), 'среди sent записи нет');

    assert_true(WebhookDelivery::retryFailed($delId), 'failed-доставка возвращается в очередь');
    $retried = WebhookDelivery::find($delId);
    assert_same('pending', (string) $retried['status']);
    assert_same(0, (int) $retried['attempts']);
    assert_same(null, $retried['response_code']);
    assert_same(null, $retried['last_error']);
    assert_false(WebhookDelivery::retryFailed($delId), 'pending-доставка повторно не изменяется');

    $pdo->exec('DELETE FROM webhook_deliveries');
    $pdo->exec('DELETE FROM webhooks');
});

test('Dead-letter: соц-публикация помечается failed и попадает в recentFailed (БД, группа 2.2)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM social_posts');
    $pdo->exec('DELETE FROM news');

    $pdo->exec("INSERT INTO news (title, slug, status, created_at) VALUES ('Новость', 'n-dl', 'published', NOW())");
    $newsId = (int) $pdo->lastInsertId();

    SocialPost::enqueue($newsId, 'facebook');
    $spId = (int) $pdo->query('SELECT id FROM social_posts LIMIT 1')->fetchColumn();

    SocialPost::markFailed($spId, 'x1');
    SocialPost::markFailed($spId, 'x2');
    SocialPost::markFailed($spId, 'x3');

    $failed = SocialPost::recentFailed(10);
    assert_same(1, count($failed), 'провалившаяся публикация в списке dead-letter');
    assert_same('facebook', (string) $failed[0]['network']);

    assert_true(SocialPost::retryFailed($spId), 'failed-запись возвращается в очередь');
    $retried = SocialPost::forNews($newsId);
    assert_same('pending', (string) $retried[0]['status']);
    assert_same(0, (int) $retried[0]['attempts']);
    assert_same('', (string) ($retried[0]['last_error'] ?? ''));
    assert_false(SocialPost::retryFailed($spId), 'pending-запись повторно не изменяется');

    $pdo->exec('DELETE FROM social_posts');
    $pdo->exec('DELETE FROM news');
});
