<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Slug;

/**
 * Категория новости — рубрика ленты.
 *
 * Раньше рубрику изображал свободный текст в `news.badge`: он не сводился в
 * список, не фильтровался и «Новости» с «новости» считались разными. Теперь
 * это запись со slug и переводимым названием, а бейдж вернулся к своей
 * настоящей роли — необязательной пометке вроде «Важно».
 */
final class NewsCategory
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $requestCache = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(?string $lang = null, bool $activeOnly = false): array
    {
        $key = ($lang ?? '') . '|' . ($activeOnly ? '1' : '0');
        if (isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }

        $sql = 'SELECT * FROM news_categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';

        $rows = Database::pdo()->query($sql)->fetchAll();
        $rows = self::localizeRows($rows, $lang);

        return self::$requestCache[$key] = $rows;
    }

    /** Активные категории, у которых есть хотя бы одна опубликованная новость. */
    public static function withPublishedNews(?string $lang = null): array
    {
        $rows = Database::pdo()->query(
            'SELECT c.* FROM news_categories c
             WHERE c.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM news n
                   WHERE n.category_id = c.id AND n.status = \'published\' AND n.deleted_at IS NULL
               )
             ORDER BY c.sort_order ASC, c.name ASC, c.id ASC'
        )->fetchAll();

        return self::localizeRows($rows, $lang);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM news_categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public static function findBySlug(string $slug, bool $activeOnly = false): ?array
    {
        $sql = 'SELECT * FROM news_categories WHERE slug = :slug';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute([':slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Название на нужном языке с откатом к основному, если перевод пуст.
     * Пустое поле перевода — не «нет категории», а «переводчик до неё не дошёл»:
     * посетителю всё равно нужно что-то показать.
     */
    public static function localize(array $row, ?string $lang = null): array
    {
        if ($lang === null || $lang === '' || $lang === Language::defaultCode()) {
            return $row;
        }
        $names = NewsCategoryTranslation::namesForIds([(int) $row['id']], $lang);
        if (isset($names[(int) $row['id']])) {
            $row['name'] = $names[(int) $row['id']];
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function localizeRows(array $rows, ?string $lang = null): array
    {
        if ($rows === [] || $lang === null || $lang === '' || $lang === Language::defaultCode()) {
            return $rows;
        }

        $names = NewsCategoryTranslation::namesForIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $lang
        );

        return array_map(static function (array $row) use ($names): array {
            $id = (int) $row['id'];
            if (isset($names[$id])) {
                $row['name'] = $names[$id];
            }

            return $row;
        }, $rows);
    }

    /**
     * Названия категорий для набора новостей одним запросом.
     *
     * @param array<int|string> $categoryIds
     * @return array<int, string>
     */
    public static function namesForIds(array $categoryIds, ?string $lang = null): array
    {
        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($categoryIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = Database::pdo()->prepare("SELECT id, name FROM news_categories WHERE id IN ({$in})");
        $stmt->execute($categoryIds);

        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        if ($lang !== null && $lang !== '' && $lang !== Language::defaultCode()) {
            foreach (NewsCategoryTranslation::namesForIds($categoryIds, $lang) as $id => $name) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    public static function create(string $name, string $slug = '', bool $active = true, int $sortOrder = 0, string $icon = ''): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $slug = Slug::unique(
            $slug !== '' ? $slug : $name,
            static fn (string $s, ?int $exclude): bool => self::slugExists($s, $exclude)
        );
        $stmt = Database::pdo()->prepare(
            'INSERT INTO news_categories (name, slug, icon, is_active, sort_order) VALUES (:n, :s, :i, :a, :o)'
        );
        $stmt->execute([
            ':n' => mb_substr($name, 0, 150),
            ':s' => mb_substr($slug, 0, 150),
            ':i' => \App\Core\Icon::cleanName($icon),
            ':a' => $active ? 1 : 0,
            ':o' => $sortOrder,
        ]);
        // id читаем до сброса кэша: bustPageCache() ходит в settings и обнуляет
        // last insert id на холодном кэше (см. CLAUDE.md, «Грабли»).
        $id = (int) Database::pdo()->lastInsertId();
        self::bustCache();

        return $id;
    }

    public static function update(int $id, string $name, string $slug, bool $active, int $sortOrder, string $icon = ''): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $slug = Slug::unique(
            $slug !== '' ? $slug : $name,
            static fn (string $s, ?int $exclude): bool => self::slugExists($s, $exclude),
            $id
        );
        $stmt = Database::pdo()->prepare(
            'UPDATE news_categories SET name = :n, slug = :s, icon = :i, is_active = :a, sort_order = :o WHERE id = :id'
        );
        $stmt->execute([
            ':n' => mb_substr($name, 0, 150),
            ':s' => mb_substr($slug, 0, 150),
            ':i' => \App\Core\Icon::cleanName($icon),
            ':a' => $active ? 1 : 0,
            ':o' => $sortOrder,
            ':id' => $id,
        ]);
        self::bustCache();
    }

    /**
     * Удаление категории. Новости остаются: внешний ключ объявлен
     * ON DELETE SET NULL, поэтому они просто теряют рубрику, а не исчезают.
     */
    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM news_categories WHERE id = :id')->execute([':id' => $id]);
        self::bustCache();
    }

    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM news_categories WHERE slug = :s';
        $params = [':s' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Сколько новостей в каждой категории (для списка категорий в админке).
     *
     * @return array<int, int>
     */
    public static function newsCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT category_id, COUNT(*) AS total FROM news
             WHERE deleted_at IS NULL AND category_id IS NOT NULL
             GROUP BY category_id'
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['category_id']] = (int) $row['total'];
        }

        return $counts;
    }

    private static function bustCache(): void
    {
        self::$requestCache = [];
        Cache::forgetPrefix('page:');
    }
}
