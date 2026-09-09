<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\TeamMember;

/**
 * Готовые ссылки на состав сектора для схемы оргструктуры.
 *
 * В схеме сектор указывают строкой `Название | /страница#team-slug`. Адрес
 * приходилось собирать вручную: знать slug страницы с блоком «Команда», знать
 * правило якоря и не ошибиться в транслитерации названия. Переименовали сектор
 * — ссылка молча перестала работать. Здесь тот же адрес считается по данным:
 * секторы берутся из карточек сотрудников, страница — из блока «Команда» с
 * включённой группировкой.
 */
final class TeamAnchors
{
    /**
     * Секторы и адреса их состава.
     *
     * @return list<array{name: string, slug: string, count: int, path: string}>
     */
    public static function options(): array
    {
        try {
            $departments = TeamMember::departments();
        } catch (\Throwable $e) {
            Logger::swallowed('TeamAnchors: не удалось прочитать секторы команды', $e);

            return [];
        }
        if ($departments === []) {
            return [];
        }

        $pages = self::groupedTeamPages();

        $options = [];
        foreach ($departments as $department) {
            $slug = (string) $department['slug'];
            // Страница, где блок команды показывает именно этот сектор
            // (фильтр по нему) либо всех сразу.
            $path = $pages[$slug] ?? ($pages[''] ?? '');
            $options[] = [
                'name' => (string) $department['name'],
                'slug' => $slug,
                'count' => (int) $department['count'],
                'path' => $path !== '' ? $path . '#team-' . $slug : '',
            ];
        }

        return $options;
    }

    /**
     * Страницы с блоком «Команда» и группировкой: ключ — фильтр по сектору
     * (пустой = блок показывает все), значение — путь страницы.
     *
     * @return array<string, string>
     */
    private static function groupedTeamPages(): array
    {
        try {
            $rows = Database::pdo()->query(
                // Значение галочки приходит и как JSON true, и как 1 — сверяем
                // распакованную строку (CAST(... AS JSON) не понимает MariaDB).
                "SELECT p.slug, p.is_home,
                        JSON_UNQUOTE(JSON_EXTRACT(b.data, '$.department')) AS department
                 FROM blocks b
                 JOIN pages p ON p.id = b.page_id
                 WHERE b.type = 'team_list' AND b.is_active = 1
                   AND JSON_UNQUOTE(JSON_EXTRACT(b.data, '$.group_by_department')) IN ('true', '1')
                   AND p.entity_type = 'page' AND p.status = 'published' AND p.deleted_at IS NULL
                 ORDER BY p.id ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            Logger::swallowed('TeamAnchors: не удалось найти страницы с блоком команды', $e);

            return [];
        }

        $pages = [];
        foreach ($rows as $row) {
            $department = (string) ($row['department'] ?? '');
            if ($department === 'null') {
                $department = '';
            }
            if (isset($pages[$department])) {
                continue;
            }
            // Главная живёт в корне, у остальных — свой slug.
            $pages[$department] = !empty($row['is_home']) ? '/' : '/' . (string) $row['slug'];
        }

        return $pages;
    }
}
