<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\ConcurrencyException;
use App\Core\Database;
use App\Core\Logger;

/**
 * Проект — страница с подтипом (`pages.entity_type = 'project'`).
 *
 * Своей таблицы у проектов нет: и чтение, и запись идут в `pages`. Анонс
 * карточки лежит в `pages.lead` и отдаётся под привычным именем `description`
 * (его ждут шаблоны списков), тело проекта живёт в блоках.
 *
 * Язык — один механизм: языковая версия это отдельная запись своего языка,
 * связанная через `translation_group_id`. Отдельной таблицы переводов у
 * проектов нет, поэтому нет и наложения перевода на строку.
 */
final class Project
{
    public const ENTITY_TYPE = 'project';

    /** Общий кусок выборки: строка проекта с анонсом под именем description. */
    private const SELECT = 'SELECT p.*, p.`lead` AS description FROM pages p';

    /** Условие принадлежности к проектам. */
    private const IS_PROJECT = "p.entity_type = 'project'";

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $stmt = Database::pdo()->query(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . ' AND p.deleted_at IS NULL
             ORDER BY p.sort_order ASC, p.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public static function trashed(): array
    {
        $stmt = Database::pdo()->query(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . ' AND p.deleted_at IS NOT NULL
             ORDER BY p.deleted_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters): array
    {
        [$where, $params] = self::adminListWhere($filters);
        $orders = [
            'manual' => 'p.sort_order ASC, p.created_at DESC, p.id DESC',
            'newest' => 'p.created_at DESC, p.id DESC',
            'oldest' => 'p.created_at ASC, p.id ASC',
            'title_asc' => 'p.title ASC, p.id ASC',
            'title_desc' => 'p.title DESC, p.id DESC',
        ];
        $order = $orders[$filters['sort'] ?? 'manual'] ?? $orders['manual'];

        $stmt = Database::pdo()->prepare(
            self::SELECT . " {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int) $filters['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $filters['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $filters */
    public static function adminCount(array $filters): int
    {
        [$where, $params] = self::adminListWhere($filters);
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM pages p {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:array<string,string>}
     */
    private static function adminListWhere(array $filters): array
    {
        $params = [];
        $where = [self::IS_PROJECT, 'p.deleted_at IS NULL'];

        $langFilter = (string) ($filters['lang'] ?? '');
        if ($langFilter !== '' && $langFilter !== 'all') {
            $where[] = 'p.lang = :lang';
            $params[':lang'] = $langFilter;
        }

        if (in_array($filters['status'] ?? '', ['published', 'draft'], true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q_title OR p.slug LIKE :q_slug OR p.`lead` LIKE :q_lead)';
            $like = '%' . (string) $filters['q'] . '%';
            $params[':q_title'] = $like;
            $params[':q_slug'] = $like;
            $params[':q_lead'] = $like;
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'published'], true)) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE pages SET status = :s WHERE id = :id AND entity_type = 'project' AND deleted_at IS NULL"
        );
        $stmt->execute([':s' => $status, ':id' => $id]);
        self::bustPageCache();
    }

    /** Полная копия проекта: блоки и переводы заголовка (черновик, slug -copy). */
    public static function duplicate(int $id): ?int
    {
        // Копируем строку pages целиком: у проекта те же служебные колонки, что
        // и у страницы, и терять их при копии незачем.
        $page = Page::findById($id);
        if (!$page || (string) ($page['entity_type'] ?? '') !== self::ENTITY_TYPE) {
            return null;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $lang = (string) ($page['lang'] ?? Language::defaultCode());
            $newSlug = \App\Core\Duplicator::uniqueCopySlug(
                (string) $page['slug'],
                static fn (string $s) => self::slugExists($s, null, $lang)
            );
            $newId = \App\Core\Duplicator::copyRow('pages', $page, [
                'slug' => $newSlug,
                'status' => 'draft',
                'is_home' => 0,
                'deleted_at' => null,
                'translation_group_id' => null,
            ]);
            $pdo->prepare(
                'UPDATE pages SET translation_group_id = id
                 WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)'
            )->execute([':id' => $newId]);
            \App\Core\Duplicator::copyChildren('blocks', 'page_id', $id, $newId);
            \App\Core\Duplicator::copyChildren('page_translations', 'page_id', $id, $newId);

            $pdo->commit();

            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function restore(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE pages SET deleted_at = NULL WHERE id = :id AND entity_type = 'project'"
        );
        $stmt->execute([':id' => $id]);
        self::bustPageCache();
    }

    public static function forceDelete(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "DELETE FROM pages WHERE id = :id AND entity_type = 'project'"
        );
        $stmt->execute([':id' => $id]);
        ContentRevision::deleteForEntity('project', $id);
        self::bustPageCache();
    }

    /** @return array<int, array<string, mixed>> */
    public static function published(?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $stmt = Database::pdo()->prepare(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . " AND p.status = 'published'
               AND p.deleted_at IS NULL AND p.lang = :lang
             ORDER BY p.sort_order ASC, p.created_at DESC, p.id DESC"
        );
        $stmt->execute([':lang' => $lang]);

        return $stmt->fetchAll();
    }

    /**
     * Проекты для блока главной: только помеченные «показать на главном».
     * Если ни один не отмечен — откат на последние опубликованные (чтобы блок
     * не был пустым при первом включении источника).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forHome(int $limit = 6, ?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $limit = max(1, min(24, $limit));

        $query = static function (bool $featuredOnly) use ($lang, $limit): array {
            $sql = self::SELECT . ' WHERE ' . self::IS_PROJECT . " AND p.status = 'published'
                   AND p.deleted_at IS NULL AND p.lang = :lang"
                . ($featuredOnly ? ' AND p.is_featured = 1' : '')
                . " ORDER BY p.sort_order ASC, p.created_at DESC, p.id DESC LIMIT {$limit}";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute([':lang' => $lang]);

            return $stmt->fetchAll();
        };

        $rows = $query(true);

        return $rows !== [] ? $rows : $query(false);
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . ' AND p.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Опубликованный проект по адресу. Если версии запрошенного языка нет,
     * отдаём запись группы переводов — посетитель увидит проект, а не 404.
     *
     * @return array<string, mixed>|null
     */
    public static function findPublishedBySlug(string $slug, ?string $lang = null): ?array
    {
        $lang = $lang ?? Language::defaultCode();
        $pdo = Database::pdo();

        $stmtExact = $pdo->prepare(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . " AND p.slug = :slug AND p.lang = :lang
               AND p.status = 'published' AND p.deleted_at IS NULL LIMIT 1"
        );
        $stmtExact->execute([':slug' => $slug, ':lang' => $lang]);
        $exactRow = $stmtExact->fetch();
        if ($exactRow) {
            return $exactRow;
        }

        $stmt = $pdo->prepare(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . " AND p.slug = :slug
               AND p.status = 'published' AND p.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $groupId = (int) ($row['translation_group_id'] ?: $row['id']);
        $stmtGroup = $pdo->prepare(
            self::SELECT . ' WHERE ' . self::IS_PROJECT . " AND COALESCE(NULLIF(p.translation_group_id, 0), p.id) = :group_id
               AND p.lang = :lang AND p.status = 'published' AND p.deleted_at IS NULL LIMIT 1"
        );
        $stmtGroup->execute([':group_id' => $groupId, ':lang' => $lang]);
        $groupRow = $stmtGroup->fetch();

        return $groupRow ?: $row;
    }

    /**
     * Языки опубликованных версий проекта для публичного переключателя.
     *
     * @return list<string>
     */
    public static function availableLangs(int $id): array
    {
        $pdo = Database::pdo();

        $stmtGroupId = $pdo->prepare(
            "SELECT COALESCE(NULLIF(translation_group_id, 0), id) FROM pages
             WHERE id = :id AND entity_type = 'project' LIMIT 1"
        );
        $stmtGroupId->execute([':id' => $id]);
        $groupId = (int) ($stmtGroupId->fetchColumn() ?: $id);

        $stmt = $pdo->prepare(
            "SELECT DISTINCT lang FROM pages
             WHERE entity_type = 'project' AND deleted_at IS NULL AND status = 'published'
               AND COALESCE(NULLIF(translation_group_id, 0), id) = :group_id"
        );
        $stmt->execute([':group_id' => $groupId]);

        $langs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $lang) {
            if (is_string($lang) && $lang !== '') {
                $langs[] = $lang;
            }
        }

        return array_values(array_unique($langs));
    }

    /**
     * Языки контента для набора проектов: список кодов либо карта «код → id
     * записи» для кликабельных баджей в админке.
     *
     * @param array<int|string> $ids
     * @return array<int, list<string>>|array<int, array<string, int>>
     */
    public static function availableLangsForIds(array $ids, bool $withTargets = false): array
    {
        $targets = self::availableLangTargetsForIds($ids);
        if ($withTargets) {
            return $targets;
        }

        $map = [];
        foreach ($targets as $id => $langs) {
            $map[$id] = array_values(array_unique(array_keys($langs)));
        }

        return $map;
    }

    /**
     * @param array<int|string> $ids
     * @return array<int, array<string, int>>
     */
    public static function availableLangTargetsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $default = Language::defaultCode();
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = [];
        }
        if ($ids === []) {
            return $map;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT p1.id AS ref_id, p2.lang, p2.id AS target_id, p2.title
                 FROM pages p1
                 JOIN pages p2
                   ON p2.entity_type = 'project'
                  AND COALESCE(NULLIF(p2.translation_group_id, 0), p2.id)
                      = COALESCE(NULLIF(p1.translation_group_id, 0), p1.id)
                 WHERE p1.id IN ($in) AND p1.entity_type = 'project' AND p2.deleted_at IS NULL"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $refId = (int) $row['ref_id'];
                $lang = (string) ($row['lang'] ?? $default);
                if (trim((string) ($row['title'] ?? '')) !== '' && isset($map[$refId])) {
                    $map[$refId][$lang] = (int) $row['target_id'];
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('Project::availableLangsForIds: не удалось прочитать группы переводов', $e);
        }

        foreach ($ids as $id) {
            if (empty($map[$id])) {
                $map[$id] = [$default => $id];
            }
        }

        return $map;
    }

    public static function slugExists(string $slug, ?int $excludeId = null, ?string $lang = null): bool
    {
        $lang = $lang ?? Language::defaultCode();
        $sql = "SELECT COUNT(*) FROM pages
                WHERE entity_type = 'project' AND slug = :slug AND lang = :lang AND deleted_at IS NULL";
        $params = [':slug' => $slug, ':lang' => $lang];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string, int> */
    public static function langCounts(): array
    {
        $pdo = Database::pdo();
        $counts = [];
        $sum = 0;
        foreach (Language::active() as $l) {
            $code = (string) $l['code'];
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM pages
                 WHERE entity_type = 'project' AND deleted_at IS NULL AND lang = :code"
            );
            $stmt->execute([':code' => $code]);
            $c = (int) $stmt->fetchColumn();
            $counts[$code] = $c;
            $sum += $c;
        }
        $counts['all'] = $sum;

        return $counts;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $lang = (string) ($data['lang'] ?? Language::defaultCode());
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO pages (title, slug, entity_type, `lead`, cover_image, status, is_featured, sort_order, layout_type, lang, translation_group_id, created_at)
             VALUES (:title, :slug, 'project', :description, :cover_image, :status, :is_featured, :sort_order, 'no_sidebar', :lang, NULL, NOW())"
        );
        $stmt->execute([
            ':title' => $data['title'],
            ':slug' => $data['slug'],
            ':description' => $data['description'],
            ':cover_image' => $data['cover_image'],
            ':status' => $data['status'],
            ':is_featured' => !empty($data['is_featured']) ? 1 : 0,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':lang' => $lang,
        ]);

        // id читаем до сброса кэша: bustPageCache() ходит в settings и обнуляет
        // last insert id на холодном кэше.
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE pages SET translation_group_id = id WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)'
        )->execute([':id' => $id]);
        self::bustPageCache();

        return $id;
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data, ?int $expectedLockVersion = null): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE pages SET title = :title, slug = :slug, `lead` = :description,
             cover_image = :cover_image, status = :status, is_featured = :is_featured,
             sort_order = :sort_order, lock_version = lock_version + 1
             WHERE id = :id AND entity_type = 'project'"
            . ($expectedLockVersion !== null ? ' AND lock_version = :expected_lock_version' : '')
        );
        $params = [
            ':title' => $data['title'],
            ':slug' => $data['slug'],
            ':description' => $data['description'],
            ':cover_image' => $data['cover_image'],
            ':status' => $data['status'],
            ':is_featured' => !empty($data['is_featured']) ? 1 : 0,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':id' => $id,
        ];
        if ($expectedLockVersion !== null) {
            $params[':expected_lock_version'] = $expectedLockVersion;
        }
        $stmt->execute($params);
        if ($expectedLockVersion !== null && $stmt->rowCount() !== 1) {
            throw new ConcurrencyException('Проект был изменён другим пользователем.');
        }
        self::bustPageCache();
    }

    public static function delete(int $id): void
    {
        // Мягкое удаление: проект отправляется в корзину.
        $stmt = Database::pdo()->prepare(
            "UPDATE pages SET deleted_at = NOW() WHERE id = :id AND entity_type = 'project'"
        );
        $stmt->execute([':id' => $id]);
        self::bustPageCache();
    }

    private static function bustPageCache(): void
    {
        \App\Core\Cache::forgetPrefix('page:');
    }
}
