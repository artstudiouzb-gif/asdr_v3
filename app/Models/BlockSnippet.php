<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Библиотека шаблонов блоков (задача 133): именованный набор блоков страницы
 * (type/title/data/custom_css) для повторного применения.
 */
final class BlockSnippet
{
    /**
     * Список шаблонов с кратким составом: выбирать по одному названию — гадать,
     * что внутри.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = Database::pdo()
            ->query('SELECT id, name, blocks_json, created_at FROM block_snippets ORDER BY created_at DESC')
            ->fetchAll();

        foreach ($rows as $i => $row) {
            $blocks = json_decode((string) ($row['blocks_json'] ?? ''), true);
            $rows[$i]['summary'] = is_array($blocks) ? self::summarize($blocks) : '';
            $rows[$i]['blocks_count'] = is_array($blocks) ? count($blocks) : 0;
            unset($rows[$i]['blocks_json']); // в списке не нужен, только вес
        }

        return $rows;
    }

    /**
     * «5 блоков: Обложка, Текст, Контакты…» — состав шаблона одной строкой.
     *
     * @param array<int, mixed> $blocks
     */
    public static function summarize(array $blocks): string
    {
        $labels = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $labels[] = \App\Core\BlockRenderer::TYPE_LABELS[$type] ?? $type;
        }
        if ($labels === []) {
            return '';
        }

        $shown = array_slice($labels, 0, 4);
        $tail = count($labels) > 4 ? '…' : '';

        return count($labels) . ' бл.: ' . implode(', ', $shown) . $tail;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM block_snippets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<int, array{type:string, title:?string, data:array, custom_css:string}> $blocks
     */
    public static function create(string $name, array $blocks): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO block_snippets (name, blocks_json, created_at) VALUES (:name, :json, NOW())'
        );
        $stmt->execute([
            ':name' => $name,
            ':json' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM block_snippets WHERE id = :id')->execute([':id' => $id]);
    }

    /** Префикс автокопий: по нему их видно в списке и чистится история. */
    public const AUTO_PREFIX = 'Автокопия: ';

    /** Сколько автокопий храним — дальше библиотека превращается в свалку. */
    private const AUTO_KEEP = 5;

    /**
     * Снимок страницы перед разрушающей операцией («заменить все блоки»).
     * История версий пишется при правке блока, а не при удалении, поэтому без
     * такой копии заменённая страница не восстанавливалась бы никак.
     *
     * @return string|null название копии; null — копировать было нечего
     */
    public static function autoBackup(int $pageId, string $lang, string $pageTitle): ?string
    {
        $blocks = self::captureFromPage($pageId, $lang);
        if ($blocks === []) {
            return null;
        }

        $title = trim($pageTitle) !== '' ? trim($pageTitle) : ('страница #' . $pageId);
        $name = self::AUTO_PREFIX . mb_substr($title, 0, 80) . ' (' . $lang . ') — ' . date('d.m.Y H:i');
        self::create($name, $blocks);
        self::pruneAuto();

        return $name;
    }

    /** Оставляет только последние AUTO_KEEP автокопий. */
    private static function pruneAuto(): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM block_snippets WHERE name LIKE :prefix ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':prefix' => self::AUTO_PREFIX . '%']);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach (array_slice($ids, self::AUTO_KEEP) as $id) {
            self::delete((int) $id);
        }
    }

    /**
     * Снимок всех блоков страницы (языкового стека) как шаблон целой страницы:
     * верхний уровень + дочерние блоки колонок (children) + активность.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function captureFromPage(int $pageId, string $lang): array
    {
        $blocks = [];
        foreach (Block::forPage($pageId, $lang) as $block) {
            $item = [
                'type' => (string) $block['type'],
                'title' => $block['title'] !== null ? (string) $block['title'] : null,
                'data' => json_decode((string) $block['data'], true) ?: [],
                'custom_css' => (string) ($block['custom_css'] ?? ''),
                'is_active' => (int) ($block['is_active'] ?? 1),
            ];
            $children = Block::childrenOf((int) $block['id']);
            if ($children !== []) {
                $item['children'] = array_map(static fn (array $c): array => [
                    'column_index' => (int) $c['column_index'],
                    'type' => (string) $c['type'],
                    'title' => $c['title'] !== null ? (string) $c['title'] : null,
                    'data' => json_decode((string) $c['data'], true) ?: [],
                    'custom_css' => (string) ($c['custom_css'] ?? ''),
                    'is_active' => (int) ($c['is_active'] ?? 1),
                ], $children);
            }
            $blocks[] = $item;
        }

        return $blocks;
    }

    /**
     * Применяет шаблон к странице. $replace = true — сначала удаляет только
     * текущие блоки выбранного языка этой страницы (дочерние уходят каскадом
     * по FK). Другие переводы не затрагиваются. Блоки
     * получают новые id (важно для изоляции custom_css по #block-{id}).
     *
     * @param array<int, mixed> $blocks
     * @return int сколько блоков создано (включая дочерние)
     */
    public static function applyToPage(array $blocks, int $pageId, string $lang, bool $replace = false): int
    {
        if ($replace) {
            $pdo = Database::pdo();
            $stmtOld = $pdo->prepare(
                'SELECT id FROM blocks WHERE page_id = :page_id AND lang = :lang ORDER BY parent_block_id IS NULL DESC'
            );
            $stmtOld->execute([':page_id' => $pageId, ':lang' => $lang]);
            foreach ($stmtOld->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
                Block::delete((int) $oldId);
            }
        }

        $count = 0;
        foreach ($blocks as $b) {
            if (!is_array($b) || (string) ($b['type'] ?? '') === '') {
                continue;
            }
            $parentId = self::createFromSnapshot($b, $pageId, $lang, null, 0);
            $count++;
            foreach ((array) ($b['children'] ?? []) as $c) {
                if (!is_array($c) || (string) ($c['type'] ?? '') === '') {
                    continue;
                }
                self::createFromSnapshot($c, $pageId, $lang, $parentId, (int) ($c['column_index'] ?? 0));
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $b */
    private static function createFromSnapshot(array $b, int $pageId, string $lang, ?int $parentId, int $columnIndex): int
    {
        $id = Block::create(
            $pageId,
            $lang,
            (string) $b['type'],
            isset($b['title']) && $b['title'] !== '' ? (string) $b['title'] : null,
            is_array($b['data'] ?? null) ? $b['data'] : [],
            (string) ($b['custom_css'] ?? ''),
            $parentId,
            $columnIndex
        );
        if (isset($b['is_active']) && (int) $b['is_active'] === 0) {
            Block::setActive($id, false);
        }

        return $id;
    }
}
