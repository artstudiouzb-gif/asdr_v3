<?php

declare(strict_types=1);

use App\Core\WebPush;
use App\Models\Setting;
use App\Models\WebPushSubscription;

// Web Push: base64url, PEM из точки, VAPID JWT, шифрование RFC 8291
// (полный раундтрип с расшифровкой «на стороне браузера»), очередь.

test('WebPush: b64url кодирует/декодирует без потерь', function () {
    $bin = random_bytes(37);
    assert_same($bin, WebPush::b64urlDecode(WebPush::b64url($bin)));
    assert_true(!str_contains(WebPush::b64url($bin), '='), 'без паддинга');
});

test('WebPush::pointToPem даёт валидный публичный ключ', function () {
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($res === false) {
        skip_test('OpenSSL в этом окружении не поддерживает EC prime256v1');
    }
    $det = openssl_pkey_get_details($res);
    $point = "\x04" . str_pad((string) $det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad((string) $det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $pub = openssl_pkey_get_public(WebPush::pointToPem($point));
    assert_true($pub !== false, 'PEM разобран openssl');
});

test('WebPush: ensureKeys создаёт пару, publicKey — 65-байтовая точка', function () {
    ensure_test_db();
    Setting::set('webpush_vapid_public', '');
    Setting::set('webpush_vapid_private', '');

    WebPush::ensureKeys();
    $pub = WebPush::b64urlDecode(WebPush::publicKey());
    assert_same(65, strlen($pub));
    assert_same("\x04", $pub[0]);
    assert_contains('PRIVATE KEY', (string) Setting::get('webpush_vapid_private', ''));

    // Повторный вызов не перегенерирует.
    $before = WebPush::publicKey();
    WebPush::ensureKeys();
    assert_same($before, WebPush::publicKey());
});

test('WebPush::encryptPayload — раундтрип: браузер расшифровывает aes128gcm', function () {
    // «Браузер»: своя пара P-256 + auth-секрет.
    $browser = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($browser === false) {
        skip_test('OpenSSL в этом окружении не поддерживает EC prime256v1');
    }
    $det = openssl_pkey_get_details($browser);
    $uaPublic = "\x04" . str_pad((string) $det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad((string) $det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $authSecret = random_bytes(16);

    $payload = '{"title":"Тест","url":"/news/x"}';
    $body = WebPush::encryptPayload($payload, WebPush::b64url($uaPublic), WebPush::b64url($authSecret));

    // Разбор заголовка aes128gcm: salt(16) | rs(4) | idlen(1) | keyid.
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    assert_same(65, $idlen);
    $asPublic = substr($body, 21, 65);
    $cipherAndTag = substr($body, 21 + 65);

    // ECDH со стороны браузера: его приватный ключ + публичный ключ сервера.
    $serverPub = openssl_pkey_get_public(WebPush::pointToPem($asPublic));
    $ecdh = openssl_pkey_derive($serverPub, $browser);
    $prk = hash_hkdf('sha256', (string) $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\x00", $salt);

    $tag = substr($cipherAndTag, -16);
    $cipher = substr($cipherAndTag, 0, -16);
    $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    assert_true($plain !== false, 'AES-GCM расшифрован');
    assert_same($payload . "\x02", (string) $plain, 'полезная нагрузка + разделитель 0x02');
});

test('WebPush::vapidJwt — подпись ES256 проверяется публичным ключом', function () {
    ensure_test_db();
    WebPush::ensureKeys();
    $jwt = WebPush::vapidJwt('https://fcm.googleapis.com/fcm/send/abc');
    [$h, $c, $s] = explode('.', $jwt);

    $claims = json_decode(WebPush::b64urlDecode($c), true);
    assert_same('https://fcm.googleapis.com', $claims['aud']);
    assert_true($claims['exp'] > time(), 'exp в будущем');

    // raw r||s -> DER и верификация openssl.
    $raw = WebPush::b64urlDecode($s);
    assert_same(64, strlen($raw));
    $derInt = static function (string $bin): string {
        $bin = ltrim($bin, "\x00");
        if ((ord($bin[0]) & 0x80) !== 0) {
            $bin = "\x00" . $bin;
        }
        return "\x02" . chr(strlen($bin)) . $bin;
    };
    $seq = $derInt(substr($raw, 0, 32)) . $derInt(substr($raw, 32));
    $der = "\x30" . chr(strlen($seq)) . $seq;
    $pub = openssl_pkey_get_public(WebPush::pointToPem(WebPush::b64urlDecode(WebPush::publicKey())));
    assert_same(1, openssl_verify($h . '.' . $c, $der, $pub, OPENSSL_ALGO_SHA256));
});

test('WebPush::send шлёт vapid-заголовки; 410 помечает подписку мёртвой', function () {
    ensure_test_db();
    WebPush::ensureKeys();

    $browser = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]));
    $p256dh = WebPush::b64url("\x04" . str_pad((string) $browser['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad((string) $browser['ec']['y'], 32, "\x00", STR_PAD_LEFT));
    $sub = ['endpoint' => 'https://push.example/send/1', 'p256dh' => $p256dh, 'auth' => WebPush::b64url(random_bytes(16))];

    $seen = [];
    $push = new WebPush(function ($m, $u, $b, $h) use (&$seen) {
        $seen = ['url' => $u, 'headers' => $h, 'body' => $b];
        return ['status' => 201, 'body' => '', 'error' => ''];
    });
    $res = $push->send($sub, '{"title":"x"}');
    assert_true($res['ok']);
    assert_false($res['gone']);
    $headers = implode("\n", $seen['headers']);
    assert_contains('Content-Encoding: aes128gcm', $headers);
    assert_contains('Authorization: vapid t=', $headers);
    assert_contains('k=' . WebPush::publicKey(), $headers);

    $gonePush = new WebPush(fn ($m, $u, $b, $h) => ['status' => 410, 'body' => '', 'error' => '']);
    $res = $gonePush->send($sub, '{}');
    assert_false($res['ok']);
    assert_true($res['gone'], '410 — подписка мертва');
});

test('WebPushSubscription: сохранение, дубликаты, очередь новостей', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec("DELETE FROM webpush_subscriptions");
    $pdo->exec("DELETE FROM webpush_queue");

    assert_true(WebPushSubscription::save('https://push.example/e1', 'PK', 'AU'));
    assert_true(WebPushSubscription::save('https://push.example/e1', 'PK2', 'AU2'), 'повтор обновляет ключи');
    assert_false(WebPushSubscription::save('http://insecure/e2', 'PK', 'AU'), 'только https');
    assert_same(1, WebPushSubscription::count());

    WebPushSubscription::deleteByEndpoint('https://push.example/e1');
    assert_same(0, WebPushSubscription::count());

    $pdo->exec("INSERT INTO news (title, slug, status, published_at) VALUES ('P', 'test-push-q', 'published', NOW())");
    $nid = (int) $pdo->lastInsertId();
    WebPushSubscription::enqueueNews($nid);
    WebPushSubscription::enqueueNews($nid); // дубликат игнорируется
    $q = WebPushSubscription::pendingQueue(10);
    $mine = array_values(array_filter($q, fn ($r) => (int) $r['news_id'] === $nid));
    assert_same(1, count($mine));

    WebPushSubscription::markQueueSent((int) $mine[0]['id']);
    assert_same(0, count(array_filter(WebPushSubscription::pendingQueue(10), fn ($r) => (int) $r['news_id'] === $nid)));

    $pdo->exec("DELETE FROM news WHERE id = {$nid}");
    $pdo->exec("DELETE FROM webpush_queue WHERE news_id = {$nid}");
});
