<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Очередь доставок вебхуков (задача 136). Обрабатывается CLI-воркером
 * (app/Console/webhook_worker.php) с ретраями.
 */
final class WebhookDelivery
{
    private const MAX_ATTEMPTS = 3;

    public static function enqueue(int $webhookId, string $event, array $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('Payload вебхука не удалось преобразовать в JSON.');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO webhook_deliveries (webhook_id, event_type, payload_json, status, created_at)
             VALUES (:w, :e, :p, :s, NOW())'
        );
        $stmt->execute([
            ':w' => $webhookId,
            ':e' => $event,
            ':p' => $json,
            ':s' => 'pending',
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function pendingBatch(int $limit = 20): array
    {
        // Гонко-безопасная выборка: FOR UPDATE SKIP LOCKED + аренда строк,
        // чтобы параллельные воркеры не дублировали обработку (QueueClaim).
        return \App\Core\QueueClaim::batch('webhook_deliveries', self::MAX_ATTEMPTS, $limit);
    }

    public static function markSent(int $id, int $responseCode): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE webhook_deliveries SET status = 'sent', sent_at = NOW(), attempts = attempts + 1,
                    response_code = :rc, last_error = NULL, locked_until = NULL WHERE id = :id"
        );
        $stmt->execute([':rc' => $responseCode, ':id' => $id]);
    }

    public static function markFailed(int $id, int $responseCode, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE webhook_deliveries
             SET attempts = attempts + 1, response_code = :rc, last_error = :err,
                 status = IF(attempts + 1 >= :max, 'failed', 'pending')
             WHERE id = :id"
        );
        $stmt->bindValue(':rc', $responseCode, PDO::PARAM_INT);
        $stmt->bindValue(':err', mb_substr($error, 0, 500));
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Dead-letter (группа 2.2): если исчерпаны ретраи и запись перешла в
        // failed — алертим один раз (после этого воркер её больше не берёт).
        $row = self::find($id);
        if ($row !== null && (string) $row['status'] === 'failed') {
            \App\Core\Logger::warning('Вебхук не доставлен после всех попыток (dead-letter)', [
                'delivery_id' => $id,
                'webhook_id' => (int) ($row['webhook_id'] ?? 0),
                'event' => (string) ($row['event_type'] ?? ''),
                'response_code' => $responseCode,
                'error' => mb_substr($error, 0, 200),
            ]);
        }
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM webhook_deliveries WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Возвращает failed-доставку в очередь для ручной повторной попытки. */
    public static function retryFailed(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE webhook_deliveries
             SET status = 'pending', attempts = 0, response_code = NULL,
                 last_error = NULL, locked_until = NULL, sent_at = NULL
             WHERE id = :id AND status = 'failed'"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Отключённый получатель не восстановится от автоматического ретрая:
     * сразу переносим такую доставку в failed вместо трёх бесполезных запусков.
     */
    public static function markTerminalFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE webhook_deliveries
             SET status = 'failed', attempts = :max, response_code = 0,
                 last_error = :err, locked_until = NULL
             WHERE id = :id"
        );
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':err', mb_substr($error, 0, 500));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<int, array<string, mixed>> последние доставки для лога в админке */
    public static function recent(int $limit = 50, ?string $status = null): array
    {
        $where = '';
        if ($status !== null && in_array($status, ['failed', 'pending', 'sent'], true)) {
            $where = 'WHERE d.status = :status';
        }
        $stmt = Database::pdo()->prepare(
            'SELECT d.*, w.url AS webhook_url FROM webhook_deliveries d
             LEFT JOIN webhooks w ON w.id = d.webhook_id
             ' . $where . '
             ORDER BY d.created_at DESC LIMIT :limit'
        );
        if ($where !== '') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array{pending:int,sent:int,failed:int} */
    public static function counts(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        $rows = Database::pdo()->query(
            'SELECT status, COUNT(*) AS c FROM webhook_deliveries GROUP BY status'
        )->fetchAll();
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $out)) {
                $out[$status] = (int) $row['c'];
            }
        }

        return $out;
    }
}
