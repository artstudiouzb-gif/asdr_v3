<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Универсальная история версий для основных сущностей контента.
 * Имена таблиц и колонок берутся только из закрытого allowlist ниже.
 */
final class ContentRevision
{
    private const KEEP_PER_ENTITY = 50;

    private const TYPES = [
        'page' => [
            'table' => 'pages',
            'columns' => ['title', 'slug', 'meta_title', 'meta_description', 'lead', 'status', 'is_home', 'layout_type', 'hide_chrome', 'transparent_header', 'parent_id'],
            'children' => [
                ['table' => 'page_translations', 'fk' => 'page_id', 'columns' => ['lang', 'title', 'meta_title', 'meta_description', 'lead']],
            ],
        ],
        'news' => [
            'table' => 'news',
            'columns' => ['title', 'slug', 'excerpt', 'lead_html', 'badge', 'content', 'image', 'video_url', 'audio_url', 'audio_title', 'hashtags', 'press_release_url', 'key_points', 'event_meta', 'timeline_json', 'docs', 'source_note', 'layout_type', 'sidebar_layout', 'focal_x', 'focal_y', 'meta_title', 'meta_description', 'status', 'published_at'],
            'children' => [
                ['table' => 'news_translations', 'fk' => 'news_id', 'columns' => ['lang', 'title', 'badge', 'excerpt', 'lead_html', 'content', 'hashtags', 'key_points', 'event_meta', 'timeline_json', 'docs', 'poll_question', 'poll_options_json', 'meta_title', 'meta_description', 'card_title', 'card_badge', 'card_stats', 'card_signature', 'card_note']],
                ['table' => 'news_images', 'fk' => 'news_id', 'columns' => ['path', 'alt_text', 'focal_x', 'focal_y', 'sort_order']],
                ['table' => 'news_polls', 'fk' => 'news_id', 'columns' => ['question', 'options_json']],
            ],
        ],
        // Проект — строка pages с подтипом: анонс карточки лежит в lead, а
        // содержимое живёт в блоках со своей историей версий.
        'project' => [
            'table' => 'pages',
            'columns' => ['title', 'slug', 'lead', 'cover_image', 'status', 'is_featured', 'sort_order'],
            'children' => [],
        ],
    ];

    public static function supports(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function capture(string $type, int $entityId, ?int $userId): ?int
    {
        $snapshot = self::snapshot($type, $entityId);
        if ($snapshot === null) {
            return null;
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $pdo = Database::pdo();

        $latest = $pdo->prepare(
            'SELECT id, snapshot_hash FROM content_revisions
             WHERE entity_type = :type AND entity_id = :entity_id ORDER BY id DESC LIMIT 1'
        );
        $latest->execute([':type' => $type, ':entity_id' => $entityId]);
        $row = $latest->fetch();
        if ($row && hash_equals((string) $row['snapshot_hash'], $hash)) {
            return (int) $row['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO content_revisions (entity_type, entity_id, snapshot, snapshot_hash, created_by)
             VALUES (:type, :entity_id, :snapshot, :hash, :created_by)'
        );
        $stmt->execute([
            ':type' => $type,
            ':entity_id' => $entityId,
            ':snapshot' => $json,
            ':hash' => $hash,
            ':created_by' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        self::prune($type, $entityId);

        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forEntity(string $type, int $entityId): array
    {
        if (!self::supports($type)) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT r.id, r.entity_type, r.entity_id, r.created_at, r.created_by,
                    COALESCE(u.username, :deleted_user) AS username
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.entity_type = :type AND r.entity_id = :entity_id
             ORDER BY r.id DESC LIMIT 50'
        );
        $stmt->execute([':deleted_user' => 'Системный пользователь', ':type' => $type, ':entity_id' => $entityId]);

        return $stmt->fetchAll();
    }

    public static function find(int $revisionId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM content_revisions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $revisionId]);
        $row = $stmt->fetch();
        if (!$row || !self::supports((string) $row['entity_type'])) {
            return null;
        }
        try {
            $row['decoded_snapshot'] = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($row['decoded_snapshot']) ? $row : null;
    }

    public static function deleteForEntity(string $type, int $entityId): void
    {
        if (!self::supports($type)) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'DELETE FROM content_revisions WHERE entity_type = :type AND entity_id = :entity_id'
        );
        $stmt->execute([':type' => $type, ':entity_id' => $entityId]);
    }

    public static function isFresh(string $type, int $entityId, string $expectedUpdatedAt, ?int $expectedLockVersion = null): bool
    {
        if (!self::supports($type)) {
            return true;
        }
        $cfg = self::TYPES[$type];
        $pdo = Database::pdo();

        if ($expectedLockVersion !== null && $expectedLockVersion > 0) {
            try {
                $stmt = $pdo->prepare('SELECT lock_version FROM ' . $cfg['table'] . ' WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $entityId]);
                $actualVersion = $stmt->fetchColumn();
                if ($actualVersion !== false && $actualVersion !== null && (int) $actualVersion > 0) {
                    return (int) $actualVersion <= $expectedLockVersion;
                }
            } catch (\Throwable $e) {
                // Игнорируем ошибку отсутствия колонки
            }
        }

        if ($expectedUpdatedAt === '') {
            return true;
        }

        $stmt = $pdo->prepare('SELECT updated_at FROM ' . $cfg['table'] . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $entityId]);
        $actual = $stmt->fetchColumn();

        return $actual === false || hash_equals((string) $actual, $expectedUpdatedAt);
    }

    public static function restore(int $revisionId, ?int $userId): ?array
    {
        $revision = self::find($revisionId);
        if ($revision === null) {
            return null;
        }

        $type = (string) $revision['entity_type'];
        $entityId = (int) $revision['entity_id'];
        $snapshot = $revision['decoded_snapshot'];
        $cfg = self::TYPES[$type];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($type === 'page') {
                $lockCurrent = $pdo->prepare('SELECT id FROM pages WHERE id = :id FOR UPDATE');
                $lockCurrent->execute([':id' => $entityId]);
                if ($lockCurrent->fetchColumn() === false) {
                    throw new \DomainException('Страница для восстановления не найдена.');
                }
                if (!empty($snapshot['entity']['is_home'])) {
                    $snapshot['entity']['parent_id'] = null;
                }
                $parentId = !empty($snapshot['entity']['parent_id'])
                    ? (int) $snapshot['entity']['parent_id']
                    : null;
                $parentError = Page::validateParent($parentId, $entityId, true);
                if ($parentError !== null) {
                    throw new \DomainException('Нельзя восстановить иерархию страницы: ' . $parentError);
                }
            }

            // Снимок текущего состояния делает восстановление обратимым.
            self::capture($type, $entityId, $userId);

            if ($type === 'page' && !empty($snapshot['entity']['is_home'])) {
                $pdo->prepare('UPDATE pages SET is_home = 0 WHERE id <> :id')->execute([':id' => $entityId]);
            }
            self::updateRow(
                $cfg['table'],
                $cfg['columns'],
                $entityId,
                self::withLegacyColumns($type, (array) ($snapshot['entity'] ?? []))
            );
            $pdo->prepare('UPDATE ' . $cfg['table'] . ' SET lock_version = lock_version + 1 WHERE id = :id')
                ->execute([':id' => $entityId]);

            foreach ($cfg['children'] as $child) {
                $pdo->prepare('DELETE FROM ' . $child['table'] . ' WHERE ' . $child['fk'] . ' = :id')
                    ->execute([':id' => $entityId]);
                foreach ((array) ($snapshot['children'][$child['table']] ?? []) as $item) {
                    self::insertChild($child['table'], $child['fk'], $child['columns'], $entityId, (array) $item);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['type' => $type, 'entity_id' => $entityId];
    }

    private static function snapshot(string $type, int $entityId): ?array
    {
        if (!self::supports($type)) {
            return null;
        }
        $cfg = self::TYPES[$type];
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $entityId]);
        $entity = $stmt->fetch();
        if (!$entity) {
            return null;
        }

        $children = [];
        foreach ($cfg['children'] as $child) {
            $cols = implode(', ', $child['columns']);
            $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM ' . $child['table'] . ' WHERE ' . $child['fk'] . ' = :id ORDER BY id ASC');
            $stmt->execute([':id' => $entityId]);
            $children[$child['table']] = $stmt->fetchAll();
        }

        return [
            'version' => 1,
            'entity' => array_intersect_key($entity, array_flip($cfg['columns'])),
            'children' => $children,
        ];
    }

    /**
     * Старые имена колонок в снимках: актуальная колонка → как она называлась
     * раньше. Снимки, снятые до переезда проектов в pages, хранят анонс под
     * именем `description`; без сопоставления восстановление затёрло бы его
     * пустым значением.
     *
     * @var array<string, array<string, string>>
     */
    private const LEGACY_COLUMNS = [
        'project' => ['lead' => 'description'],
    ];

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private static function withLegacyColumns(string $type, array $entity): array
    {
        foreach (self::LEGACY_COLUMNS[$type] ?? [] as $column => $legacy) {
            if (!array_key_exists($column, $entity) && array_key_exists($legacy, $entity)) {
                $entity[$column] = $entity[$legacy];
            }
        }

        return $entity;
    }

    private static function updateRow(string $table, array $columns, int $id, array $data): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach ($columns as $column) {
            // Имена колонок в обратных кавычках: `lead` — зарезервированное
            // слово в MySQL 8 (оконная функция LEAD), без них запрос падает.
            $sets[] = '`' . $column . '` = :' . $column;
            $params[':' . $column] = $data[$column] ?? null;
        }
        Database::pdo()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private static function insertChild(string $table, string $fk, array $columns, int $entityId, array $data): void
    {
        $allColumns = array_merge([$fk], $columns);
        $params = [':' . $fk => $entityId];
        foreach ($columns as $column) {
            $params[':' . $column] = $data[$column] ?? null;
        }
        Database::pdo()->prepare(
            'INSERT INTO ' . $table . ' (`' . implode('`, `', $allColumns) . '`) VALUES ('
            . implode(', ', array_map(static fn (string $c): string => ':' . $c, $allColumns)) . ')'
        )->execute($params);
    }

    private static function prune(string $type, int $entityId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM content_revisions WHERE entity_type = :type AND entity_id = :entity_id
             ORDER BY id DESC LIMIT 1 OFFSET ' . (self::KEEP_PER_ENTITY - 1)
        );
        $stmt->execute([':type' => $type, ':entity_id' => $entityId]);
        $threshold = $stmt->fetchColumn();
        if ($threshold !== false) {
            $del = Database::pdo()->prepare(
                'DELETE FROM content_revisions WHERE entity_type = :type AND entity_id = :entity_id AND id < :threshold'
            );
            $del->execute([':type' => $type, ':entity_id' => $entityId, ':threshold' => (int) $threshold]);
        }
    }
}
