<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class TeamMemberTranslation
{
    /**
     * @return array<string, array<string, mixed>> переводы по коду языка
     */
    public static function forMember(int $memberId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM team_member_translations WHERE member_id = :id');
        $stmt->execute([':id' => $memberId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    public static function find(int $memberId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM team_member_translations WHERE member_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $memberId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Пакетная загрузка одного перевода для списка сотрудников — устраняет N+1.
     * @param list<int> $memberIds
     * @return array<int, array<string, mixed>>
     */
    public static function forMemberIds(array $memberIds, string $lang): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM team_member_translations WHERE member_id IN ({$placeholders}) AND lang = ?"
        );
        $stmt->execute([...$memberIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['member_id']] = $row;
        }
        return $result;
    }

    public static function upsert(int $memberId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO team_member_translations (member_id, lang, name, position, department, unit)
             VALUES (:member_id, :lang, :name, :position, :department, :unit)
             ON DUPLICATE KEY UPDATE name = VALUES(name), position = VALUES(position),
                department = VALUES(department), unit = VALUES(unit)'
        );
        $stmt->execute([
            ':member_id' => $memberId,
            ':lang' => $lang,
            ':name' => $data['name'] ?? null,
            ':position' => $data['position'] ?? null,
            ':department' => $data['department'] ?? null,
            ':unit' => $data['unit'] ?? null,
        ]);
    }
}
