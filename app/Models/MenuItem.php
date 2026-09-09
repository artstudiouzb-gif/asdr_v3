<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Icon;
use App\Core\Lang;
use App\Core\Locale;

final class MenuItem
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $activeRequestCache = [];

    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM menu_items ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * Активные пункты только указанного языка.
     *
     * Общие пункты и fallback на основной язык намеренно запрещены: иначе
     * локализованная шапка может вести посетителя на чужой контент.
     */
    public static function activeForLang(string $lang): array
    {
        $lang = self::normalizeLang($lang);
        if (isset(self::$activeRequestCache[$lang])) {
            return self::$activeRequestCache[$lang];
        }
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM menu_items WHERE is_active = 1 AND lang = :lang
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':lang' => $lang]);
        $items = $stmt->fetchAll();

        // Цели пунктов разрешаются пакетом: поштучно это давало по запросу на
        // пункт, то есть 36 обращений к базе на каждой странице сайта.
        $pageValues = [];
        foreach ($items as $row) {
            if ((string) $row['url_type'] === 'page') {
                $pageValues[] = (string) $row['url_value'];
            }
        }
        $targets = $pageValues === [] ? [] : Page::publishedMenuTargets($pageValues, $lang);

        $rows = [];
        foreach ($items as $row) {
            if ((string) $row['url_type'] === 'page') {
                $page = $targets[(string) $row['url_value']] ?? null;
                if ($page === null) {
                    continue;
                }
                $row['url_value'] = Page::menuTargetValue($page);
            }
            $rows[] = $row;
        }

        return self::$activeRequestCache[$lang] = self::buildTree($rows);
    }

    /**
     * Переписывает произвольные ссылки меню с одного адреса на другой.
     *
     * Нужна ровно одному случаю: раздел каталога переехал в корень
     * (`/catalog/documenty` → `/documenty`). Прежний адрес продолжает работать
     * редиректом, но пункт меню, оставшийся на нём, перестал бы подсвечиваться
     * — посетитель приходит уже на новый адрес, а меню сравнивает со старым.
     * Искать такие пункты руками по всем языкам редактор не должен.
     *
     * Сравнение точное, с учётом записи со слэшем и без: адрес пункта — это
     * ровно тот раздел, а не всё, что начинается на него.
     *
     * @return int сколько пунктов переписано
     */
    public static function retargetCustomUrl(string $from, string $to): int
    {
        $from = '/' . trim($from, '/');
        $to = '/' . trim($to, '/');
        if ($from === $to || $from === '/' || $to === '/') {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            "UPDATE menu_items SET url_value = :to
              WHERE url_type = 'custom' AND url_value IN (:from, :from_slash, :from_bare)"
        );
        $stmt->execute([
            ':to' => $to,
            ':from' => $from,
            ':from_slash' => $from . '/',
            ':from_bare' => ltrim($from, '/'),
        ]);
        self::$activeRequestCache = [];

        return $stmt->rowCount();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $lang = self::normalizeLang($data['lang'] ?? '');
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        // Порядок считаем в пределах одного родителя и языка.
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items
             WHERE lang = :lang AND parent_id <=> :parent'
        );
        $stmt->execute([':lang' => $lang, ':parent' => $parentId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO menu_items (lang, title, icon_svg, hide_title, badge_text, badge_color, badge_pos, is_divider, url_type, url_value, parent_id, mega_columns, sort_order, is_active, created_at)
             VALUES (:lang, :title, :icon_svg, :hide_title, :badge_text, :badge_color, :badge_pos, :is_divider, :url_type, :url_value, :parent_id, :mega_columns, :sort_order, :is_active, NOW())'
        );
        $stmt->execute([
            ':lang' => $lang,
            ':title' => $data['title'],
            ':icon_svg' => self::cleanIcon($data['icon_svg'] ?? null),
            ':hide_title' => !empty($data['hide_title']) ? 1 : 0,
            ':badge_text' => self::cleanBadgeText($data['badge_text'] ?? null),
            ':badge_color' => self::cleanBadgeColor($data['badge_color'] ?? null),
            ':badge_pos' => self::cleanBadgePos($data['badge_pos'] ?? null),
            ':is_divider' => !empty($data['is_divider']) ? 1 : 0,
            ':url_type' => $data['url_type'],
            ':url_value' => $data['url_value'],
            ':parent_id' => $parentId,
            ':mega_columns' => self::megaColumns($data['mega_columns'] ?? 0, $parentId),
            ':sort_order' => $nextOrder,
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        self::$activeRequestCache = [];
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $lang = self::normalizeLang($data['lang'] ?? '');
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $current = self::findById($id);
        $sortOrder = (int) ($current['sort_order'] ?? 0);
        $parentChanged = $current !== null
            && ((string) $current['lang'] !== $lang
                || (($current['parent_id'] === null) !== ($parentId === null))
                || ($parentId !== null && (int) $current['parent_id'] !== $parentId));
        if ($parentChanged) {
            $orderStmt = Database::pdo()->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items
                 WHERE lang = :lang AND parent_id <=> :parent AND id <> :id'
            );
            $orderStmt->execute([':lang' => $lang, ':parent' => $parentId, ':id' => $id]);
            $sortOrder = (int) $orderStmt->fetchColumn();
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE menu_items SET lang = :lang, title = :title, icon_svg = :icon_svg, hide_title = :hide_title,
             badge_text = :badge_text, badge_color = :badge_color, badge_pos = :badge_pos,
             is_divider = :is_divider, url_type = :url_type, url_value = :url_value,
             parent_id = :parent_id, mega_columns = :mega_columns,
             sort_order = :sort_order, is_active = :is_active WHERE id = :id'
        );
        $stmt->execute([
            ':lang' => $lang,
            ':title' => $data['title'],
            ':icon_svg' => self::cleanIcon($data['icon_svg'] ?? null),
            ':hide_title' => !empty($data['hide_title']) ? 1 : 0,
            ':badge_text' => self::cleanBadgeText($data['badge_text'] ?? null),
            ':badge_color' => self::cleanBadgeColor($data['badge_color'] ?? null),
            ':badge_pos' => self::cleanBadgePos($data['badge_pos'] ?? null),
            ':is_divider' => !empty($data['is_divider']) ? 1 : 0,
            ':url_type' => $data['url_type'],
            ':url_value' => $data['url_value'],
            ':parent_id' => $parentId,
            ':mega_columns' => self::megaColumns($data['mega_columns'] ?? 0, $parentId),
            ':sort_order' => $sortOrder,
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':id' => $id,
        ]);
        self::$activeRequestCache = [];
    }

    public static function cleanBadgeText(mixed $value): ?string
    {
        $val = trim((string) $value);
        return $val !== '' ? mb_substr($val, 0, 100) : null;
    }

    public static function normalizeLang(mixed $value): string
    {
        $lang = strtolower(trim((string) $value));

        return $lang !== '' && Language::isActive($lang) ? $lang : Language::defaultCode();
    }

    public static function cleanBadgeColor(mixed $value): string
    {
        $val = strtolower(trim((string) $value));
        return in_array($val, ['red', 'green', 'blue', 'orange', 'purple'], true) ? $val : 'red';
    }

    /**
     * Число колонок мега-меню: 0 (обычная выпадашка) либо 2..4. У вложенного
     * пункта мега-меню быть не может — раскладку задаёт только верхний уровень.
     */
    public static function megaColumns(mixed $value, ?int $parentId = null): int
    {
        if ($parentId !== null) {
            return 0;
        }
        $columns = (int) $value;

        return $columns >= 2 && $columns <= 4 ? $columns : 0;
    }

    /** В БД хранится только безопасный ключ Tabler Icons, без SVG-разметки. */
    private static function cleanIcon(mixed $svg): ?string
    {
        $name = Icon::cleanName($svg);

        return $name !== '' ? $name : null;
    }

    /**
     * Проверяет допустимость назначения родителя (глубина максимум 1 уровень,
     * без циклов и «внуков»). Возвращает текст ошибки или null, если всё ок.
     *
     * @param int|null $selfId id редактируемого пункта (null при создании)
     */
    public static function validateParent(?int $parentId, ?int $selfId, string $lang): ?string
    {
        $lang = self::normalizeLang($lang);
        if ($selfId !== null && self::hasChildrenWithOtherLanguage($selfId, $lang)) {
            return 'Сначала приведите язык вложенных пунктов в соответствие с родительским.';
        }
        if ($parentId === null) {
            return null; // Пункт верхнего уровня — всегда допустимо.
        }
        if ($selfId !== null && $parentId === $selfId) {
            return 'Пункт не может быть родителем самому себе.';
        }
        $parent = self::findById($parentId);
        if (!$parent) {
            return 'Выбранный родительский пункт не найден.';
        }
        // Глубина ограничена одним уровнем: у родителя не должно быть своего родителя.
        if ($parent['parent_id'] !== null) {
            return 'Меню поддерживает только один уровень вложенности.';
        }
        // Родитель и потомок должны быть на одном языке (или оба «для всех»).
        if ((string) $parent['lang'] !== $lang) {
            return 'Родитель и вложенный пункт должны быть на одном языке.';
        }
        if (!empty($parent['is_divider'])) {
            return 'Разделитель не может быть родительским пунктом.';
        }
        // Нельзя вкладывать пункт, у которого уже есть свои дети (появились бы «внуки»).
        if ($selfId !== null && self::hasChildren($selfId)) {
            return 'У этого пункта есть вложенные — сначала перенесите или удалите их.';
        }

        return null;
    }

    private static function hasChildrenWithOtherLanguage(int $id, string $lang): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM menu_items WHERE parent_id = :id AND lang <> :lang LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':lang' => $lang]);

        return $stmt->fetchColumn() !== false;
    }

    public static function hasChildren(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM menu_items WHERE parent_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Пункты, пригодные быть родителями для указанного языка: только верхнего
     * уровня (parent_id IS NULL), исключая сам редактируемый пункт.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parentCandidates(string $lang, ?int $excludeId = null): array
    {
        $lang = self::normalizeLang($lang);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM menu_items WHERE parent_id IS NULL AND lang = :lang
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':lang' => $lang]);
        $rows = $stmt->fetchAll();

        return array_values(array_filter($rows, static fn ($r) => $excludeId === null || (int) $r['id'] !== $excludeId));
    }

    /**
     * Пакетное сохранение порядка и вложенности (AJAX drag-and-drop, задача 3).
     * Каждый элемент: ['id'=>int, 'parent_id'=>?int, 'sort_order'=>int].
     * Отклоняет попытки превысить глубину/создать цикл (валидируется на сервере).
     *
     * @param array<int,array{id:int,parent_id:?int,sort_order:int}> $rows
     */
    public static function reorder(array $rows): void
    {
        // Индекс всех пунктов для проверки глубины.
        $byId = [];
        foreach (self::all() as $r) {
            $byId[(int) $r['id']] = $r;
        }
        if ($rows === []) {
            throw new \DomainException('Список пунктов меню пуст. Обновите страницу и повторите попытку.');
        }

        // Полная карта будущих родителей: неизменяемые строки тоже участвуют
        // в проверке, поэтому частичный запрос не может создать скрытых «внуков».
        $futureParent = [];
        foreach ($byId as $id => $row) {
            $futureParent[$id] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($byId[$id])) {
                throw new \DomainException('Один из пунктов меню больше не существует. Обновите страницу.');
            }
            if (isset($seen[$id])) {
                throw new \DomainException('Получен повторяющийся пункт меню. Обновите страницу.');
            }
            $seen[$id] = true;
            $futureParent[$id] = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        }

        // Проверяем всю будущую структуру до первого UPDATE: либо применятся
        // все изменения, либо ни одного. Это исключает частично сохранённое меню.
        foreach ($futureParent as $id => $parent) {
            if (!empty($byId[$id]['is_divider']) && $parent !== null) {
                throw new \DomainException('Разделитель нельзя сделать вложенным пунктом.');
            }
            if ($parent === null) {
                continue;
            }
            if ($parent === $id) {
                throw new \DomainException('Пункт меню не может быть родителем самому себе.');
            }
            if (!isset($byId[$parent])) {
                throw new \DomainException('Родительский пункт больше не существует. Обновите страницу.');
            }
            if (!empty($byId[$parent]['is_divider'])) {
                throw new \DomainException('Разделитель не может содержать вложенные пункты.');
            }
            if ((string) $byId[$parent]['lang'] !== (string) $byId[$id]['lang']) {
                throw new \DomainException('Нельзя объединять пункты меню разных языков.');
            }
            $parentOfParent = $futureParent[$parent];
            if ($parentOfParent !== null) {
                throw new \DomainException('Меню поддерживает только один уровень вложенности.');
            }
        }

        // Нормализуем порядок отдельно внутри каждого языка и родителя.
        $groupOrder = [];
        $normalizedOrder = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parent = $futureParent[$id];
            $group = (string) $byId[$id]['lang'] . ':' . ($parent ?? 0);
            $groupOrder[$group] = ($groupOrder[$group] ?? 0) + 1;
            $normalizedOrder[$id] = $groupOrder[$group];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE menu_items SET parent_id = :parent, sort_order = :order WHERE id = :id');
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $parent = $futureParent[$id];
                $stmt->execute([
                    ':parent' => $parent,
                    ':order' => $normalizedOrder[$id],
                    ':id' => $id,
                ]);
            }
            $pdo->commit();
            self::$activeRequestCache = [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Строит двухуровневое дерево из плоского списка строк: пункты верхнего
     * уровня получают ключ 'children' с отсортированными дочерними пунктами.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function buildTree(array $rows): array
    {
        $top = [];
        $children = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] === null) {
                $r['children'] = [];
                $top[(int) $r['id']] = $r;
            } else {
                $children[(int) $r['parent_id']][] = $r;
            }
        }
        foreach ($children as $parentId => $kids) {
            if (isset($top[$parentId])) {
                $top[$parentId]['children'] = $kids;
            }
            // «Осиротевшие» дети (родитель неактивен/не в выборке) не показываем.
        }

        return array_values($top);
    }

    /**
     * Заменяет меню целевого языка структурой исходного языка.
     *
     * Ссылки на страницы сопоставляются через translation_group_id и ведут
     * только на опубликованную запись целевого языка. Если перевода страницы
     * нет, пункт (и его вложенные пункты) не копируется. Уже переведённые
     * названия совпадающих пунктов целевого меню сохраняются.
     *
     * @return array{created:int,skipped:int,replaced:int}
     */
    public static function synchronizeLanguage(string $sourceLang, string $targetLang): array
    {
        $sourceLang = strtolower(trim($sourceLang));
        $targetLang = strtolower(trim($targetLang));
        if (!Language::isActive($sourceLang) || !Language::isActive($targetLang)) {
            throw new \DomainException('Выберите два активных языка меню.');
        }
        if ($sourceLang === $targetLang) {
            throw new \DomainException('Исходный и целевой языки должны отличаться.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM menu_items WHERE lang = :lang ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':lang' => $sourceLang]);
        $sourceRows = $stmt->fetchAll();
        if ($sourceRows === []) {
            throw new \DomainException('В исходном языке нет пунктов для синхронизации.');
        }

        $stmt->execute([':lang' => $targetLang]);
        $targetRows = $stmt->fetchAll();
        $boundary = $pdo->prepare(
            'SELECT 1
             FROM menu_items child
             INNER JOIN menu_items parent ON parent.id = child.parent_id
             WHERE child.lang <> parent.lang
               AND (child.lang = :target_child OR parent.lang = :target_parent)
             LIMIT 1'
        );
        $boundary->execute([
            ':target_child' => $targetLang,
            ':target_parent' => $targetLang,
        ]);
        if ($boundary->fetchColumn() !== false) {
            throw new \DomainException(
                'В целевом меню есть связь между разными языками. Исправьте родителя такого пункта перед синхронизацией.'
            );
        }

        $targetByKey = [];
        foreach ($targetRows as $targetRow) {
            $key = self::synchronizationKey($targetRow, $targetLang);
            if ($key !== null) {
                $targetByKey[$key][] = $targetRow;
            }
        }

        $tree = self::buildTree($sourceRows);
        $treeRows = 0;
        foreach ($tree as $node) {
            $treeRows += 1 + count($node['children'] ?? []);
        }
        if ($treeRows !== count($sourceRows)) {
            throw new \DomainException(
                'В исходном меню найдены пункты с родителем другого языка. Исправьте структуру и повторите синхронизацию.'
            );
        }

        $created = 0;
        $skipped = 0;
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM menu_items WHERE lang = :lang');
            $delete->execute([':lang' => $targetLang]);

            $topOrder = 0;
            foreach ($tree as $sourceNode) {
                $node = self::synchronizedRow($sourceNode, $sourceLang, $targetLang, $targetByKey);
                if ($node === null) {
                    $skipped += 1 + count($sourceNode['children'] ?? []);
                    continue;
                }

                $topOrder++;
                $parentId = self::insertSynchronizedRow($pdo, $node, null, $topOrder);
                $created++;

                $childOrder = 0;
                foreach ($sourceNode['children'] ?? [] as $sourceChild) {
                    $child = self::synchronizedRow($sourceChild, $sourceLang, $targetLang, $targetByKey);
                    if ($child === null) {
                        $skipped++;
                        continue;
                    }
                    $childOrder++;
                    self::insertSynchronizedRow($pdo, $child, $parentId, $childOrder);
                    $created++;
                }
            }

            $pdo->commit();
            self::$activeRequestCache = [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'replaced' => count($targetRows),
        ];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $targetByKey
     * @return array<string,mixed>|null
     */
    private static function synchronizedRow(
        array $source,
        string $sourceLang,
        string $targetLang,
        array &$targetByKey
    ): ?array {
        $key = null;
        $fallbackTitle = (string) $source['title'];
        if ((string) $source['url_type'] === 'page') {
            $page = Page::findPublishedMenuTarget((string) $source['url_value'], $targetLang);
            if ($page === null) {
                return null;
            }
            $source['url_value'] = Page::menuTargetValue($page);
            $fallbackTitle = (string) $page['title'];
            $groupId = (int) ($page['translation_group_id'] ?: $page['id']);
            $key = 'page:' . $groupId;
        } else {
            if ((string) $source['url_type'] === 'custom') {
                $source['url_value'] = self::customUrl((string) $source['url_value'], $targetLang);
            }
            $key = self::synchronizationKey($source, $targetLang);
            if ($sourceLang === 'ru') {
                $fallbackTitle = Lang::t($fallbackTitle, $targetLang);
            }
        }

        if ($key !== null && !empty($targetByKey[$key])) {
            /** @var array<string,mixed> $existing */
            $existing = array_shift($targetByKey[$key]);
            $source['title'] = (string) $existing['title'];
        } else {
            $source['title'] = $fallbackTitle;
        }
        $source['lang'] = $targetLang;

        return $source;
    }

    private static function synchronizationKey(array $item, string $lang): ?string
    {
        if (!empty($item['is_divider'])) {
            return null;
        }

        return match ((string) $item['url_type']) {
            'page' => (static function () use ($item, $lang): ?string {
                $page = Page::findPublishedMenuTarget((string) $item['url_value'], $lang);
                if ($page === null) {
                    return null;
                }
                $groupId = (int) ($page['translation_group_id'] ?: $page['id']);

                return 'page:' . $groupId;
            })(),
            'news_index' => 'section:news',
            default => 'custom:' . self::customUrl(
                (string) $item['url_value'],
                Language::defaultCode()
            ),
        };
    }

    private static function insertSynchronizedRow(
        \PDO $pdo,
        array $item,
        ?int $parentId,
        int $sortOrder
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO menu_items
             (lang, title, icon_svg, hide_title, badge_text, badge_color, badge_pos, is_divider,
              url_type, url_value, parent_id, mega_columns, sort_order, is_active, created_at)
             VALUES
             (:lang, :title, :icon_svg, :hide_title, :badge_text, :badge_color, :badge_pos, :is_divider,
              :url_type, :url_value, :parent_id, :mega_columns, :sort_order, :is_active, NOW())'
        );
        $stmt->execute([
            ':lang' => $item['lang'],
            ':title' => $item['title'],
            ':icon_svg' => $item['icon_svg'] ?? null,
            ':hide_title' => !empty($item['hide_title']) ? 1 : 0,
            ':badge_text' => $item['badge_text'] ?? null,
            ':badge_color' => $item['badge_color'] ?? 'red',
            ':badge_pos' => $item['badge_pos'] ?? 'right',
            ':is_divider' => !empty($item['is_divider']) ? 1 : 0,
            ':url_type' => $item['url_type'],
            ':url_value' => $item['url_value'],
            ':parent_id' => $parentId,
            ':mega_columns' => self::megaColumns($item['mega_columns'] ?? 0, $parentId),
            ':sort_order' => $sortOrder,
            ':is_active' => !empty($item['is_active']) ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Все пункты в виде дерева (для админки). */
    public static function allTree(): array
    {
        return self::buildTree(self::all());
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM menu_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::$activeRequestCache = [];
    }

    public static function move(int $id, string $direction): void
    {
        $item = self::findById($id);
        if (!$item) {
            return;
        }
        $siblings = array_values(array_filter(
            self::all(),
            static fn (array $r) => (string) $r['lang'] === (string) $item['lang']
                && (($r['parent_id'] === null && $item['parent_id'] === null)
                    || (int) $r['parent_id'] === (int) $item['parent_id'])
        ));

        $index = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap < 0 || $swap >= count($siblings)) {
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE menu_items SET sort_order = :order WHERE id = :id');
            $stmt->execute([':order' => $siblings[$swap]['sort_order'], ':id' => $siblings[$index]['id']]);
            $stmt->execute([':order' => $siblings[$index]['sort_order'], ':id' => $siblings[$swap]['id']]);
            $pdo->commit();
            self::$activeRequestCache = [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Разрешает конечный URL пункта меню с учётом языкового префикса.
     */
    public static function resolveUrl(array $item, string $lang): string
    {
        $prefix = $lang === Language::defaultCode() ? '' : '/' . $lang;

        return match ($item['url_type']) {
            'news_index' => $prefix . '/news',
            'page' => self::pageUrl((string) $item['url_value'], $lang),
            default => self::customUrl((string) $item['url_value'], $lang),
        };
    }

    /**
     * Внутренние произвольные ссылки должны оставаться в выбранной локали.
     * Внешние URL, служебные схемы, якоря и query-only ссылки не меняем.
     */
    private static function customUrl(string $url, string $lang): string
    {
        $url = trim($url);
        if ($url === ''
            || str_starts_with($url, '#')
            || str_starts_with($url, '?')
            || str_starts_with($url, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1
        ) {
            return $url;
        }

        $suffixAt = strcspn($url, '?#');
        $path = '/' . ltrim(substr($url, 0, $suffixAt), '/');
        $suffix = substr($url, $suffixAt);

        // Старые пункты меню могли хранить языковой префикс вручную.
        // Снимаем известный префикс и добавляем текущий ровно один раз.
        $languageCodes = array_values(array_unique(array_merge([$lang], Language::activeCodes())));
        foreach ($languageCodes as $code) {
            $languagePrefix = '/' . $code;
            if ($path === $languagePrefix || str_starts_with($path, $languagePrefix . '/')) {
                $path = substr($path, strlen($languagePrefix));
                $path = $path === '' ? '/' : $path;
                break;
            }
        }

        return Locale::url($path, $lang) . $suffix;
    }

    public static function cleanBadgePos(?string $pos): string
    {
        $pos = trim((string) $pos);
        return in_array($pos, ['left', 'center', 'right'], true) ? $pos : 'right';
    }

    /**
     * URL только самостоятельной опубликованной страницы нужного языка.
     *
     * Адрес собирается `Page::menuTargetValue()`, а не голым слагом: у проекта
     * публичный адрес — `/projects/<slug>`, и пункт меню, собранный из одного
     * слага, вёл на несуществующий `/<slug>`, то есть на 404. Знание о
     * префиксе живёт в одном месте — там же, где пункт разбирается обратно.
     */
    private static function pageUrl(string $slug, string $lang): string
    {
        $page = Page::findPublishedMenuTarget($slug, $lang);
        if ($page === null) {
            return '#';
        }
        if (!empty($page['is_home'])) {
            return Locale::url('/', $lang);
        }

        return Locale::url('/' . Page::menuTargetValue($page), $lang);
    }
}
