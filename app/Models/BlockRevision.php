<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * История версий блока (группа 5.1). Перед каждой перезаписью блока его
 * текущее состояние снимается сюда; хранятся последние KEEP ревизий.
 */
final class BlockRevision
{
    /** Сколько последних ревизий хранить на блок. */
    public const KEEP = 20;

    /**
     * Снимает ревизию (текущее состояние блока) и подрезает историю до KEEP.
     */
    public static function snapshot(int $blockId, ?string $title, array $data, ?string $customCss, ?int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO block_revisions (block_id, title, data, custom_css, created_by, created_at)
             VALUES (:block_id, :title, :data, :custom_css, :created_by, NOW())'
        );
        $stmt->execute([
            ':block_id' => $blockId,
            ':title' => $title,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':custom_css' => $customCss,
            ':created_by' => $userId,
        ]);
        $id = (int) Database::pdo()->lastInsertId();

        self::prune($blockId, self::KEEP);

        return $id;
    }

    /**
     * Последние ревизии блока (новые сверху), с именем автора.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forBlock(int $blockId, int $limit = self::KEEP): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, u.username AS author
             FROM block_revisions r
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.block_id = :block_id
             ORDER BY r.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':block_id' => $blockId]);

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM block_revisions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Оставляет только $keep новейших ревизий блока, остальные удаляет.
     */
    public static function prune(int $blockId, int $keep = self::KEEP): void
    {
        // id новейшей ревизии, которую ещё сохраняем (граница отсечения).
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM block_revisions WHERE block_id = :block_id ORDER BY id DESC LIMIT 1 OFFSET :offset'
        );
        $stmt->bindValue(':block_id', $blockId, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $keep, \PDO::PARAM_INT);
        $stmt->execute();
        $threshold = $stmt->fetchColumn();
        if ($threshold === false) {
            return; // ревизий меньше, чем keep — подрезать нечего
        }

        $del = Database::pdo()->prepare('DELETE FROM block_revisions WHERE block_id = :block_id AND id <= :threshold');
        $del->execute([':block_id' => $blockId, ':threshold' => (int) $threshold]);
    }

    public static function countForBlock(int $blockId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM block_revisions WHERE block_id = :block_id');
        $stmt->execute([':block_id' => $blockId]);

        return (int) $stmt->fetchColumn();
    }
}
