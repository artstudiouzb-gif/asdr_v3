<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Подписчики email-дайджеста новостей. Токен — для ссылки отписки в письме
 * (без входа в аккаунт). Рассылку выполняет app/Console/digest_worker.php.
 */
final class Subscriber
{
    private const SOURCES = ['block', 'footer', 'news', 'widget', 'website', 'admin'];

    /**
     * Подписывает адрес. Возвращает: 'ok' — добавлен, 'exists' — уже был,
     * 'invalid' — некорректный email.
     */
    public static function subscribe(
        string $email,
        ?string $lang = null,
        string $source = 'website',
        bool $consent = false
    ): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        $activeLangs = Language::activeCodes();
        $lang = strtolower(trim((string) $lang));
        if (!in_array($lang, $activeLangs, true)) {
            $lang = Language::defaultCode();
        }
        $source = in_array($source, self::SOURCES, true) ? $source : 'website';
        try {
            return Database::transaction(static function (\PDO $pdo) use ($email, $lang, $source, $consent): string {
                $find = $pdo->prepare('SELECT id, status FROM subscribers WHERE email = :email FOR UPDATE');
                $find->execute([':email' => $email]);
                $existing = $find->fetch();

                if ($existing) {
                    $active = (string) ($existing['status'] ?? 'active') === 'active';
                    $update = $pdo->prepare(
                        "UPDATE subscribers
                         SET lang = :lang,
                             source = :source,
                             consent_at = IF(:consent = 1, COALESCE(consent_at, NOW()), consent_at),
                             status = 'active',
                             unsubscribed_at = NULL,
                             token = IF(:reactivate = 1, :token, token)
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':lang' => $lang,
                        ':source' => $source,
                        ':consent' => $consent ? 1 : 0,
                        ':reactivate' => $active ? 0 : 1,
                        ':token' => bin2hex(random_bytes(24)),
                        ':id' => (int) $existing['id'],
                    ]);

                    return $active ? 'exists' : 'ok';
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO subscribers
                        (email, token, status, lang, source, consent_at, created_at, updated_at)
                     VALUES
                        (:email, :token, 'active', :lang, :source, :consent_at, NOW(), NOW())"
                );
                $stmt->execute([
                    ':email' => $email,
                    ':token' => bin2hex(random_bytes(24)),
                    ':lang' => $lang,
                    ':source' => $source,
                    ':consent_at' => $consent ? date('Y-m-d H:i:s') : null,
                ]);

                return 'ok';
            });
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return 'exists'; // параллельная вставка того же уникального email
            }
            throw $exception;
        }
    }

    public static function unsubscribeByToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return false;
        }
        return Database::transaction(static function (\PDO $pdo) use ($token): bool {
            $stmt = $pdo->prepare(
                "UPDATE subscribers
                 SET status = 'unsubscribed', unsubscribed_at = NOW()
                 WHERE token = :token AND status = 'active'"
            );
            $stmt->execute([':token' => $token]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $find = $pdo->prepare('SELECT id FROM subscribers WHERE token = :token LIMIT 1');
            $find->execute([':token' => $token]);
            $subscriberId = (int) $find->fetchColumn();
            $cancel = $pdo->prepare(
                "UPDATE mail_queue
                 SET status = 'cancelled', locked_until = NULL,
                     last_error = 'Получатель отписался до отправки'
                 WHERE subscriber_id = :subscriber_id
                   AND purpose = 'digest'
                   AND status = 'pending'"
            );
            $cancel->execute([':subscriber_id' => $subscriberId]);

            return true;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM subscribers ORDER BY created_at DESC, id DESC'
        )->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM subscribers WHERE status = 'active'"
        )->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(): array
    {
        return Database::pdo()->query(
            "SELECT * FROM subscribers
             WHERE status = 'active'
             ORDER BY lang ASC, id ASC"
        )->fetchAll();
    }

    public static function isActive(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM subscribers WHERE id = :id AND status = 'active'"
        );
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function markDigestQueued(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE subscribers SET last_digest_at = NOW()
             WHERE id = :id AND status = 'active'"
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'unsubscribed'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }
        $lang = strtolower(trim((string) ($filters['lang'] ?? '')));
        if ($lang !== '') {
            $where[] = 'lang = :lang';
            $params[':lang'] = $lang;
        }
        $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 120);
        if ($query !== '') {
            $where[] = 'email LIKE :query';
            $params[':query'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        }
        $suffix = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = Database::pdo()->prepare('SELECT COUNT(*) FROM subscribers' . $suffix);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = max(10, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, status, lang, source, consent_at, unsubscribed_at,
                    last_digest_at, created_at
             FROM subscribers' . $suffix .
            ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** @return array{total:int,active:int,unsubscribed:int} */
    public static function summary(): array
    {
        $row = Database::pdo()->query(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(status = 'unsubscribed') AS unsubscribed
             FROM subscribers"
        )->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
        ];
    }

    public static function delete(int $id): void
    {
        Database::transaction(static function (\PDO $pdo) use ($id): void {
            $cancel = $pdo->prepare(
                "UPDATE mail_queue
                 SET status = 'cancelled', locked_until = NULL,
                     last_error = 'Получатель удалён до отправки'
                 WHERE subscriber_id = :id AND purpose = 'digest' AND status = 'pending'"
            );
            $cancel->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM subscribers WHERE id = :id')->execute([':id' => $id]);
        });
    }
}
