<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Slug;
use App\Core\Translations;

final class TeamMember
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM team_members ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    public static function published(?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        if ($lang === Language::defaultCode()) {
            $stmt = Database::pdo()->query(
                "SELECT * FROM team_members WHERE status = 'published' ORDER BY sort_order ASC, id ASC"
            );

            return $stmt->fetchAll();
        }

        $stmt = Database::pdo()->prepare(
            "SELECT tm.* FROM team_members tm
             INNER JOIN team_member_translations tmt
                ON tmt.member_id = tm.id AND tmt.lang = :lang
               AND (
                    TRIM(COALESCE(tmt.name, '')) <> ''
                    OR TRIM(COALESCE(tmt.position, '')) <> ''
               )
             WHERE tm.status = 'published'
             ORDER BY tm.sort_order ASC, tm.id ASC"
        );
        $stmt->execute([':lang' => $lang]);
        $rows = $stmt->fetchAll();

        return self::localizeRows($rows, $lang);
    }

    /**
     * Накладывает перевод указанного языка на базовую строку. Пустые поля
     * перевода откатываются к значению основного языка (graceful fallback).
     */
    public static function localize(array $row, string $lang): array
    {
        return self::applyTranslation($row, TeamMemberTranslation::find((int) $row['id'], $lang));
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private static function localizeRows(array $rows, string $lang): array
    {
        $translations = TeamMemberTranslation::forMemberIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $lang
        );
        return array_map(
            static fn (array $row): array => self::applyTranslation($row, $translations[(int) $row['id']] ?? null),
            $rows
        );
    }

    /**
     * Якорь отдела для ссылок из схемы оргструктуры. Считается от названия на
     * основном языке, поэтому одна и та же ссылка работает на всех языках.
     */
    public static function departmentSlug(array $row): string
    {
        $base = trim((string) ($row['department_base'] ?? $row['department'] ?? ''));

        return $base !== '' ? Slug::make($base) : '';
    }

    /**
     * Отделы опубликованных сотрудников в порядке сортировки команды.
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    public static function departments(?string $lang = null): array
    {
        $groups = [];
        foreach (self::published($lang) as $row) {
            $name = trim((string) ($row['department'] ?? ''));
            $slug = self::departmentSlug($row);
            if ($name === '' || $slug === '') {
                continue;
            }
            if (!isset($groups[$slug])) {
                $groups[$slug] = ['name' => $name, 'slug' => $slug, 'count' => 0];
            }
            $groups[$slug]['count']++;
        }

        return array_values($groups);
    }

    /**
     * Раскладывает сотрудников по секторам, а внутри сектора — по отделам и
     * группам. Сотрудники без сектора уходят в последнюю группу с пустым
     * названием — их не должно «потерять».
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{name: string, slug: string, members: list<array<string, mixed>>, units: list<array{name: string, members: list<array<string, mixed>>}>}>
     */
    public static function groupByDepartment(array $rows): array
    {
        $groups = [];
        $rest = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['department'] ?? ''));
            $slug = self::departmentSlug($row);
            if ($name === '' || $slug === '') {
                $rest[] = $row;
                continue;
            }
            if (!isset($groups[$slug])) {
                $groups[$slug] = ['name' => $name, 'slug' => $slug, 'members' => [], 'units' => []];
            }

            $unit = trim((string) ($row['unit'] ?? ''));
            if ($unit === '') {
                $groups[$slug]['members'][] = $row;
                continue;
            }
            if (!isset($groups[$slug]['units'][$unit])) {
                $groups[$slug]['units'][$unit] = ['name' => $unit, 'members' => []];
            }
            $groups[$slug]['units'][$unit]['members'][] = $row;
        }

        $result = [];
        foreach ($groups as $group) {
            $group['units'] = array_values($group['units']);
            $result[] = $group;
        }
        if ($rest !== []) {
            $result[] = ['name' => '', 'slug' => '', 'members' => $rest, 'units' => []];
        }

        return $result;
    }

    private static function applyTranslation(array $row, ?array $translation): array
    {
        // Базовое название отдела сохраняем до наложения перевода: якорь
        // ссылки не должен меняться вместе с языком страницы.
        $row['department_base'] = (string) ($row['department'] ?? '');

        return Translations::overlayFields($row, $translation, ['name', 'position', 'department', 'unit']);
    }

    /**
     * Языки контента для набора сотрудников одним запросом (без N+1).
     * Контент на языке = непустой перевод имени или должности.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    /**
     * Языки контента для набора сотрудников одним запросом (без N+1).
     * Разбор — общий для всех сущностей механизма А: полсотни строк, которые
     * повторялись здесь дословно, живут в `Translations::availableLangs()`.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    public static function availableLangsForIds(array $ids): array
    {
        return Translations::availableLangs('team_members', $ids, ['name', 'position', 'department', 'unit']);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM team_members WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(array $data): int
    {
        // Всё, кроме имени и статуса, в схеме NULL-able, поэтому отсутствующий
        // ключ — это NULL, а не ошибка. Раньше значения читались напрямую:
        // вызов без телефона печатал «Undefined array key», а в рабочем режиме
        // ErrorHandler превращает предупреждение в исключение — 500 на ровном
        // месте у формы, где поле просто не заполнили.
        $stmt = Database::pdo()->prepare(
            'INSERT INTO team_members (name, position, department, unit, photo, email, phone, socials_json, status, sort_order, created_at)
             VALUES (:name, :position, :department, :unit, :photo, :email, :phone, :socials_json, :status, :sort_order, NOW())'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':position' => $data['position'] ?? null,
            ':department' => $data['department'] ?? null,
            ':unit' => $data['unit'] ?? null,
            ':photo' => $data['photo'] ?? null,
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':socials_json' => json_encode($data['socials'] ?? [], JSON_UNESCAPED_UNICODE),
            ':status' => $data['status'],
            ':sort_order' => $data['sort_order'] ?? 0,
        ]);

        // id читаем до сброса кэша: bustPageCache() делает запрос к settings,
        // который обнуляет lastInsertId() (см. Project::create).
        $id = (int) Database::pdo()->lastInsertId();
        self::bustPageCache();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE team_members SET name = :name, position = :position, department = :department, unit = :unit,
             photo = :photo, email = :email, phone = :phone, socials_json = :socials_json, status = :status,
             sort_order = :sort_order WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':position' => $data['position'] ?? null,
            ':department' => $data['department'] ?? null,
            ':unit' => $data['unit'] ?? null,
            ':photo' => $data['photo'] ?? null,
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':socials_json' => json_encode($data['socials'] ?? [], JSON_UNESCAPED_UNICODE),
            ':status' => $data['status'],
            ':sort_order' => $data['sort_order'] ?? 0,
            ':id' => $id,
        ]);
        self::bustPageCache();
    }

    /**
     * Смена статуса без переписывания остальных полей — нужна массовым
     * действиям списка, которым незачем перечитывать сотрудника целиком.
     */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'published'], true)) {
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE team_members SET status = :s WHERE id = :id');
        $stmt->execute([':s' => $status, ':id' => $id]);
        self::bustPageCache();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM team_members WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::bustPageCache();
    }

    private static function bustPageCache(): void
    {
        \App\Core\Cache::forgetPrefix('page:');
    }
}
