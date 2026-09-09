<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;
use App\Core\Database;

/**
 * Переводы названий категорий (механизм А: базовое имя в news_categories,
 * языковые варианты здесь). Отдельной записи-категории на язык нет — slug
 * у категории один на все языки, иначе один и тот же раздел ленты получал бы
 * разные адреса и переставал бы совпадать сам с собой при смене языка.
 */
final class NewsCategoryTranslation
{
    /** @return array<string, array<string, mixed>> переводы по коду языка */
    public static function forCategory(int $categoryId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM news_category_translations WHERE category_id = :id'
        );
        $stmt->execute([':id' => $categoryId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    /**
     * Один язык для набора категорий — списки категорий читаются на каждой
     * странице новостей, поэтому N+1 здесь недопустим.
     *
     * @param list<int> $categoryIds
     * @return array<int, string> id категории → переведённое название
     */
    public static function namesForIds(array $categoryIds, string $lang): array
    {
        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($categoryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT category_id, name FROM news_category_translations
             WHERE category_id IN ({$placeholders}) AND lang = ? AND TRIM(COALESCE(name, '')) <> ''"
        );
        $stmt->execute([...$categoryIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['category_id']] = (string) $row['name'];
        }

        return $result;
    }

    public static function upsert(int $categoryId, string $lang, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            self::delete($categoryId, $lang);

            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO news_category_translations (category_id, lang, name)
             VALUES (:category_id, :lang, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        $stmt->execute([
            ':category_id' => $categoryId,
            ':lang' => $lang,
            ':name' => mb_substr($name, 0, 150),
        ]);
        Cache::forgetPrefix('page:');
    }

    public static function delete(int $categoryId, string $lang): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM news_category_translations WHERE category_id = :id AND lang = :lang'
        );
        $stmt->execute([':id' => $categoryId, ':lang' => $lang]);
        Cache::forgetPrefix('page:');
    }

    /**
     * Языки, на которых у категорий есть название (для колонки «Языки»).
     *
     * @param array<int|string> $ids
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

        $in = implode(',', array_fill(0, count($ids), '?'));
        $default = Language::defaultCode();

        $stmtBase = Database::pdo()->prepare("SELECT id, name FROM news_categories WHERE id IN ({$in})");
        $stmtBase->execute($ids);
        foreach ($stmtBase->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (isset($map[$id]) && trim((string) ($row['name'] ?? '')) !== '') {
                $map[$id][] = $default;
            }
        }

        $stmtTrans = Database::pdo()->prepare(
            "SELECT category_id, lang FROM news_category_translations
             WHERE category_id IN ({$in}) AND TRIM(COALESCE(name, '')) <> ''"
        );
        $stmtTrans->execute($ids);
        foreach ($stmtTrans->fetchAll() as $row) {
            $id = (int) $row['category_id'];
            $lang = (string) $row['lang'];
            if (isset($map[$id]) && !in_array($lang, $map[$id], true)) {
                $map[$id][] = $lang;
            }
        }

        return $map;
    }
}
