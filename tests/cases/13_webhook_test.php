<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\WebhookDispatcher;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

test('Webhook: HMAC-подпись стабильна и в формате sha256=', function () {
    $sig = WebhookDispatcher::sign('{"a":1}', 'secret');
    assert_true(str_starts_with($sig, 'sha256='));
    assert_same('sha256=' . hash_hmac('sha256', '{"a":1}', 'secret'), $sig);
});

test('Webhook: deliver подписывает и трактует 2xx как успех', function () {
    $seen = [];
    $http = function ($url, $body, $headers) use (&$seen) {
        $seen = ['url' => $url, 'body' => $body, 'headers' => $headers];
        return ['status' => 200, 'body' => 'ok', 'error' => ''];
    };
    $res = WebhookDispatcher::deliver(
        ['payload_json' => '{"event":"x"}'],
        ['url' => 'https://93.184.216.34/hook', 'secret' => 'S'],
        $http
    );
    assert_true($res['ok']);
    assert_same(200, $res['code']);
    $hasSig = false;
    foreach ($seen['headers'] as $h) {
        if (str_starts_with($h, WebhookDispatcher::SIGNATURE_HEADER . ':')) { $hasSig = true; }
    }
    assert_true($hasSig, 'подпись должна быть в заголовках');
});

test('Webhook: deliver трактует 5xx как ошибку', function () {
    // Даже если тестовый транспорт не вернул ключ error, диспетчер не должен
    // создавать PHP warning и использует HTTP-код.
    $http = fn ($u, $b, $h) => ['status' => 500, 'body' => 'err'];
    $res = WebhookDispatcher::deliver(['payload_json' => '{}'], ['url' => 'https://93.184.216.34/x', 'secret' => null], $http);
    assert_false($res['ok']);
    assert_same(500, $res['code']);
    assert_same('HTTP 500', $res['error']);
});

test('Webhook: проверка подключения отправляет отдельное событие webhook.test', function () {
    $seen = [];
    $http = function ($url, $body, $headers) use (&$seen) {
        $seen = json_decode($body, true);
        return ['status' => 204, 'body' => '', 'error' => ''];
    };
    $result = WebhookDispatcher::test([
        'url' => 'https://93.184.216.34/test',
        'secret' => '0123456789abcdef',
        'event_type' => 'news.published',
    ], $http);

    assert_true($result['ok']);
    assert_same('webhook.test', (string) ($seen['event'] ?? ''));
    assert_same('news.published', (string) ($seen['data']['configured_event'] ?? ''));
});

test('Webhook: SSRF — приватный/loopback URL блокируется на доставке', function () {
    $called = false;
    $http = function ($u, $b, $h) use (&$called) { $called = true; return ['status' => 200, 'body' => '', 'error' => '']; };
    $res = WebhookDispatcher::deliver(['payload_json' => '{}'], ['url' => 'http://127.0.0.1/x', 'secret' => null], $http);
    assert_false($res['ok']);
    assert_false($called, 'запрос на loopback не должен выполняться');
});

test('Webhook: dispatch ставит доставки только активным подписчикам события (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM webhook_deliveries');
    $pdo->exec('DELETE FROM webhooks');

    $encryptedHookId = Webhook::create('form.submitted', 'https://93.184.216.34/a', 's1', true);
    Webhook::create('form.submitted', 'https://93.184.216.34/b', null, false); // выключен
    Webhook::create('news.published', 'https://93.184.216.34/c', null, true);  // другое событие

    $rawSecret = (string) $pdo->query('SELECT secret FROM webhooks WHERE id = ' . $encryptedHookId)->fetchColumn();
    assert_true(str_starts_with($rawSecret, 'enc:v1:'), 'секрет webhook зашифрован в БД');
    assert_same('s1', Webhook::findById($encryptedHookId)['secret']);
    $adminRows = Webhook::all();
    $adminRow = array_values(array_filter(
        $adminRows,
        static fn (array $row): bool => (int) $row['id'] === $encryptedHookId
    ))[0] ?? [];
    assert_true(!array_key_exists('secret', $adminRow), 'список админки не расшифровывает секреты');
    assert_same(1, (int) ($adminRow['secret_configured'] ?? 0));

    $n = WebhookDispatcher::dispatch('form.submitted', ['form' => 'contact']);
    assert_same(1, $n, 'только один активный подписчик form.submitted');

    $pending = WebhookDelivery::pendingBatch(10);
    assert_same(1, count($pending));
    assert_same('form.submitted', $pending[0]['event_type']);
    assert_contains('contact', (string) $pending[0]['payload_json']);
});

test('Webhook: отключённый получатель завершается без трёх бесполезных запусков (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM webhook_deliveries');
    $pdo->exec('DELETE FROM webhooks');

    $hookId = Webhook::create('news.published', 'https://93.184.216.34/off', null, false);
    $deliveryId = WebhookDelivery::enqueue($hookId, 'news.published', ['id' => 1]);
    $result = WebhookDispatcher::processQueue(1);
    $row = WebhookDelivery::find($deliveryId);

    assert_false($result['busy']);
    assert_same(1, $result['failed']);
    assert_same('failed', (string) ($row['status'] ?? ''));
    assert_same(3, (int) ($row['attempts'] ?? 0));
    assert_contains('отключён', (string) ($row['last_error'] ?? ''));

    $pdo->exec('DELETE FROM webhook_deliveries');
    $pdo->exec('DELETE FROM webhooks');
});
