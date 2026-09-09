<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Запись пользовательского типа контента (задача 131). Значения полей хранятся
 * в JSON-колонке data; переводы — в content_entry_translations.
 */
final class ContentEntry
{
    /** @return array<int, array<string, mixed>> */
    public static function forType(int $typeId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM content_entries WHERE type_id = :t AND deleted_at IS NULL';
        $params = [':t' => $typeId];
        if ($status === 'published' || $status === 'draft') {
            $sql .= ' AND status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY sort_order ASC, created_at DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function adminList(int $typeId, array $filters): array
    {
        [$where, $params] = self::adminListWhere($typeId, $filters);
        $orders = [
            'manual' => 'ce.sort_order ASC, ce.created_at DESC, ce.id DESC',
            'newest' => 'ce.created_at DESC, ce.id DESC',
            'oldest' => 'ce.created_at ASC, ce.id ASC',
            'title_asc' => 'ce.title ASC, ce.id ASC',
            'title_desc' => 'ce.title DESC, ce.id DESC',
        ];
        $order = $orders[$filters['sort'] ?? 'manual'] ?? $orders['manual'];
        $stmt = Database::pdo()->prepare("SELECT ce.* FROM content_entries ce {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int) $filters['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $filters['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function adminCount(int $typeId, array $filters): int
    {
        [$where, $params] = self::adminListWhere($typeId, $filters);
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM content_entries ce {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function adminListWhere(int $typeId, array $filters): array
    {
        $where = 'WHERE ce.type_id = :type_id AND ce.deleted_at IS NULL';
        $params = [':type_id' => (string) $typeId];
        if (in_array($filters['status'] ?? '', ['published', 'draft'], true)) {
            $where .= ' AND ce.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND (ce.title LIKE :q_title OR ce.slug LIKE :q_slug OR ce.data LIKE :q_data'
                . ' OR EXISTS (SELECT 1 FROM content_entry_translations cetq WHERE cetq.entry_id = ce.id AND cetq.title LIKE :q_translation))';
            $like = '%' . (string) $filters['q'] . '%';
            $params[':q_title'] = $like;
            $params[':q_slug'] = $like;
            $params[':q_data'] = $like;
            $params[':q_translation'] = $like;
        }

        return [$where, $params];
    }

    /**
     * Опубликованные записи типа с поиском/сортировкой/пагинацией (фронтенд).
     * Поиск — по заголовку и JSON-значениям полей (data). Сортировка:
     * new (новые), old (старые), title (по алфавиту).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forTypePublic(
        int $typeId,
        string $q = '',
        string $sort = 'new',
        int $limit = 12,
        int $offset = 0,
        ?string $lang = null
    ): array
    {
        $lang = $lang ?? Language::defaultCode();
        [$join, $where, $params, $titleExpression] = self::publicWhere($typeId, $q, $lang);
        $order = match ($sort) {
            'old' => 'ce.created_at ASC',
            'title' => "{$titleExpression} ASC",
            default => 'ce.created_at DESC',
        };
        $translationColumns = $lang === Language::defaultCode()
            ? 'NULL AS _translation_title, NULL AS _translation_data'
            : 'cet.title AS _translation_title, cet.data AS _translation_data';
        $sql = "SELECT ce.*, ct.has_translations AS _has_translations, {$translationColumns}
                FROM content_entries ce
                INNER JOIN content_types ct ON ct.id = ce.type_id
                {$join}
                WHERE {$where}
                ORDER BY {$order}, ce.id DESC LIMIT :lim OFFSET :off";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => self::decodePublicRow($row),
            $stmt->fetchAll()
        );
    }

    public static function countTypePublic(int $typeId, string $q = '', ?string $lang = null): int
    {
        $lang = $lang ?? Language::defaultCode();
        [$join, $where, $params] = self::publicWhere($typeId, $q, $lang);
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM content_entries ce
             INNER JOIN content_types ct ON ct.id = ce.type_id
             {$join}
             WHERE {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0:string,1:string,2:array<string,string>,3:string}
     */
    private static function publicWhere(int $typeId, string $q, string $lang): array
    {
        $default = Language::defaultCode();
        $join = '';
        $where = "ce.type_id = :t AND ce.status = 'published' AND ce.deleted_at IS NULL";
        $params = [':t' => (string) $typeId];
        $titleExpression = 'ce.title';

        if ($lang !== $default) {
            $join = 'LEFT JOIN content_entry_translations cet
                        ON cet.entry_id = ce.id AND cet.lang = :content_lang';
            $params[':content_lang'] = $lang;
            $meaningful = "(
                TRIM(COALESCE(cet.title, '')) <> ''
                OR TRIM(COALESCE(cet.data, '')) NOT IN ('', '[]', '{}', 'null')
            )";
            $where .= " AND (ct.has_translations = 0 OR (cet.id IS NOT NULL AND {$meaningful}))";
            $titleExpression = "CASE
                WHEN ct.has_translations = 1 AND TRIM(COALESCE(cet.title, '')) <> '' THEN cet.title
                ELSE ce.title
            END";
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            if ($lang === $default) {
                $where .= ' AND (ce.title LIKE :q1 OR ce.data LIKE :q2)';
            } else {
                $where .= " AND (
                    {$titleExpression} LIKE :q1
                    OR (
                        ct.has_translations = 1
                        AND (cet.data LIKE :q2 OR ce.data LIKE :q3)
                    )
                    OR (ct.has_translations = 0 AND ce.data LIKE :q4)
                )";
                $params[':q3'] = $like;
                $params[':q4'] = $like;
            }
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }

        return [$join, $where, $params, $titleExpression];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decodePublicRow(array $row): array
    {
        $baseData = json_decode((string) ($row['data'] ?? ''), true) ?: [];
        if (
            (int) ($row['_has_translations'] ?? 0) === 1
            && trim((string) ($row['_translation_title'] ?? '')) !== ''
        ) {
            $row['title'] = $row['_translation_title'];
        }
        if ((int) ($row['_has_translations'] ?? 0) === 1) {
            $translatedData = json_decode((string) ($row['_translation_data'] ?? ''), true) ?: [];
            $baseData = array_merge($baseData, self::normalizeTranslationData($translatedData));
        }
        $row['data'] = json_encode($baseData, JSON_UNESCAPED_UNICODE);
        unset($row['_has_translations'], $row['_translation_title'], $row['_translation_data']);

        return $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM content_entries WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string) $row['data'], true) ?: [];

        return $row;
    }

    public static function findPublishedBySlug(int $typeId, string $slug): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM content_entries WHERE type_id = :t AND slug = :s AND status = 'published' AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':t' => $typeId, ':s' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string) $row['data'], true) ?: [];

        return $row;
    }

    /**
     * Накладывает перевод на запись каталога, сохраняя базовые значения
     * для отдельных незаполненных полей.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function localize(array $entry, string $lang, bool $hasTranslations = true): array
    {
        if (!$hasTranslations || $lang === Language::defaultCode()) {
            return $entry;
        }
        $translation = self::translations((int) $entry['id'])[$lang] ?? null;
        if ($translation === null) {
            return $entry;
        }
        if (trim((string) ($translation['title'] ?? '')) !== '') {
            $entry['title'] = $translation['title'];
        }
        $baseData = is_array($entry['data'] ?? null)
            ? $entry['data']
            : (json_decode((string) ($entry['data'] ?? ''), true) ?: []);
        $entry['data'] = array_merge($baseData, self::normalizeTranslationData((array) ($translation['data'] ?? [])));

        return $entry;
    }

    /** @return array<int,string> */
    public static function availableLangs(int $entryId): array
    {
        $langs = [Language::defaultCode()];
        $stmt = Database::pdo()->prepare(
            "SELECT lang FROM content_entry_translations
             WHERE entry_id = :entry_id
               AND (
                    TRIM(COALESCE(title, '')) <> ''
                    OR TRIM(COALESCE(data, '')) NOT IN ('', '[]', '{}', 'null')
               )"
        );
        $stmt->execute([':entry_id' => $entryId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $lang) {
            if (is_string($lang) && $lang !== '') {
                $langs[] = $lang;
            }
        }

        return array_values(array_unique($langs));
    }

    public static function slugExists(int $typeId, string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM content_entries WHERE type_id = :t AND slug = :s';
        $params = [':t' => $typeId, ':s' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(int $typeId, string $title, string $slug, string $status, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO content_entries (type_id, title, slug, status, data, created_at)
             VALUES (:t, :ti, :sl, :st, :d, NOW())'
        );
        $stmt->execute([
            ':t' => $typeId,
            ':ti' => $title,
            ':sl' => $slug,
            ':st' => $status === 'published' ? 'published' : 'draft',
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, string $title, string $slug, string $status, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE content_entries SET title = :ti, slug = :sl, status = :st, data = :d WHERE id = :id'
        );
        $stmt->execute([
            ':ti' => $title,
            ':sl' => $slug,
            ':st' => $status === 'published' ? 'published' : 'draft',
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    /** Мягкое удаление. */
    public static function delete(int $id): void
    {
        Database::pdo()->prepare('UPDATE content_entries SET deleted_at = NOW() WHERE id = :id')->execute([':id' => $id]);
    }

    // --- Переводы ---

    /** @return array<string, array<string, mixed>> перевод по языку */
    public static function translations(int $entryId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM content_entry_translations WHERE entry_id = :e');
        $stmt->execute([':e' => $entryId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['lang']] = [
                'title' => $r['title'],
                'data' => $r['data'] ? (json_decode((string) $r['data'], true) ?: []) : [],
            ];
        }

        return $out;
    }

    public static function upsertTranslation(int $entryId, string $lang, ?string $title, array $data): void
    {
        $data = self::normalizeTranslationData($data);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO content_entry_translations (entry_id, lang, title, data)
             VALUES (:e, :l, :ti, :d)
             ON DUPLICATE KEY UPDATE title = VALUES(title), data = VALUES(data)'
        );
        $stmt->execute([
            ':e' => $entryId,
            ':l' => $lang,
            ':ti' => $title,
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function normalizeTranslationData(array $data): array
    {
        return array_filter(
            $data,
            static fn (mixed $value): bool => $value !== null
                && (!is_string($value) || trim($value) !== '')
                && (!is_array($value) || $value !== [])
        );
    }
}
