<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Очередь авто-публикаций новости в соцсети. Одна строка = одна публикация
 * в одну сеть. Обрабатывается CLI-воркером (app/Console/social_worker.php).
 */
final class SocialPost
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Ставит публикацию в очередь.
     *
     * По умолчанию идемпотентна по паре news_id+network: уже отправленная
     * запись остаётся 'sent', иначе правка новости плодила бы посты в канале.
     * $force — явное «опубликовать заново» из админки: человек нажал кнопку
     * осознанно, и в канал уйдёт ещё одно сообщение.
     */
    /**
     * @param string|null $scheduledAt «Y-m-d H:i:s» — не отправлять раньше
     *     этого времени; null — при ближайшем запуске воркера.
     */
    public static function enqueue(int $newsId, string $network, bool $force = false, ?string $scheduledAt = null): void
    {
        $stmt = Database::pdo()->prepare(
            $force
                ? "INSERT INTO social_posts (news_id, network, status, scheduled_at, created_at)
                   VALUES (:nid, :net, 'pending', :sched, NOW())
                   ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0,
                      remote_id = NULL, last_error = NULL, sent_at = NULL,
                      locked_until = NULL, scheduled_at = VALUES(scheduled_at), created_at = NOW()"
                : "INSERT INTO social_posts (news_id, network, status, scheduled_at, created_at)
                   VALUES (:nid, :net, 'pending', :sched, NOW())
                   ON DUPLICATE KEY UPDATE
                      status = IF(status = 'sent', 'sent', 'pending'),
                      attempts = IF(status = 'sent', attempts, 0),
                      last_error = IF(status = 'sent', last_error, NULL),
                      locked_until = IF(status = 'sent', locked_until, NULL),
                      scheduled_at = IF(status = 'sent', scheduled_at, VALUES(scheduled_at))"
        );
        $stmt->execute([':nid' => $newsId, ':net' => $network, ':sched' => $scheduledAt]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function pendingBatch(int $limit = 20): array
    {
        // Гонко-безопасная выборка: FOR UPDATE SKIP LOCKED + аренда строк,
        // чтобы параллельные воркеры не дублировали обработку (QueueClaim).
        return \App\Core\QueueClaim::batch('social_posts', self::MAX_ATTEMPTS, $limit);
    }

    public static function markSent(int $id, ?string $remoteId): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE social_posts SET status = 'sent', sent_at = NOW(), attempts = attempts + 1,
                    remote_id = :rid, last_error = NULL, locked_until = NULL WHERE id = :id"
        );
        $stmt->execute([':rid' => $remoteId, ':id' => $id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE social_posts
             SET attempts = attempts + 1,
                 last_error = :error,
                 status = IF(attempts + 1 >= :max, 'failed', 'pending')
             WHERE id = :id"
        );
        $stmt->bindValue(':error', mb_substr($error, 0, 500));
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Dead-letter (группа 2.2): переход в failed после исчерпания ретраев —
        // алертим один раз.
        $check = Database::pdo()->prepare('SELECT news_id, network, status FROM social_posts WHERE id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        $row = $check->fetch();
        if ($row && (string) $row['status'] === 'failed') {
            \App\Core\Logger::warning('Автопубликация в соцсеть не удалась после всех попыток (dead-letter)', [
                'social_post_id' => $id,
                'news_id' => (int) ($row['news_id'] ?? 0),
                'network' => (string) ($row['network'] ?? ''),
                'error' => mb_substr($error, 0, 200),
            ]);
        }
    }

    /** Возвращает dead-letter запись в очередь для ручной повторной попытки. */
    public static function retryFailed(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE social_posts
             SET status = 'pending', attempts = 0, last_error = NULL, locked_until = NULL
             WHERE id = :id AND status = 'failed'"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<int, array<string, mixed>> статусы публикаций новости */
    public static function forNews(int $newsId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM social_posts WHERE news_id = :nid ORDER BY network');
        $stmt->execute([':nid' => $newsId]);

        return $stmt->fetchAll();
    }

    /**
     * Лента последних публикаций всех статусов — для журнала очереди в админке
     * (что делает воркер: что ушло, что ждёт, что упало и почему).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 40): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT sp.*, n.title AS news_title FROM social_posts sp
             LEFT JOIN news n ON n.id = sp.news_id
             ORDER BY COALESCE(sp.sent_at, sp.created_at) DESC, sp.id DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Сводка статуса публикации в соцсети для списка новостей (без N+1).
     * По каждому news_id: сколько ушло/в очереди/с ошибкой, когда последний
     * раз публиковалось и в какие сети.
     *
     * @param array<int, int> $newsIds
     * @return array<int, array{sent:int, pending:int, failed:int, last_sent:?string, networks:array<int,string>, scheduled:?string}>
     */
    public static function statusForNewsIds(array $newsIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $newsIds))));
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT news_id, network, status, sent_at, scheduled_at FROM social_posts
             WHERE news_id IN ($in)"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $nid = (int) $row['news_id'];
            if (!isset($out[$nid])) {
                $out[$nid] = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'last_sent' => null, 'networks' => [], 'scheduled' => null];
            }
            $status = (string) $row['status'];
            if ($status === 'sent') {
                $out[$nid]['sent']++;
                $out[$nid]['networks'][] = (string) $row['network'];
                $sentAt = $row['sent_at'] ?? null;
                if ($sentAt !== null && ($out[$nid]['last_sent'] === null || $sentAt > $out[$nid]['last_sent'])) {
                    $out[$nid]['last_sent'] = (string) $sentAt;
                }
            } elseif ($status === 'failed') {
                $out[$nid]['failed']++;
            } else {
                $out[$nid]['pending']++;
                // Ближайшее запланированное время: редактор должен видеть в
                // списке, что пост не «завис», а ждёт своего часа.
                $scheduled = $row['scheduled_at'] ?? null;
                if ($scheduled !== null && ($out[$nid]['scheduled'] === null || $scheduled < $out[$nid]['scheduled'])) {
                    $out[$nid]['scheduled'] = (string) $scheduled;
                }
            }
        }

        return $out;
    }

    /**
     * Счётчики очереди по статусам (для сводки в шапке журнала).
     *
     * @return array{pending: int, sent: int, failed: int}
     */
    public static function counts(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        $rows = Database::pdo()->query('SELECT status, COUNT(*) AS c FROM social_posts GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $out)) {
                $out[$status] = (int) $row['c'];
            }
        }

        return $out;
    }

    /**
     * Публикации, «застрявшие» в failed после исчерпания ретраев (группа 2.2).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentFailed(int $limit = 30): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT sp.*, n.title AS news_title FROM social_posts sp
             LEFT JOIN news n ON n.id = sp.news_id
             WHERE sp.status = 'failed'
             ORDER BY sp.created_at DESC, sp.id DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
