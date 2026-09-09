<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Webhook;
use App\Models\WebhookDelivery;

/**
 * Диспетчер исходящих вебхуков (задача 136). При наступлении события ставит
 * доставки в очередь для всех активных подписанных вебхуков. Доставка (воркером)
 * подписывается HMAC-SHA256 по секрету вебхука. HTTP-транспорт инжектируется
 * для тестов.
 */
final class WebhookDispatcher
{
    public const SIGNATURE_HEADER = 'X-ASDR-Signature';

    /**
     * Ставит событие в очередь доставки для всех активных вебхуков.
     * @param array<string, mixed> $data
     * @return int число поставленных доставок
     */
    public static function dispatch(string $event, array $data): int
    {
        $payload = [
            'event' => $event,
            'timestamp' => gmdate('c'),
            'data' => $data,
        ];

        $count = 0;
        try {
            foreach (Webhook::activeForEvent($event) as $hook) {
                WebhookDelivery::enqueue((int) $hook['id'], $event, $payload);
                $count++;
            }
        } catch (\Throwable $e) {
            Logger::error('Webhook dispatch failed: ' . $e->getMessage());
        }

        return $count;
    }

    /** Подпись тела запроса: "sha256=<hex hmac>". */
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Отправляет одну доставку. Возвращает результат для воркера.
     *
     * @param array<string,mixed> $delivery строка webhook_deliveries
     * @param array<string,mixed> $webhook строка webhooks
     * @param callable|null $http fn(url,body,headers):array{status,body,error}
     * @return array{ok:bool, code:int, error:string}
     */
    public static function deliver(array $delivery, array $webhook, ?callable $http = null): array
    {
        $url = (string) ($webhook['url'] ?? '');
        // Защита от SSRF: не шлём на приватные/loopback адреса.
        if (!UrlGuard::isSafeRemote($url)) {
            return ['ok' => false, 'code' => 0, 'error' => 'URL небезопасен или недоступен для внешнего запроса.'];
        }

        $body = (string) $delivery['payload_json'];
        $headers = ['Content-Type: application/json', 'User-Agent: ASDR-CMS-Webhook'];
        if (!empty($webhook['secret'])) {
            $headers[] = self::SIGNATURE_HEADER . ': ' . self::sign($body, (string) $webhook['secret']);
        }

        $send = $http ?? static fn (string $u, string $b, array $h) => Http::requestSafeRemote(
            'POST',
            $u,
            $b,
            $h,
            15,
            1024 * 1024
        );
        $res = $send($url, $body, $headers);

        $code = (int) ($res['status'] ?? 0);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'code' => $code, 'error' => ''];
        }

        $error = trim((string) ($res['error'] ?? ''));

        return ['ok' => false, 'code' => $code, 'error' => $error !== '' ? $error : ('HTTP ' . $code)];
    }

    /**
     * Обрабатывает очередь с той же межпроцессной блокировкой, что и Cron.
     *
     * @return array{sent:int,failed:int,taken:int,busy:bool}
     */
    public static function processQueue(int $limit = 20): array
    {
        $lock = ProcessLock::acquire('webhook_worker');
        if ($lock === null) {
            return ['sent' => 0, 'failed' => 0, 'taken' => 0, 'busy' => true];
        }

        try {
            $batch = WebhookDelivery::pendingBatch(max(1, min(50, $limit)));
            $sent = 0;
            $failed = 0;
            foreach ($batch as $delivery) {
                $id = (int) $delivery['id'];
                $webhook = Webhook::findById((int) $delivery['webhook_id']);
                if ($webhook === null || (int) $webhook['is_active'] !== 1) {
                    WebhookDelivery::markTerminalFailed($id, 'Получатель удалён или отключён.');
                    $failed++;
                    continue;
                }

                $result = self::deliver($delivery, $webhook);
                if ($result['ok']) {
                    WebhookDelivery::markSent($id, $result['code']);
                    $sent++;
                } else {
                    WebhookDelivery::markFailed($id, $result['code'], $result['error']);
                    $failed++;
                }
            }

            return [
                'sent' => $sent,
                'failed' => $failed,
                'taken' => count($batch),
                'busy' => false,
            ];
        } finally {
            ProcessLock::release($lock);
        }
    }

    /**
     * Отправляет безопасное тестовое событие напрямую, не создавая запись
     * реального события и не запуская бизнес-действия у получателя.
     *
     * @param array<string,mixed> $webhook
     * @return array{ok:bool,code:int,error:string}
     */
    public static function test(array $webhook, ?callable $http = null): array
    {
        $body = json_encode([
            'event' => 'webhook.test',
            'timestamp' => gmdate('c'),
            'data' => [
                'configured_event' => (string) ($webhook['event_type'] ?? ''),
                'message' => 'Проверка подключения ASDR CMS',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::deliver(
            ['payload_json' => is_string($body) ? $body : '{}'],
            $webhook,
            $http
        );
    }
}
