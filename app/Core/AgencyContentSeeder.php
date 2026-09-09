<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Загрузка реального контента Агентства из database/content/agency_content.php.
 *
 * Вынесено из CLI-скрипта отдельным классом, чтобы тесты гоняли ту же логику на
 * настоящей базе: страницы, языковые версии, иерархию и переводы сотрудников
 * проверяет CI, а не только ручной запуск.
 *
 * Блоки страницы ПЕРЕЗАПИСЫВАЮТСЯ целиком — повторный запуск приводит контент к
 * состоянию фикстуры, поэтому после выкладки правки делают в админке.
 */
final class AgencyContentSeeder
{
    /** @var list<string> строки отчёта для вывода в консоль */
    private array $log = [];

    /**
     * @return array{pages_created:int, pages_updated:int, blocks:int, team_created:int, team_updated:int, log:list<string>}
     */
    public static function run(PDO $pdo, bool $dryRun = false, ?array $fixture = null): array
    {
        return (new self())->apply($pdo, $dryRun, $fixture ?? self::fixture());
    }

    /** @return array<string, mixed> */
    public static function fixture(): array
    {
        $path = (defined('APP_ROOT') ? APP_ROOT : \dirname(__DIR__, 2)) . '/database/content/agency_content.php';
        if (!is_file($path)) {
            throw new RuntimeException('Фикстура контента не найдена: ' . $path);
        }
        /** @var array<string, mixed> $fixture */
        $fixture = require $path;

        return $fixture;
    }

    /**
     * Языки фикстуры, выключенные в разделе «Языки»: страницы создадутся, но на
     * сайте видны не будут — об этом честнее предупредить сразу.
     *
     * @param array<string, mixed> $fixture
     * @return list<string>
     */
    public static function inactiveLangs(array $fixture, PDO $pdo): array
    {
        $active = [];
        try {
            $active = $pdo->query('SELECT code FROM languages WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            return [];
        }

        $used = [];
        foreach ((array) ($fixture['pages'] ?? []) as $langData) {
            foreach (array_keys((array) $langData) as $lang) {
                $used[(string) $lang] = true;
            }
        }

        return array_values(array_diff(array_keys($used), array_map('strval', $active)));
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array{pages_created:int, pages_updated:int, blocks:int, team_created:int, team_updated:int, log:list<string>}
     */
    private function apply(PDO $pdo, bool $dryRun, array $fixture): array
    {
        $pages = (array) ($fixture['pages'] ?? []);
        $hierarchy = (array) ($fixture['hierarchy'] ?? []);
        $team = (array) ($fixture['team'] ?? []);

        $report = ['pages_created' => 0, 'pages_updated' => 0, 'blocks' => 0, 'team_created' => 0, 'team_updated' => 0];
        /** @var array<string, array<string, int>> $pageIds slug => lang => id */
        $pageIds = [];

        $pdo->beginTransaction();

        try {
            foreach ($pages as $slug => $langData) {
                $slug = (string) $slug;
                $langData = (array) $langData;
                // Основной язык идёт первым: его id становится корнем группы
                // переводов, к которой присоединяются остальные версии.
                $ordered = isset($langData['ru']) ? ['ru' => $langData['ru']] + $langData : $langData;
                $groupId = null;

                foreach ($ordered as $lang => $data) {
                    $lang = (string) $lang;
                    $data = (array) $data;

                    $find = $pdo->prepare(
                        "SELECT id FROM pages WHERE slug = :slug AND lang = :lang AND entity_type = 'page' LIMIT 1"
                    );
                    $find->execute([':slug' => $slug, ':lang' => $lang]);
                    $pageId = (int) ($find->fetchColumn() ?: 0);

                    $fields = [
                        ':title' => (string) ($data['title'] ?? ''),
                        ':meta_title' => (string) ($data['meta_title'] ?? ''),
                        ':meta_description' => (string) ($data['meta_description'] ?? ''),
                        ':lead' => (string) ($data['lead'] ?? ''),
                    ];

                    if ($pageId > 0) {
                        $pdo->prepare(
                            "UPDATE pages
                                SET title = :title, meta_title = :meta_title,
                                    meta_description = :meta_description, `lead` = :lead,
                                    status = 'published', deleted_at = NULL
                              WHERE id = :id"
                        )->execute($fields + [':id' => $pageId]);
                        $report['pages_updated']++;
                        $this->log[] = sprintf('  обновлена  /%s [%s] — %s', $slug, $lang, $fields[':title']);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO pages
                                (title, slug, meta_title, meta_description, `lead`, status, is_home,
                                 layout_type, lang, created_at)
                             VALUES (:title, :slug, :meta_title, :meta_description, :lead, 'published', 0,
                                     'no_sidebar', :lang, NOW())"
                        )->execute($fields + [':slug' => $slug, ':lang' => $lang]);
                        $pageId = (int) $pdo->lastInsertId();
                        $report['pages_created']++;
                        $this->log[] = sprintf('  создана    /%s [%s] — %s', $slug, $lang, $fields[':title']);
                    }

                    $groupId ??= $pageId;
                    $pdo->prepare('UPDATE pages SET translation_group_id = :group_id WHERE id = :id')
                        ->execute([':group_id' => $groupId, ':id' => $pageId]);
                    $pageIds[$slug][$lang] = $pageId;

                    // Блоки языковой версии заменяются целиком: страница должна
                    // совпасть с фикстурой, а не смешаться с демо-блоками.
                    $pdo->prepare('DELETE FROM blocks WHERE page_id = :page_id AND lang = :lang')
                        ->execute([':page_id' => $pageId, ':lang' => $lang]);

                    $blockIns = $pdo->prepare(
                        'INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
                         VALUES (:page_id, :lang, :type, :title, :data, :sort_order, 1, NOW())'
                    );
                    foreach (array_values((array) ($data['blocks'] ?? [])) as $index => $block) {
                        [$type, $blockTitle, $blockData] = $block;
                        $payload = json_encode($blockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if (!is_string($payload)) {
                            throw new RuntimeException("Блок {$type} страницы {$slug} [{$lang}] не сериализуется в JSON");
                        }
                        $blockIns->execute([
                            ':page_id' => $pageId,
                            ':lang' => $lang,
                            ':type' => (string) $type,
                            ':title' => (string) $blockTitle,
                            ':data' => $payload,
                            ':sort_order' => $index,
                        ]);
                        $report['blocks']++;
                    }
                }
            }

            // Родители проставляются вторым проходом: к этому моменту созданы
            // все языковые версии, и родитель находится на своём языке.
            foreach ($hierarchy as $childSlug => $parentSlug) {
                foreach ($pageIds[(string) $childSlug] ?? [] as $lang => $childId) {
                    $parentId = $pageIds[(string) $parentSlug][$lang] ?? null;
                    if ($parentId === null || $parentId === $childId) {
                        continue;
                    }
                    $pdo->prepare('UPDATE pages SET parent_id = :parent WHERE id = :id')
                        ->execute([':parent' => $parentId, ':id' => $childId]);
                }
            }

            foreach ($team as $member) {
                $member = (array) $member;
                $name = (string) ($member['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $find = $pdo->prepare('SELECT id FROM team_members WHERE name = :name LIMIT 1');
                $find->execute([':name' => $name]);
                $memberId = (int) ($find->fetchColumn() ?: 0);

                $fields = [
                    ':name' => $name,
                    ':position' => (string) ($member['position'] ?? ''),
                    ':department' => (string) ($member['department'] ?? ''),
                    ':unit' => (string) ($member['unit'] ?? ''),
                    ':sort_order' => (int) ($member['sort_order'] ?? 0),
                ];

                if ($memberId > 0) {
                    $pdo->prepare(
                        "UPDATE team_members
                            SET position = :position, department = :department, unit = :unit,
                                sort_order = :sort_order, status = 'published'
                          WHERE id = :id"
                    )->execute(array_diff_key($fields, [':name' => null]) + [':id' => $memberId]);
                    $report['team_updated']++;
                } else {
                    $pdo->prepare(
                        "INSERT INTO team_members (name, position, department, unit, status, sort_order, created_at)
                         VALUES (:name, :position, :department, :unit, 'published', :sort_order, NOW())"
                    )->execute($fields);
                    $memberId = (int) $pdo->lastInsertId();
                    $report['team_created']++;
                }
                $this->log[] = sprintf('  сотрудник  %s', $name);

                foreach ((array) ($member['translations'] ?? []) as $lang => $translation) {
                    $translation = (array) $translation;
                    $pdo->prepare(
                        'INSERT INTO team_member_translations (member_id, lang, name, position, department, unit)
                         VALUES (:member_id, :lang, :name, :position, :department, :unit)
                         ON DUPLICATE KEY UPDATE name = VALUES(name), position = VALUES(position),
                                                 department = VALUES(department), unit = VALUES(unit)'
                    )->execute([
                        ':member_id' => $memberId,
                        ':lang' => (string) $lang,
                        ':name' => (string) ($translation['name'] ?? ''),
                        ':position' => (string) ($translation['position'] ?? ''),
                        ':department' => (string) ($translation['department'] ?? ''),
                        ':unit' => (string) ($translation['unit'] ?? ''),
                    ]);
                }
            }

            if ($dryRun) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
                Cache::flush();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $report + ['log' => $this->log];
    }
}
