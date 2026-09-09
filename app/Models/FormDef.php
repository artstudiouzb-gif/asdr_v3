<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class FormDef
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM forms ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['fields'] = json_decode((string) $row['fields_json'], true) ?: [];

        return $row;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM forms WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['fields'] = json_decode((string) $row['fields_json'], true) ?: [];

        return $row;
    }

    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM forms WHERE slug = :slug';
        $params = [':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO forms (name, slug, fields_json, notify_email, success_message, created_at)
             VALUES (:name, :slug, :fields_json, :notify_email, :success_message, NOW())'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':fields_json' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            ':notify_email' => $data['notify_email'],
            ':success_message' => $data['success_message'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE forms SET name = :name, slug = :slug, fields_json = :fields_json,
             notify_email = :notify_email, success_message = :success_message WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':fields_json' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            ':notify_email' => $data['notify_email'],
            ':success_message' => $data['success_message'],
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM forms WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
