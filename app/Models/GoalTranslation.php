<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Перевод названия и описания цели (механизм А: перевод накладывается на
 * базовую строку). Отдельной записи на язык у цели нет — держать стабильным
 * нечего, публичного адреса у неё не существует.
 */
final class GoalTranslation
{
    /** @return array<string, array<string, mixed>> переводы по коду языка */
    public static function forGoal(int $goalId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM goal_translations WHERE goal_id = :id');
        $stmt->execute([':id' => $goalId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $goalId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM goal_translations WHERE goal_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $goalId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Пакетная загрузка одного перевода для списка целей — устраняет N+1 на
     * странице списка админки.
     *
     * @param list<int> $goalIds
     * @return array<int, array<string, mixed>>
     */
    public static function forGoalIds(array $goalIds, string $lang): array
    {
        $goalIds = array_values(array_unique(array_filter(
            array_map('intval', $goalIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($goalIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goalIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM goal_translations WHERE goal_id IN ({$placeholders}) AND lang = ?"
        );
        $stmt->execute([...$goalIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['goal_id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function upsert(int $goalId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO goal_translations (goal_id, lang, name, description)
             VALUES (:goal_id, :lang, :name, :description)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        $stmt->execute([
            ':goal_id' => $goalId,
            ':lang' => $lang,
            ':name' => $data['name'] ?? null,
            ':description' => $data['description'] ?? null,
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }
}
