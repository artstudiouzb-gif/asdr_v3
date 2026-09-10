<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Translations;
use App\Core\UrlGuard;

/**
 * «Цель» (Maqsad) — набор снимков с названием и описанием. Публичного адреса
 * у цели нет: на сайте она показывается каруселью в виджете, поэтому ни slug,
 * ни SEO ей не нужны.
 *
 * Название и описание видны посетителю, а значит переводятся (механизм А,
 * `goal_translations`): набор снимков без единого слова не сообщает, что за
 * объект показан и зачем.
 */
final class Goal
{
    /**
     * Страница списка админки. Записей сотни, поэтому список постраничный и с
     * поиском по имени — иначе нужная цель ищется прокруткой.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function page(int $limit, int $offset, string $search = ''): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE g.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        // Число снимков — одним запросом вместе со списком: отдельный SELECT на
        // каждую строку дал бы N+1 на странице из полусотни целей.
        $sql = 'SELECT g.*, COUNT(i.id) AS image_count
                  FROM goals g
                  LEFT JOIN goal_images i ON i.goal_id = g.id
                ' . $where . '
                 GROUP BY g.id
                 ORDER BY g.id DESC
                 LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function countAll(string $search = ''): int
    {
        if ($search !== '') {
            $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM goals WHERE name LIKE ?');
            $stmt->execute(['%' . $search . '%']);
        } else {
            $stmt = Database::pdo()->query('SELECT COUNT(*) FROM goals');
        }

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id, ?string $lang = null): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM goals WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $lang === null ? $row : self::localize($row, $lang);
    }

    /**
     * Накладывает перевод указанного языка на базовую строку. Пустое поле
     * перевода откатывается к основному языку — недописанный перевод оставляет
     * на месте текст, а не дыру.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function localize(array $row, string $lang): array
    {
        return self::applyTranslation($row, GoalTranslation::find((int) $row['id'], $lang));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function localizeRows(array $rows, string $lang): array
    {
        $translations = GoalTranslation::forGoalIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $lang
        );

        return array_map(
            static fn (array $row): array => self::applyTranslation($row, $translations[(int) $row['id']] ?? null),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $translation
     * @return array<string, mixed>
     */
    private static function applyTranslation(array $row, ?array $translation): array
    {
        return Translations::overlayFields($row, $translation, ['name', 'description']);
    }

    /**
     * Языки, на которых у цели заполнен перевод — для колонки «Языки» в списке
     * админки. Одним запросом на всю страницу, а не по запросу на строку.
     *
     * @param list<int> $ids
     * @return array<int, list<string>>
     */
    public static function availableLangsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = [];
        }
        if ($ids === []) {
            return $map;
        }

        // Основной язык есть всегда: базовая строка и есть его версия.
        $default = Language::defaultCode();
        foreach ($ids as $id) {
            $map[$id][] = $default;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT goal_id, lang FROM goal_translations
              WHERE goal_id IN ($in) AND TRIM(COALESCE(name, '')) <> ''"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $goalId = (int) $row['goal_id'];
            $lang = (string) $row['lang'];
            if ($lang !== $default && !in_array($lang, $map[$goalId], true)) {
                $map[$goalId][] = $lang;
            }
        }

        return $map;
    }

    /** @return array<int, array<string, mixed>> */
    public static function images(int $goalId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM goal_images WHERE goal_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$goalId]);

        return $stmt->fetchAll();
    }

    /**
     * Случайная цель со снимками — то, что показывает виджет.
     *
     * Идентификаторы выбираются отдельным запросом, а не `ORDER BY RAND()`:
     * так выбор не зависит от того, сколько снимков у целей, и остаётся
     * одинаково дешёвым, когда целей станет вдвое больше. Цель без снимков
     * пропускается — пустая карусель хуже, чем соседняя цель.
     *
     * @return array{goal: array<string, mixed>, images: array<int, array<string, mixed>>}|null
     */
    public static function random(?string $lang = null): ?array
    {
        $ids = Database::pdo()
            ->query('SELECT DISTINCT g.id FROM goals g
                       JOIN goal_images i ON i.goal_id = g.id
                      WHERE g.is_active = 1')
            ->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids === []) {
            return null;
        }

        $id = (int) $ids[random_int(0, count($ids) - 1)];
        $goal = self::find($id, $lang);
        if ($goal === null) {
            return null;
        }

        return ['goal' => $goal, 'images' => self::images($id)];
    }

    public static function create(string $name, string $description, bool $isActive): int
    {
        $stmt = Database::pdo()->prepare('INSERT INTO goals (name, description, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $isActive ? 1 : 0]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, string $name, string $description, bool $isActive): void
    {
        $stmt = Database::pdo()->prepare('UPDATE goals SET name = ?, description = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$name, $description, $isActive ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        // Снимки уходят вместе с целью по внешнему ключу: строки без владельца
        // никому не видны и просто копились бы.
        $stmt = Database::pdo()->prepare('DELETE FROM goals WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * У цели нет draft/published — только `is_active` («показывать в
     * карусели» / нет), поэтому массовое действие называется «включить» /
     * «выключить», а не «опубликовать»: называть его иначе значило бы
     * приписать цели состояние, которого в схеме не существует.
     */
    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE goals SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Снимки цели переписываются целиком: форма присылает готовый порядок, и
     * сверять её со старым списком построчно незачем.
     *
     * @param array<int, array{image: string, alt: string}> $images
     */
    public static function replaceImages(int $goalId, array $images): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM goal_images WHERE goal_id = ?')->execute([$goalId]);

        $insert = $pdo->prepare(
            'INSERT INTO goal_images (goal_id, image, alt, sort_order) VALUES (?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($images as $image) {
            $src = trim((string) ($image['image'] ?? ''));
            if ($src === '' || !UrlGuard::isSafeMedia($src)) {
                continue;
            }
            $insert->execute([
                $goalId,
                $src,
                mb_substr(trim((string) ($image['alt'] ?? '')), 0, 200),
                $order++,
            ]);
        }
    }
}
