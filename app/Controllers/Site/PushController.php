<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\WebPush;
use App\Models\WebPushSubscription;

/**
 * Публичные эндпоинты webpush: публичный VAPID-ключ и регистрация/удаление
 * подписки браузера (JSON, вызывается из assets/js/push.js).
 */
final class PushController
{
    public function key(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (!WebPush::isEnabled()) {
            http_response_code(404);
            echo '{"error":"disabled"}';
            exit;
        }
        echo json_encode(['key' => WebPush::publicKey(), 'csrf_token' => Csrf::token()]);
        exit;
    }

    public function subscribe(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (!WebPush::isEnabled()) {
            http_response_code(404);
            echo '{"error":"disabled"}';
            exit;
        }
        if (!$this->allowMutation()) {
            return;
        }
        $data = json_decode((string) file_get_contents('php://input'), true);
        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');

        if (!WebPushSubscription::save($endpoint, $p256dh, $auth)) {
            http_response_code(422);
            echo '{"error":"invalid subscription"}';
            exit;
        }
        echo '{"ok":true}';
        exit;
    }

    public function unsubscribe(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (!$this->allowMutation()) {
            return;
        }
        $data = json_decode((string) file_get_contents('php://input'), true);
        $endpoint = (string) ($data['endpoint'] ?? '');
        if ($endpoint !== '') {
            WebPushSubscription::deleteByEndpoint($endpoint);
        }
        echo '{"ok":true}';
        exit;
    }

    private function allowMutation(): bool
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!str_starts_with($contentType, 'application/json') || !Csrf::verify($token)) {
            http_response_code(419);
            echo '{"error":"csrf"}';
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::throttle('push_subscription', $ip, 20, 10)) {
            http_response_code(429);
            header('Retry-After: 600');
            echo '{"error":"rate_limit"}';
            return false;
        }
        return true;
    }
}
