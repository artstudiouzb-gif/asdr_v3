<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\ConcurrencyException;
use App\Core\Database;
use App\Core\Logger;

final class Block
{
    /**
     * Блоки страницы для конкретного языкового стека. По умолчанию — только
     * верхнего уровня (parent_block_id IS NULL); дочерние блоки колонок
     * (группа 4.1) выбираются отдельно через childrenOf().
     */
    public static function forPage(int $pageId, ?string $lang = null, bool $topLevelOnly = true, bool $activeOnly = false): array
    {
        $lang = $lang ?? Language::defaultCode();
        $sql = 'SELECT * FROM blocks WHERE page_id = :page_id AND lang = :lang';
        if ($topLevelOnly) {
            $sql .= ' AND parent_block_id IS NULL';
        }
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':page_id' => $pageId, ':lang' => $lang]);

        return $stmt->fetchAll();
    }

    /**
     * Дочерние блоки родителя-колонок, сгруппированные по номеру колонки
     * (группа 4.1). Порядок: колонка, затем позиция внутри неё.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function childrenOf(int $parentBlockId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM blocks WHERE parent_block_id = :pid';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY column_index ASC, sort_order ASC, id ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':pid' => $parentBlockId]);

        return $stmt->fetchAll();
    }

    /**
     * Блоки для вывода на сайте: только активные; приоритеты:
     * 1. Собственные блоки текущей страницы нужного языка
     * 2. Собственные блоки текущей страницы языка по умолчанию
     * 3. Блоки родительской страницы группы переводов нужного языка
     * 4. Блоки родительской страницы группы переводов языка по умолчанию
     */
    public static function forPageLocalized(int $pageId, string $lang): array
    {
        // 1. Блоки самой текущей страницы для запрошенного языка
        $blocks = self::forPage($pageId, $lang, true, true);
        if (!empty($blocks)) {
            return $blocks;
        }

        // 2. Блоки самой текущей страницы на языке по умолчанию
        $defaultBlocks = self::forPage($pageId, Language::defaultCode(), true, true);
        if (!empty($defaultBlocks)) {
            return $defaultBlocks;
        }

        // 3. Фолбэк на родительскую страницу группы переводов
        try {
            $stmtPage = Database::pdo()->prepare("SELECT translation_group_id FROM pages WHERE id = :id LIMIT 1");
            $stmtPage->execute([':id' => $pageId]);
            $groupId = (int) $stmtPage->fetchColumn();
            if ($groupId > 0 && $groupId !== $pageId) {
                $parentBlocks = self::forPage($groupId, $lang, true, true);
                if (!empty($parentBlocks)) {
                    return $parentBlocks;
                }

                return self::forPage($groupId, Language::defaultCode(), true, true);
            }
        } catch (\Throwable $e) {
            Logger::swallowed('Block::forPageLocalized: фолбэк на страницу группы переводов не сработал', $e);
        }

        return [];
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE blocks SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $active ? 1 : 0, ':id' => $id]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM blocks WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(
        int $pageId,
        string $lang,
        string $type,
        ?string $title,
        array $data,
        string $customCss,
        ?int $parentBlockId = null,
        int $columnIndex = 0
    ): int {
        // Порядок считаем в пределах одного родителя (или верхнего уровня) и колонки.
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM blocks
             WHERE page_id = :page_id AND lang = :lang AND parent_block_id <=> :parent AND column_index = :col'
        );
        $stmt->execute([':page_id' => $pageId, ':lang' => $lang, ':parent' => $parentBlockId, ':col' => $columnIndex]);
        $nextOrder = (int) $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, custom_css, sort_order, created_at)
             VALUES (:page_id, :parent, :col, :lang, :type, :title, :data, :custom_css, :sort_order, NOW())'
        );
        $stmt->execute([
            ':page_id' => $pageId,
            ':parent' => $parentBlockId,
            ':col' => $columnIndex,
            ':lang' => $lang,
            ':type' => $type,
            ':title' => $title,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':custom_css' => $customCss,
            ':sort_order' => $nextOrder,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(
        int $id,
        ?string $title,
        array $data,
        string $customCss,
        ?int $expectedLockVersion = null
    ): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE blocks
             SET title = :title, data = :data, custom_css = :custom_css, lock_version = lock_version + 1
             WHERE id = :id' . ($expectedLockVersion !== null ? ' AND lock_version = :expected_lock_version' : '')
        );
        $params = [
            ':title' => $title,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':custom_css' => $customCss,
            ':id' => $id,
        ];
        if ($expectedLockVersion !== null) {
            $params[':expected_lock_version'] = $expectedLockVersion;
        }
        $stmt->execute($params);
        if ($expectedLockVersion !== null && $stmt->rowCount() !== 1) {
            throw new ConcurrencyException('Блок был изменён другим пользователем.');
        }
    }

    public static function delete(int $id): void
    {
        $pdo = Database::pdo();
        $stmtChildren = $pdo->prepare('DELETE FROM blocks WHERE parent_block_id = :pid');
        $stmtChildren->execute([':pid' => $id]);

        $stmt = $pdo->prepare('DELETE FROM blocks WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Устанавливает порядок блоков по заданному списку id (drag-and-drop,
     * задача 134). Учитываются только блоки, реально принадлежащие странице
     * и языку; посторонние id игнорируются.
     *
     * @param array<int, int> $orderedIds
     */
    public static function reorder(int $pageId, string $lang, array $orderedIds): void
    {
        $valid = [];
        foreach (self::forPage($pageId, $lang) as $block) {
            $valid[(int) $block['id']] = true;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE blocks SET sort_order = :order WHERE id = :id AND page_id = :pid AND lang = :lang');
            $order = 1;
            foreach ($orderedIds as $id) {
                $id = (int) $id;
                if (!isset($valid[$id])) {
                    continue;
                }
                $stmt->execute([':order' => $order++, ':id' => $id, ':pid' => $pageId, ':lang' => $lang]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function moveUp(int $id, int $pageId, string $lang): void
    {
        self::swap($id, $pageId, $lang, 'up');
    }

    public static function moveDown(int $id, int $pageId, string $lang): void
    {
        self::swap($id, $pageId, $lang, 'down');
    }

    private static function swap(int $id, int $pageId, string $lang, string $direction): void
    {
        $blocks = self::forPage($pageId, $lang);
        $index = null;
        foreach ($blocks as $i => $block) {
            if ((int) $block['id'] === $id) {
                $index = (int) $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex < 0 || $swapIndex >= count($blocks)) {
            return;
        }

        $current = $blocks[$index];
        $target = $blocks[$swapIndex];

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE blocks SET sort_order = :order WHERE id = :id');
            $stmt->execute([':order' => $target['sort_order'], ':id' => $current['id']]);
            $stmt->execute([':order' => $current['sort_order'], ':id' => $target['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function copyLanguageBlocks(int $pageId, string $fromLang, string $toLang): int
    {
        $sourceBlocks = self::forPage($pageId, $fromLang, true);
        if (empty($sourceBlocks)) {
            return 0;
        }

        $pdo = Database::pdo();
        $stmtDel = $pdo->prepare("SELECT id FROM blocks WHERE page_id = :pid AND lang = :lang");
        $stmtDel->execute([':pid' => $pageId, ':lang' => $toLang]);
        foreach ($stmtDel->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
            self::delete((int) $oldId);
        }

        $count = 0;
        foreach ($sourceBlocks as $b) {
            $data = is_array($b['data']) ? $b['data'] : (json_decode((string) $b['data'], true) ?: []);
            $newParentId = self::create(
                $pageId,
                $toLang,
                (string) $b['type'],
                $b['title'] !== null ? (string) $b['title'] : null,
                $data,
                (string) ($b['custom_css'] ?? ''),
                null,
                (int) ($b['column_index'] ?? 0)
            );
            $count++;

            $children = self::childrenOf((int) $b['id']);
            foreach ($children as $c) {
                $cData = is_array($c['data']) ? $c['data'] : (json_decode((string) $c['data'], true) ?: []);
                self::create(
                    $pageId,
                    $toLang,
                    (string) $c['type'],
                    $c['title'] !== null ? (string) $c['title'] : null,
                    $cData,
                    (string) ($c['custom_css'] ?? ''),
                    $newParentId,
                    (int) ($c['column_index'] ?? 0)
                );
                $count++;
            }
        }

        return $count;
    }
}
