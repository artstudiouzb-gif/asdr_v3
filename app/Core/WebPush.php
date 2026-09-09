<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Web Push без сторонних библиотек: VAPID (RFC 8292, ES256) + шифрование
 * полезной нагрузки aes128gcm (RFC 8291) на ext-openssl. Ключи VAPID
 * генерируются автоматически и хранятся в settings; публичный ключ отдаётся
 * фронтенду для PushManager.subscribe.
 *
 * HTTP-транспорт инжектируется (callable) — адаптер тестируем без сети.
 */
final class WebPush
{
    /** DER-префикс SubjectPublicKeyInfo для несжатой точки P-256. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var callable(string,string,string,array):array */
    private $http;

    /** @param callable|null $http fn(method,url,body,headers):array{status,body,error} */
    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? static fn (string $m, string $u, string $b, array $h) => Http::request($m, $u, $b, $h);
    }

    public static function isEnabled(): bool
    {
        return Setting::get('webpush_enabled', '0') === '1';
    }

    /** Публичный VAPID-ключ (base64url, для JS); генерирует пару при первом обращении. */
    public static function publicKey(): string
    {
        self::ensureKeys();

        return (string) Setting::get('webpush_vapid_public', '');
    }

    /** Создаёт и сохраняет пару VAPID-ключей P-256, если её ещё нет. */
    public static function ensureKeys(): void
    {
        if ((string) Setting::get('webpush_vapid_public', '') !== ''
            && (string) Setting::get('webpush_vapid_private', '') !== '') {
            return;
        }
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) {
            throw new \RuntimeException('Не удалось сгенерировать VAPID-ключи (openssl).');
        }
        openssl_pkey_export($res, $pem);
        $det = openssl_pkey_get_details($res);
        if ($det === false || !isset($det['ec']['x'], $det['ec']['y'])) {
            throw new \RuntimeException('Не удалось прочитать сгенерированные VAPID-ключи (openssl).');
        }
        $point = "\x04" . self::pad32((string) $det['ec']['x']) . self::pad32((string) $det['ec']['y']);

        Setting::set('webpush_vapid_private', (string) $pem);
        Setting::set('webpush_vapid_public', self::b64url($point));
    }

    /**
     * Отправляет уведомление на одну подписку.
     * gone=true — подписка мертва (404/410) и подлежит удалению.
     *
     * @param array{endpoint:string, p256dh:string, auth:string} $sub
     * @return array{ok:bool, status:int, gone:bool, error:?string}
     */
    public function send(array $sub, string $payload, int $ttl = 86400): array
    {
        $endpoint = (string) $sub['endpoint'];
        if (!preg_match('#^https://#', $endpoint)) {
            return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'endpoint не https'];
        }

        try {
            $body = self::encryptPayload($payload, (string) $sub['p256dh'], (string) $sub['auth']);
            $jwt = self::vapidJwt($endpoint);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => $e->getMessage()];
        }

        $res = ($this->http)('POST', $endpoint, $body, [
            'TTL: ' . $ttl,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($body),
            'Urgency: normal',
            'Authorization: vapid t=' . $jwt . ', k=' . self::publicKey(),
        ]);

        $status = (int) $res['status'];
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'gone' => in_array($status, [404, 410], true),
            'error' => $ok ? null : (($res['error'] ?? '') !== '' ? (string) $res['error'] : 'HTTP ' . $status),
        ];
    }

    // ===== RFC 8291: aes128gcm =====

    /**
     * Шифрует полезную нагрузку для подписчика (публичный ключ p256dh и
     * секрет auth — из PushSubscription). Возвращает готовое бинарное тело.
     */
    public static function encryptPayload(string $payload, string $p256dh, string $auth): string
    {
        $uaPublic = self::b64urlDecode($p256dh);
        $authSecret = self::b64urlDecode($auth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \RuntimeException('Некорректный p256dh подписки.');
        }
        if (strlen($authSecret) < 16) {
            throw new \RuntimeException('Некорректный auth подписки.');
        }

        // Эфемерная пара отправителя + ECDH с ключом браузера.
        // Отказ openssl здесь не проверялся вовсе: дальше ключ уходил в
        // openssl_pkey_get_details() и разбор деталей падал уже в глубине,
        // без внятной причины в логе.
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($eph === false) {
            throw new \RuntimeException('Не удалось сгенерировать эфемерный ключ отправителя (openssl).');
        }
        $det = openssl_pkey_get_details($eph);
        if ($det === false || !isset($det['ec']['x'], $det['ec']['y'])) {
            throw new \RuntimeException('Не удалось прочитать эфемерный ключ отправителя (openssl).');
        }
        $asPublic = "\x04" . self::pad32((string) $det['ec']['x']) . self::pad32((string) $det['ec']['y']);
        $peer = openssl_pkey_get_public(self::pointToPem($uaPublic));
        if ($peer === false) {
            throw new \RuntimeException('Не удалось разобрать публичный ключ подписки.');
        }
        $ecdh = openssl_pkey_derive($peer, $eph);
        if (!is_string($ecdh) || $ecdh === '') {
            throw new \RuntimeException('ECDH не удался.');
        }

        $salt = random_bytes(16);
        $prk = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\x00", $salt);

        // Единственная (последняя) запись: паддинг-разделитель 0x02.
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('AES-128-GCM не удался.');
        }

        // Заголовок aes128gcm: salt(16) | rs(4) | idlen(1) | keyid(65) | записи.
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    // ===== RFC 8292: VAPID =====

    /** JWT ES256 для пуш-сервиса endpoint'а (aud = origin). */
    public static function vapidJwt(string $endpoint): string
    {
        self::ensureKeys();
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        $sub = trim((string) Setting::get('contact_email', ''));
        $claims = [
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $sub !== '' ? 'mailto:' . $sub : 'mailto:admin@example.com',
        ];
        $input = self::b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64url((string) json_encode($claims));

        $priv = openssl_pkey_get_private((string) Setting::get('webpush_vapid_private', ''));
        if ($priv === false) {
            throw new \RuntimeException('VAPID-ключ повреждён.');
        }
        openssl_sign($input, $der, $priv, OPENSSL_ALGO_SHA256);

        return $input . '.' . self::b64url(self::derToRaw((string) $der));
    }

    /** DER-подпись ECDSA -> сырые r||s по 32 байта (формат JWS). */
    public static function derToRaw(string $der): string
    {
        $offset = 2; // SEQUENCE, len
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $offset++; // 0x02 INTEGER
            $len = ord($der[$offset]);
            $offset++;
            $int = ltrim(substr($der, $offset, $len), "\x00");
            $out .= self::pad32($int);
            $offset += $len;
        }

        return $out;
    }

    /** PEM публичного ключа из сырой несжатой точки P-256. */
    public static function pointToPem(string $point): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        $pad = strlen($data) % 4;

        return (string) base64_decode(strtr($data, '-_', '+/') . ($pad !== 0 ? str_repeat('=', 4 - $pad) : ''), true);
    }

    private static function pad32(string $bin): string
    {
        return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
    }
}
