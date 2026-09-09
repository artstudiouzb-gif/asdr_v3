<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Категория файлового хранилища (портал /repo). Один уровень вложенности:
 * категория → подкатегории (parent_id). Удаление родителя каскадно удаляет
 * подкатегории; файлы при удалении категории остаются без категории (SET NULL).
 */
final class RepoCategory
{
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM repo_categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Дерево: корневые категории по алфавиту, у каждой — 'children'
     * (по алфавиту) и 'files_count' (для children — своё).
     *
     * @return list<array>
     */
    public static function tree(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM repo_categories ORDER BY name ASC, id ASC')->fetchAll();
        $counts = [];
        foreach (Database::pdo()->query(
            'SELECT category_id, COUNT(*) AS cnt FROM repo_files WHERE category_id IS NOT NULL GROUP BY category_id'
        )->fetchAll() as $r) {
            $counts[(int) $r['category_id']] = (int) $r['cnt'];
        }

        $roots = [];
        $children = [];
        foreach ($rows as $row) {
            $row['files_count'] = $counts[(int) $row['id']] ?? 0;
            $row['children'] = [];
            if ($row['parent_id'] === null) {
                $roots[(int) $row['id']] = $row;
            } else {
                $children[(int) $row['parent_id']][] = $row;
            }
        }
        foreach ($children as $pid => $kids) {
            if (isset($roots[$pid])) {
                $roots[$pid]['children'] = $kids;
            }
        }

        return array_values($roots);
    }

    /**
     * Плоский список для селектов и фильтров: корни, за каждым — его
     * подкатегории. label подкатегории включает родителя («Родитель / Дочка»).
     *
     * @return list<array{id:int, parent_id:?int, name:string, label:string, files_count:int}>
     */
    public static function flatOptions(): array
    {
        $out = [];
        foreach (self::tree() as $root) {
            $out[] = [
                'id' => (int) $root['id'],
                'parent_id' => null,
                'name' => (string) $root['name'],
                'label' => (string) $root['name'],
                'files_count' => (int) $root['files_count'],
            ];
            foreach ($root['children'] as $child) {
                $out[] = [
                    'id' => (int) $child['id'],
                    'parent_id' => (int) $root['id'],
                    'name' => (string) $child['name'],
                    'label' => $root['name'] . ' / ' . $child['name'],
                    'files_count' => (int) $child['files_count'],
                ];
            }
        }

        return $out;
    }

    /** Создаёт категорию; parent_id только у корневой (один уровень). */
    public static function create(string $name, ?int $parentId = null): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO repo_categories (parent_id, name, created_at) VALUES (:parent, :name, NOW())'
        );
        $stmt->execute([':parent' => $parentId, ':name' => $name]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_categories SET name = :name WHERE id = :id');
        $stmt->execute([':name' => $name, ':id' => $id]);
    }

    /** Подкатегории удаляются каскадом (FK), файлы остаются без категории. */
    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM repo_categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
