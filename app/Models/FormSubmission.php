<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class FormSubmission
{
    /**
     * @return array<int,array{total:int,unread:int}> статистика по form_id
     */
    public static function countsByForm(): array
    {
        $rows = Database::pdo()->query(
            'SELECT form_id, COUNT(*) AS total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
             FROM form_submissions GROUP BY form_id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['form_id']] = [
                'total' => (int) $row['total'],
                'unread' => (int) $row['unread'],
            ];
        }

        return $out;
    }

    public static function forForm(int $formId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM form_submissions WHERE form_id = :form_id ORDER BY created_at DESC'
        );
        $stmt->execute([':form_id' => $formId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['data'] = json_decode((string) $row['data_json'], true) ?: [];
        }

        return $rows;
    }

    /**
     * Общий журнал заявок с фильтрами и пагинацией.
     *
     * @param array{form_id?:int,status?:string,q?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        $formId = (int) ($filters['form_id'] ?? 0);
        if ($formId > 0) {
            $where[] = 'fs.form_id = :form_id';
            $params[':form_id'] = $formId;
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'unread') {
            $where[] = 'fs.is_read = 0';
        } elseif ($status === 'read') {
            $where[] = 'fs.is_read = 1';
        }
        $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 120);
        if ($query !== '') {
            $where[] = 'fs.data_json LIKE :q';
            $params[':q'] = '%' . $query . '%';
        }

        $suffix = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM form_submissions fs' . $suffix);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $perPage = max(10, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT fs.*, f.name AS form_name, f.fields_json
             FROM form_submissions fs
             INNER JOIN forms f ON f.id = fs.form_id'
            . $suffix .
            ' ORDER BY fs.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            self::decodeRow($item);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{total:int,unread:int,read:int} */
    public static function summary(?int $formId = null): array
    {
        $sql = 'SELECT COUNT(*) AS total,
                       SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
                       SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS `read`
                FROM form_submissions';
        $params = [];
        if ($formId !== null && $formId > 0) {
            $sql .= ' WHERE form_id = :form_id';
            $params[':form_id'] = $formId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
            'read' => (int) ($row['read'] ?? 0),
        ];
    }

    public static function findWithForm(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT fs.*, f.name AS form_name, f.fields_json
             FROM form_submissions fs
             INNER JOIN forms f ON f.id = fs.form_id
             WHERE fs.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        self::decodeRow($row);

        return $row;
    }

    public static function countUnread(int $formId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = :form_id AND is_read = 0'
        );
        $stmt->execute([':form_id' => $formId]);

        return (int) $stmt->fetchColumn();
    }

    public static function create(int $formId, array $data, ?string $ip, ?string $userAgent): int
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('Данные заявки не удалось преобразовать в JSON.');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO form_submissions (form_id, data_json, ip_address, user_agent, created_at)
             VALUES (:form_id, :data_json, :ip, :user_agent, NOW())'
        );
        $stmt->execute([
            ':form_id' => $formId,
            ':data_json' => $json,
            ':ip' => $ip,
            ':user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function markRead(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE form_submissions SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM form_submissions WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Удаляет заявки старше $days дней (GDPR-очистка ПДн, группа 6).
     * $days <= 0 — очистка отключена (хранить бессрочно). Возвращает число
     * удалённых записей.
     */
    public static function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $stmt = Database::pdo()->prepare(
            'DELETE FROM form_submissions WHERE created_at < (NOW() - INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $row */
    private static function decodeRow(array &$row): void
    {
        $row['data'] = json_decode((string) ($row['data_json'] ?? ''), true) ?: [];
        $row['fields'] = json_decode((string) ($row['fields_json'] ?? ''), true) ?: [];
    }
}
