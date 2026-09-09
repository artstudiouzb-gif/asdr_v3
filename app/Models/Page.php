<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\ConcurrencyException;
use App\Core\Logger;

final class Page
{
    /** Кэш slug главной страницы на время запроса (см. homeSlug()). */
    private static ?string $homeSlugCache = null;

    /**
     * Память ответов в пределах запроса: адрес пункта → цель (null тоже
     * запоминается). Шапка спрашивает одно и то же дважды — сперва разрешая
     * пункты дерева, потом собирая ссылку каждого из них, — и на меню из
     * 46 пунктов это давало 36 одинаковых запросов на каждой странице.
     *
     * @var array<string, array<string,mixed>|null>
     */
    private static array $menuTargetMemo = [];

    public static function all(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM pages WHERE deleted_at IS NULL AND entity_type = 'page' ORDER BY created_at DESC"
        );

        return $stmt->fetchAll();
    }

    /**
     * Список с фильтрами админки (задача 91). deleted_at IS NULL всегда.
     * $lang (не-дефолтный) ограничивает страницами, имеющими перевод.
     */
    /**
     * Список страниц для выпадающих списков (меню, выбор родителя).
     *
     * $entityType: 'page' — обычные страницы (по умолчанию), 'project' — только
     * проекты, 'all' — и то и другое (конструктор меню предлагает оба раздела).
     */
    public static function filter(?string $status = null, ?string $lang = null, string $entityType = 'page'): array
    {
        $sql = 'SELECT p.* FROM pages p';
        $params = [];
        if ($lang !== null && $lang !== '' && $lang !== Language::defaultCode()) {
            $sql .= ' INNER JOIN page_translations pt ON pt.page_id = p.id AND pt.lang = :lang';
            $params[':lang'] = $lang;
        }
        $sql .= ' WHERE p.deleted_at IS NULL';
        if ($entityType === 'page' || $entityType === 'project') {
            $sql .= ' AND p.entity_type = :entity_type';
            $params[':entity_type'] = $entityType;
        }
        if ($status === 'published' || $status === 'draft') {
            $sql .= ' AND p.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function adminList(array $filters): array
    {
        [$from, $params] = self::adminListFrom($filters);
        $orders = [
            'newest' => 'p.created_at DESC, p.id DESC',
            'oldest' => 'p.created_at ASC, p.id ASC',
            'title_asc' => 'p.title ASC, p.id ASC',
            'title_desc' => 'p.title DESC, p.id DESC',
        ];
        $order = $orders[$filters['sort'] ?? 'newest'] ?? $orders['newest'];
        $stmt = Database::pdo()->prepare(
            "SELECT p.*,
                    (SELECT parent.title FROM pages parent WHERE parent.id = p.parent_id AND parent.deleted_at IS NULL LIMIT 1) AS parent_title,
                    (SELECT parent.slug FROM pages parent WHERE parent.id = p.parent_id AND parent.deleted_at IS NULL LIMIT 1) AS parent_slug
             {$from}
             ORDER BY {$order}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int) $filters['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $filters['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll();
        $langFilter = (string) ($filters['lang'] ?? '');
        if ($langFilter !== '' && $langFilter !== 'all' && $items !== []) {
            $ids = array_map(static fn ($item): int => (int) $item['id'], $items);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $tStmt = Database::pdo()->prepare(
                "SELECT page_id, title FROM page_translations WHERE page_id IN ({$placeholders}) AND lang = ? AND TRIM(COALESCE(title, '')) <> ''"
            );
            $tStmt->execute([...$ids, $langFilter]);
            $transMap = $tStmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            foreach ($items as &$item) {
                if (!empty($transMap[(int) $item['id']])) {
                    $item['title'] = (string) $transMap[(int) $item['id']];
                }
            }
            unset($item);
        }

        return $items;
    }

    public static function adminCount(array $filters): int
    {
        [$from, $params] = self::adminListFrom($filters);
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) {$from}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function adminListFrom(array $filters): array
    {
        $from = 'FROM pages p';
        $params = [];
        // Проекты живут в этой же таблице, но у них свой раздел админки.
        $where = ['p.deleted_at IS NULL', "p.entity_type = 'page'"];

        $langFilter = (string) ($filters['lang'] ?? '');
        if ($langFilter !== '' && $langFilter !== 'all') {
            $where[] = '(p.lang = :lang'
                . ' OR (p.lang <> :lang_neq'
                . '     AND (EXISTS (SELECT 1 FROM page_translations pt WHERE pt.page_id = p.id AND pt.lang = :lang_pt AND TRIM(COALESCE(pt.title, \'\')) <> \'\')'
                . '          OR EXISTS (SELECT 1 FROM blocks b WHERE b.page_id = p.id AND b.lang = :lang_b))'
                . '     AND NOT EXISTS (SELECT 1 FROM pages p2 WHERE (p2.translation_group_id = COALESCE(NULLIF(p.translation_group_id, 0), p.id) OR p2.id = COALESCE(NULLIF(p.translation_group_id, 0), p.id)) AND p2.lang = :lang_p2 AND p2.deleted_at IS NULL AND p2.id <> p.id)))';
            $params[':lang'] = $langFilter;
            $params[':lang_neq'] = $langFilter;
            $params[':lang_pt'] = $langFilter;
            $params[':lang_b'] = $langFilter;
            $params[':lang_p2'] = $langFilter;
        }

        if (in_array($filters['status'] ?? '', ['published', 'draft'], true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q_title OR p.slug LIKE :q_slug'
                . ' OR EXISTS (SELECT 1 FROM page_translations pqs WHERE pqs.page_id = p.id AND pqs.title LIKE :q_translation))';
            $like = '%' . (string) $filters['q'] . '%';
            $params[':q_title'] = $like;
            $params[':q_slug'] = $like;
            $params[':q_translation'] = $like;
        }

        $from .= ' WHERE ' . implode(' AND ', $where);

        return [$from, $params];
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'published'], true)) {
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE pages SET status = :s WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':s' => $status, ':id' => $id]);
    }

    /** Полная копия страницы с блоками и переводами (черновик, slug -copy). */
    public static function duplicate(int $id): ?int
    {
        $page = self::findById($id);
        if (!$page) {
            return null;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $lang = (string) ($page['lang'] ?? Language::defaultCode());
            $newSlug = \App\Core\Duplicator::uniqueCopySlug(
                (string) $page['slug'],
                static fn (string $s) => self::slugExists($s, null, $lang)
            );
            $newId = \App\Core\Duplicator::copyRow('pages', $page, [
                'slug' => $newSlug,
                'status' => 'draft',
                'is_home' => 0,
                'deleted_at' => null,
                'translation_group_id' => null,
            ]);
            $pdo->prepare(
                'UPDATE pages SET translation_group_id = id
                 WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)'
            )->execute([':id' => $newId]);
            \App\Core\Duplicator::copyChildren('blocks', 'page_id', $id, $newId);
            \App\Core\Duplicator::copyChildren('page_translations', 'page_id', $id, $newId);

            $pdo->commit();

            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function trashed(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM pages WHERE deleted_at IS NOT NULL AND entity_type = 'page' ORDER BY deleted_at DESC"
        );

        return $stmt->fetchAll();
    }

    public static function restore(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE pages SET deleted_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function forceDelete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        ContentRevision::deleteForEntity('page', $id);
    }

    public static function findBySlug(string $slug, ?string $lang = null): ?array
    {
        $lang = $lang ?? Language::defaultCode();

        // 1. Ищем точное совпадение по slug и запрашиваемому языку
        $stmtExact = Database::pdo()->prepare(
            "SELECT * FROM pages WHERE slug = :slug AND lang = :lang AND entity_type = 'page'
               AND status = 'published' AND deleted_at IS NULL LIMIT 1"
        );
        $stmtExact->execute([':slug' => $slug, ':lang' => $lang]);
        $exactRow = $stmtExact->fetch();
        if ($exactRow) {
            return $exactRow;
        }

        // 2. Ищем любой первичный материал по slug
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM pages WHERE slug = :slug AND entity_type = 'page'
               AND status = 'published' AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Если у страницы есть связанный независимый пост на запрашиваемом языке
        $groupId = (int) ($row['translation_group_id'] ?? $row['id']);
        $stmtTrans = Database::pdo()->prepare(
            "SELECT * FROM pages WHERE (translation_group_id = :gid OR id = :gid2) AND lang = :lang
               AND entity_type = 'page' AND status = 'published' AND deleted_at IS NULL LIMIT 1"
        );
        $stmtTrans->execute([':gid' => $groupId, ':gid2' => $groupId, ':lang' => $lang]);
        $transRow = $stmtTrans->fetch();
        if ($transRow) {
            return $transRow;
        }

        return self::localize($row, $lang);
    }

    /**
     * Находит именно опубликованную запись страницы нужного языка для меню.
     *
     * В отличие от findBySlug() этот метод не возвращает локализованный
     * контент основной записи как fallback: меню языка должно вести только
     * на самостоятельную опубликованную версию этого языка.
     */
    /**
     * Цель пункта меню по адресу из формы.
     *
     * Проект адресуется как `projects/<slug>` — тем же значением, каким пункт
     * меню и хранится, поэтому публичная ссылка собирается без особых случаев.
     */
    public static function findPublishedMenuTarget(string $slug, string $lang): ?array
    {
        $memoKey = $lang . '|' . ltrim(trim($slug), '/');
        if (array_key_exists($memoKey, self::$menuTargetMemo)) {
            return self::$menuTargetMemo[$memoKey];
        }

        return self::$menuTargetMemo[$memoKey] = self::resolveMenuTarget($slug, $lang);
    }

    /** Сбрасывает память целей меню: адреса страниц изменились. */
    public static function forgetMenuTargets(): void
    {
        self::$menuTargetMemo = [];
    }

    /** @return array<string,mixed>|null */
    private static function resolveMenuTarget(string $slug, string $lang): ?array
    {
        $slug = ltrim(trim($slug), '/');
        $entityType = 'page';
        if (str_starts_with($slug, 'projects/')) {
            $entityType = 'project';
            $slug = substr($slug, strlen('projects/'));
        }
        if ($slug === '' || !Language::isActive($lang)) {
            return null;
        }

        $pdo = Database::pdo();
        $stmtExact = $pdo->prepare(
            "SELECT * FROM pages
             WHERE slug = :slug AND lang = :lang AND entity_type = :entity_type
               AND status = 'published' AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmtExact->execute([':slug' => $slug, ':lang' => $lang, ':entity_type' => $entityType]);
        $exact = $stmtExact->fetch();
        if ($exact) {
            return $exact;
        }

        $stmtSource = $pdo->prepare(
            "SELECT * FROM pages
             WHERE slug = :slug AND entity_type = :entity_type
               AND status = 'published' AND deleted_at IS NULL
             ORDER BY (lang = :default_lang) DESC, id ASC
             LIMIT 1"
        );
        $stmtSource->execute([
            ':slug' => $slug,
            ':entity_type' => $entityType,
            ':default_lang' => Language::defaultCode(),
        ]);
        $source = $stmtSource->fetch();
        if (!$source) {
            return null;
        }

        $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
        $stmtTarget = $pdo->prepare(
            "SELECT * FROM pages
             WHERE (id = :group_id OR translation_group_id = :translation_group_id)
               AND lang = :lang AND entity_type = :entity_type
               AND status = 'published' AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmtTarget->execute([
            ':group_id' => $groupId,
            ':translation_group_id' => $groupId,
            ':lang' => $lang,
            ':entity_type' => $entityType,
        ]);
        $target = $stmtTarget->fetch();

        return $target ?: null;
    }

    /**
     * Цели пунктов меню сразу для набора адресов: значение пункта → запись.
     *
     * Шапка разрешала каждый пункт отдельным запросом, и меню из 46 пунктов
     * давало 36 обращений к базе на **каждой** странице сайта. Здесь обычный
     * случай — точное совпадание языка — берётся одним запросом на тип
     * записи. Редкий остаток (страница есть только на другом языке) дорешает
     * поштучный findPublishedMenuTarget: своя копия его логики про группы
     * переводов разъехалась бы с ним при первой правке.
     *
     * @param array<array-key, mixed> $urlValues
     * @return array<string, array<string,mixed>> адрес пункта → строка страницы
     */
    public static function publishedMenuTargets(array $urlValues, string $lang): array
    {
        if (!Language::isActive($lang)) {
            return [];
        }

        $wanted = [];   // тип записи → slug → список исходных значений
        foreach ($urlValues as $value) {
            $value = (string) $value;
            $slug = ltrim(trim($value), '/');
            $entityType = 'page';
            if (str_starts_with($slug, 'projects/')) {
                $entityType = 'project';
                $slug = substr($slug, strlen('projects/'));
            }
            if ($slug !== '') {
                $wanted[$entityType][$slug][] = $value;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $found = [];
        $pdo = Database::pdo();
        foreach ($wanted as $entityType => $bySlug) {
            foreach (array_chunk(array_keys($bySlug), 500) as $chunk) {
                $marks = implode(', ', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare(
                    "SELECT * FROM pages
                     WHERE slug IN ({$marks}) AND lang = ? AND entity_type = ?
                       AND status = 'published' AND deleted_at IS NULL"
                );
                $stmt->execute(array_merge($chunk, [$lang, $entityType]));
                foreach ($stmt->fetchAll() as $row) {
                    foreach ($bySlug[(string) $row['slug']] ?? [] as $value) {
                        $found[$value] = $row;
                    }
                }
            }
        }

        foreach ($urlValues as $value) {
            $value = (string) $value;
            if (!array_key_exists($value, $found)) {
                $target = self::findPublishedMenuTarget($value, $lang);
                if ($target !== null) {
                    $found[$value] = $target;
                }
                continue;
            }
            // Найденное кладём в общую память: ту же цель спросит resolveUrl,
            // когда будет собирать ссылку этого пункта.
            self::$menuTargetMemo[$lang . '|' . ltrim(trim($value), '/')] = $found[$value];
        }

        return $found;
    }

    /** Значение пункта меню для найденной цели: у проекта адрес с префиксом. */
    public static function menuTargetValue(array $target): string
    {
        $slug = (string) ($target['slug'] ?? '');

        return (string) ($target['entity_type'] ?? 'page') === 'project'
            ? 'projects/' . $slug
            : $slug;
    }

    /** Ключ раздела из формы: чужое значение — обычная страница. */
    private static function normalizeSection(mixed $section): string
    {
        $section = (string) $section;

        return isset(self::SECTIONS[$section]) ? $section : '';
    }

    /** Разделы, у которых может быть своя страница-шапка. */
    public const SECTIONS = ['news' => 'Новости', 'projects' => 'Проекты'];

    /**
     * Страница-шапка раздела (`/news`, `/projects`): заголовок, лид, SEO и
     * блоки под списком.
     *
     * Язык разрешается так же, как у главной (`findHome`), и это не
     * косметика. Прежний вариант искал только запись с заполненной колонкой
     * `section` и, не найдя её на нужном языке, откатывался на
     * `page_translations`. Но страницы переводятся отдельной записью
     * (механизм Б), а не полями, поэтому на узбекской странице выходил
     * русский заголовок при живой узбекской версии той же страницы.
     * Самостоятельная запись нужного языка обязана иметь приоритет —
     * ровно как у новостей (см. News::publicScope).
     *
     * Следствие для редактора: роль достаточно назначить один раз, на любой
     * языковой версии. Раньше её приходилось проставлять на каждой, и забытый
     * язык молча показывал чужой текст.
     */
    public static function forSection(string $section, ?string $lang = null): ?array
    {
        if (!isset(self::SECTIONS[$section])) {
            return null;
        }

        $targetLang = $lang ?? \App\Core\Locale::current();
        $pdo = Database::pdo();

        // 1. Запись нужного языка, которой роль назначена напрямую.
        $stmt = $pdo->prepare(
            "SELECT * FROM pages
             WHERE section = :section AND entity_type = 'page'
               AND status = 'published' AND deleted_at IS NULL
             ORDER BY (lang = :lang) DESC, (lang = :default_lang) DESC, id ASC
             LIMIT 1"
        );
        $stmt->execute([
            ':section' => $section,
            ':lang' => $targetLang,
            ':default_lang' => Language::defaultCode(),
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ((string) ($row['lang'] ?? '') === $targetLang) {
            return $row;
        }

        // 2. Языковая версия из группы перевода: у страниц это отдельная
        //    опубликованная запись со своими блоками.
        $groupId = (int) ($row['translation_group_id'] ?? $row['id']);
        $stmtGroup = $pdo->prepare(
            "SELECT * FROM pages
             WHERE (translation_group_id = :gid OR id = :gid2) AND lang = :lang
               AND entity_type = 'page' AND status = 'published' AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmtGroup->execute([':gid' => $groupId, ':gid2' => $groupId, ':lang' => $targetLang]);
        $groupRow = $stmtGroup->fetch();
        if ($groupRow) {
            // Роль принадлежит группе, а не конкретной записи: иначе вызывающий
            // код решит, что нашёл обычную страницу.
            $groupRow['section'] = $section;

            return $groupRow;
        }

        // 3. Перевода-записи нет — остаются поля из page_translations.
        //    Пустой раздел хуже чужого языка в заголовке.
        return self::localize($row, $targetLang);
    }

    /**
     * Раздел, шапкой которого служит страница, — с учётом группы перевода.
     * Пусто, если страница не назначена шапкой ни одного раздела.
     *
     * @param array<string, mixed> $page
     */
    public static function sectionOf(array $page, ?string $lang = null): string
    {
        $own = self::normalizeSection($page['section'] ?? '');
        if ($own !== '') {
            return $own;
        }

        $id = (int) ($page['id'] ?? 0);
        if ($id === 0) {
            return '';
        }

        // Роль могла быть назначена соседней языковой версии: шапкой раздела
        // служит вся группа перевода, а не конкретная запись. Достаточно одной
        // выборки по группе — прогонять forSection по каждому разделу значило
        // бы два лишних запроса на КАЖДОМ показе обычной страницы.
        $groupId = (int) ($page['translation_group_id'] ?? $id);
        $stmt = Database::pdo()->prepare(
            "SELECT section FROM pages
             WHERE (translation_group_id = :gid OR id = :gid2)
               AND section <> '' AND entity_type = 'page'
               AND status = 'published' AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute([':gid' => $groupId, ':gid2' => $groupId]);
        $section = $stmt->fetchColumn();

        return $section !== false ? self::normalizeSection($section) : '';
    }

    /** Публичный адрес раздела: /news или /projects с учётом языка. */
    public static function sectionUrl(string $section, ?string $lang = null): string
    {
        return isset(self::SECTIONS[$section])
            ? \App\Core\Locale::url('/' . $section, $lang)
            : '';
    }

    public static function findHome(?string $lang = null): ?array
    {
        $targetLang = $lang ?? \App\Core\Locale::current();
        $pdo = Database::pdo();

        // 1. Ищем опубликованную главную страницу именно для запрошенного языка
        $stmtLang = $pdo->prepare(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM blocks b WHERE b.page_id = p.id AND b.is_active = 1) AS blocks_count
             FROM pages p
             WHERE (p.is_home = 1 OR p.slug = 'home')
               AND p.lang = :lang AND p.entity_type = 'page'
               AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.is_home DESC, (blocks_count > 0) DESC, p.id DESC
             LIMIT 1"
        );
        $stmtLang->execute([':lang' => $targetLang]);
        $row = $stmtLang->fetch();
        if ($row) {
            return $row;
        }

        // 2. Ищем главную страницу основного языка (RU)
        $defaultLang = Language::defaultCode();
        $stmtDefault = $pdo->prepare(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM blocks b WHERE b.page_id = p.id AND b.is_active = 1) AS blocks_count
             FROM pages p
             WHERE (p.is_home = 1 OR p.slug = 'home')
               AND p.entity_type = 'page'
               AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY (p.lang = :default_lang) DESC, p.is_home DESC, (blocks_count > 0) DESC, p.id DESC
             LIMIT 1"
        );
        $stmtDefault->execute([':default_lang' => $defaultLang]);
        $row = $stmtDefault->fetch();

        if (!$row) {
            $stmtFallback = Database::pdo()->query(
                "SELECT * FROM pages WHERE status = 'published' AND entity_type = 'page'
                   AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
            );
            $row = $stmtFallback->fetch();
            if (!$row) {
                return null;
            }
        }

        if ((string) ($row['lang'] ?? $defaultLang) === $targetLang) {
            return $row;
        }

        // 3. Локализация главной страницы для запрошенного языка через группу переводов
        $groupId = (int) ($row['translation_group_id'] ?? $row['id']);
        $stmtTrans = Database::pdo()->prepare(
            "SELECT * FROM pages WHERE (translation_group_id = :gid OR id = :gid2) AND lang = :lang
               AND entity_type = 'page' AND status = 'published' AND deleted_at IS NULL LIMIT 1"
        );
        $stmtTrans->execute([':gid' => $groupId, ':gid2' => $groupId, ':lang' => $targetLang]);
        $transRow = $stmtTrans->fetch();
        if ($transRow) {
            $transRow['is_home'] = 1;
            return $transRow;
        }

        return self::localize($row, $targetLang);
    }

    /**
     * Точно определяет, является ли страница Главной страницей для указанного языка (или для своего языка).
     */
    public static function isHomePage(array|int $page, ?string $lang = null): bool
    {
        if (is_int($page)) {
            $pageRow = self::findById($page);
            if (!$pageRow) {
                return false;
            }
            $page = $pageRow;
        }

        $targetLang = $lang ?? (string) ($page['lang'] ?? Language::defaultCode());
        if ($targetLang !== (string) ($page['lang'] ?? Language::defaultCode())) {
            return false;
        }

        if (!empty($page['is_home']) || (string) ($page['slug'] ?? '') === 'home') {
            return true;
        }

        if (!isset($page['id'])) {
            return false;
        }

        $homePage = self::findHome($targetLang);
        if (!$homePage) {
            return false;
        }

        return (int) $page['id'] === (int) $homePage['id'];
    }

    /**
     * Slug опубликованной главной страницы (кэш на запрос). Нужен, чтобы ссылки
     * на главную вели на «/», а не на «/{slug}». Пусто — главная не задана.
     */
    public static function homeSlug(): string
    {
        if (self::$homeSlugCache === null) {
            $slug = Database::pdo()->query(
                "SELECT slug FROM pages WHERE is_home = 1 AND entity_type = 'page'
                   AND status = 'published' AND deleted_at IS NULL LIMIT 1"
            )->fetchColumn();
            self::$homeSlugCache = $slug !== false ? (string) $slug : '';
        }

        return self::$homeSlugCache;
    }

    /**
     * Накладывает перевод (title/meta) на базовую строку страницы.
     */
    public static function localize(array $row, string $lang): array
    {
        if ($lang === Language::defaultCode()) {
            return $row;
        }

        $translation = PageTranslation::find((int) $row['id'], $lang);
        if ($translation === null) {
            return $row;
        }

        if (isset($translation['title']) && trim((string) $translation['title']) !== '') {
            $row['title'] = $translation['title'];
        }
        $row['meta_title'] = $translation['meta_title'] ?? null;
        $row['meta_description'] = $translation['meta_description'] ?? null;
        if (isset($translation['lead']) && trim((string) $translation['lead']) !== '') {
            $row['lead'] = $translation['lead'];
        }

        return $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Варианты родительской страницы для административной формы.
     *
     * В список попадают только основные записи групп переводов. Текущая
     * страница и все её потомки исключаются, поэтому интерфейс не предлагает
     * создать цикл.
     *
     * @return list<array{id:int,label:string,depth:int}>
     */
    public static function parentOptions(?int $excludeId = null): array
    {
        $rows = Database::pdo()->query(
            "SELECT id, title, slug, is_home, parent_id, translation_group_id
             FROM pages
             WHERE deleted_at IS NULL AND entity_type = 'page'
             ORDER BY title ASC, id ASC"
        )->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $excludedGroup = $excludeId !== null ? self::logicalGroupId($excludeId, $byId) : null;
        $options = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $groupId = self::logicalGroupId($id, $byId);
            if ($groupId !== $id
                || !empty($row['is_home'])
                || (string) ($row['slug'] ?? '') === 'home'
                || ($excludedGroup !== null && $groupId === $excludedGroup)) {
                continue;
            }

            $path = [];
            $visited = [];
            $cursor = $row;
            $invalid = false;
            while ($cursor) {
                $cursorId = (int) $cursor['id'];
                $cursorGroup = self::logicalGroupId($cursorId, $byId);
                if (isset($visited[$cursorGroup])) {
                    $invalid = true;
                    break;
                }
                if ($excludedGroup !== null && $cursorGroup === $excludedGroup) {
                    $invalid = true;
                    break;
                }
                $visited[$cursorGroup] = true;
                array_unshift($path, (string) $cursor['title']);
                $parentId = (int) ($cursor['parent_id'] ?? 0);
                $cursor = $parentId > 0 ? ($byId[$parentId] ?? null) : null;
            }
            if ($invalid) {
                continue;
            }

            $options[] = [
                'id' => $id,
                'label' => implode(' → ', $path),
                'depth' => max(0, count($path) - 1),
            ];
        }

        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Проверяет существование родителя и отсутствие циклов в дереве.
     * Возвращает текст ошибки или null.
     */
    public static function validateParent(?int $parentId, ?int $pageId = null, bool $lockRows = false): ?string
    {
        if ($parentId === null || $parentId <= 0) {
            return null;
        }

        $parent = self::hierarchyRow($parentId, $lockRows);
        if (!$parent || !empty($parent['deleted_at'])) {
            return 'Выбранная родительская страница не существует или находится в корзине.';
        }
        if (!empty($parent['is_home']) || (string) ($parent['slug'] ?? '') === 'home') {
            return 'Главную страницу не нужно выбирать родителем: она уже является началом навигации.';
        }

        $currentGroup = $pageId !== null ? self::logicalGroupId($pageId) : null;
        $visited = [];
        $cursor = $parent;
        for ($depth = 0; $depth < 100; $depth++) {
            $cursorGroup = (int) (($cursor['translation_group_id'] ?? null) ?: $cursor['id']);
            if ($currentGroup !== null && $cursorGroup === $currentGroup) {
                return 'Страница не может быть родителем самой себе или своего родительского раздела.';
            }
            if (isset($visited[$cursorGroup])) {
                return 'В иерархии страниц обнаружен цикл.';
            }
            $visited[$cursorGroup] = true;

            $nextId = (int) ($cursor['parent_id'] ?? 0);
            if ($nextId <= 0) {
                return null;
            }
            $next = self::hierarchyRow($nextId, $lockRows);
            if (!$next) {
                return 'Цепочка родительских страниц повреждена.';
            }
            $cursor = $next;
        }

        return 'Превышена допустимая глубина иерархии страниц.';
    }

    private static function hierarchyRow(int $id, bool $lockRow): ?array
    {
        $sql = 'SELECT * FROM pages WHERE id = :id AND deleted_at IS NULL LIMIT 1';
        if ($lockRow && Database::pdo()->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Родительские страницы от корневой к непосредственному родителю.
     * Заголовки локализуются, а URL остаются плоскими.
     *
     * @return list<array<string,mixed>>
     */
    public static function ancestorTrail(array $page, string $lang): array
    {
        $trail = [];
        $visited = [];
        $parentId = (int) ($page['parent_id'] ?? 0);

        for ($depth = 0; $parentId > 0 && $depth < 100; $depth++) {
            if (isset($visited[$parentId])) {
                break;
            }
            $visited[$parentId] = true;

            $parent = self::findById($parentId);
            if (!$parent || !empty($parent['deleted_at'])) {
                break;
            }

            $display = self::hierarchyNodeForLanguage($parent, $lang);
            array_unshift($trail, $display);
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }

        return $trail;
    }

    /** @param array<int,array<string,mixed>>|null $rowsById */
    private static function logicalGroupId(int $id, ?array $rowsById = null): int
    {
        $row = $rowsById[$id] ?? null;
        if ($row === null) {
            $row = self::findById($id);
        }
        if (!$row) {
            return $id;
        }

        return (int) (($row['translation_group_id'] ?? null) ?: $row['id']);
    }

    private static function hierarchyNodeForLanguage(array $row, string $lang): array
    {
        $groupId = (int) (($row['translation_group_id'] ?? null) ?: $row['id']);
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM pages
             WHERE (id = :group_id OR translation_group_id = :translation_group_id)
               AND lang = :lang AND deleted_at IS NULL
             ORDER BY status = 'published' DESC, id ASC
             LIMIT 1"
        );
        $stmt->execute([
            ':group_id' => $groupId,
            ':translation_group_id' => $groupId,
            ':lang' => $lang,
        ]);
        $localizedRow = $stmt->fetch();

        return $localizedRow ?: self::localize($row, $lang);
    }

    /**
     * Языки контента для набора страниц в виде списка кодов ['ru', 'uz'] (или с картой целевых постов при $withTargets = true).
     *
     * @param array<int|string> $ids
     * @return array<int, list<string>>|array<int, array<string, int>>
     */
    public static function availableLangsForIds(array $ids, bool $withTargets = false): array
    {
        $targets = self::availableLangTargetsForIds($ids);
        if ($withTargets) {
            return $targets;
        }

        $map = [];
        foreach ($targets as $id => $langs) {
            $map[$id] = array_values(array_unique(array_keys($langs)));
        }

        return $map;
    }

    /**
     * Карта языков контента с ID целевых записей для кликабельных баджей ['ru' => 15, 'uz' => 98].
     *
     * @param array<int|string> $ids
     * @return array<int, array<string, int>>
     */
    public static function availableLangTargetsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $default = Language::defaultCode();
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = [];
        }
        if ($ids === []) {
            return $map;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo = Database::pdo();

        try {
            $stmtGroup = $pdo->prepare(
                "SELECT p1.id AS ref_id, p2.lang, p2.id AS target_id, p2.title
                 FROM pages p1
                 JOIN pages p2 ON (p2.translation_group_id = COALESCE(NULLIF(p1.translation_group_id, 0), p1.id)
                                OR p2.id = COALESCE(NULLIF(p1.translation_group_id, 0), p1.id)
                                OR (p1.translation_group_id > 0 AND p2.translation_group_id = p1.translation_group_id))
                 WHERE p1.id IN ($in) AND p2.deleted_at IS NULL"
            );
            $stmtGroup->execute($ids);
            foreach ($stmtGroup->fetchAll() as $row) {
                $refId = (int) $row['ref_id'];
                $lang = (string) ($row['lang'] ?? $default);
                $targetId = (int) $row['target_id'];
                $title = trim((string) ($row['title'] ?? ''));
                if ($title !== '' && isset($map[$refId])) {
                    $map[$refId][$lang] = $targetId;
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('Page::availableLangsForIds: не удалось прочитать группы переводов', $e);
        }

        try {
            $stmtLegacy = $pdo->prepare(
                "SELECT page_id, lang FROM page_translations
                 WHERE page_id IN ($in) AND TRIM(COALESCE(title, '')) <> ''
                 UNION
                 SELECT DISTINCT page_id, lang FROM blocks WHERE page_id IN ($in)"
            );
            $stmtLegacy->execute(array_merge($ids, $ids));
            foreach ($stmtLegacy->fetchAll() as $row) {
                $id = (int) $row['page_id'];
                $lang = (string) $row['lang'];
                if (isset($map[$id]) && !isset($map[$id][$lang])) {
                    $map[$id][$lang] = $id;
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('Page::availableLangsForIds: не удалось прочитать page_translations', $e);
        }

        foreach ($ids as $id) {
            if (empty($map[$id])) {
                $map[$id] = [$default => $id];
            }
        }

        return $map;
    }

    public static function availableLangs(int $id): array
    {
        $langs = [Language::defaultCode()];
        $pdo = Database::pdo();

        $stmtPage = $pdo->prepare(
            'SELECT COALESCE(NULLIF(translation_group_id, 0), id) FROM pages WHERE id = :id LIMIT 1'
        );
        $stmtPage->execute([':id' => $id]);
        $groupId = (int) ($stmtPage->fetchColumn() ?: $id);

        // Независимые языковые версии считаются доступными на публичном сайте
        // только после публикации.
        $stmtGroup = $pdo->prepare(
            "SELECT DISTINCT lang FROM pages
             WHERE (id = :group_id OR translation_group_id = :translation_group_id)
               AND status = 'published' AND deleted_at IS NULL"
        );
        $stmtGroup->execute([
            ':group_id' => $groupId,
            ':translation_group_id' => $groupId,
        ]);
        foreach ($stmtGroup->fetchAll(\PDO::FETCH_COLUMN) as $lang) {
            if (is_string($lang) && $lang !== '') {
                $langs[] = $lang;
            }
        }

        // Совместимость со старой схемой переводов и языковыми стеками блоков.
        $stmt = $pdo->prepare(
            "SELECT lang FROM page_translations WHERE page_id = :id AND TRIM(COALESCE(title, '')) <> ''
             UNION SELECT DISTINCT lang FROM blocks WHERE page_id = :id2"
        );
        $stmt->execute([':id' => $groupId, ':id2' => $groupId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $lang) {
            if (is_string($lang) && $lang !== '') {
                $langs[] = $lang;
            }
        }

        return array_values(array_unique($langs));
    }

    public static function create(array $data): int
    {
        // Адреса страниц изменились — память целей меню в этом запросе устарела.
        self::forgetMenuTargets();
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if (!empty($data['is_home'])) {
            $parentId = null;
        }
        $data['parent_id'] = $parentId;

        return Database::transaction(static function (\PDO $pdo) use ($data, $parentId): int {
            $parentError = self::validateParent($parentId, null, true);
            if ($parentError !== null) {
                throw new \DomainException($parentError);
            }
            $lang = (string) ($data['lang'] ?? Language::defaultCode());

            // Снимаем признак главной только у страниц ТОГО ЖЕ языка.
            // Раньше сброс шёл по всей таблице, и создание главной на узбекском
            // снимало флаг с русской главной: findHome('ru') переставал её
            // находить и откатывался на первую попавшуюся страницу со slug
            // 'home'. У каждого языка своя главная — это и проверяет findHome.
            if (!empty($data['is_home'])) {
                $pdo->prepare('UPDATE pages SET is_home = 0 WHERE lang = :lang')
                    ->execute([':lang' => $lang]);
            }

            // Шапка раздела одна на язык: иначе на сайте выигрывала бы
            // случайная запись, а редактор об этом не узнал бы.
            $section = self::normalizeSection($data['section'] ?? '');
            if ($section !== '') {
                $pdo->prepare("UPDATE pages SET section = '' WHERE section = :section AND lang = :lang")
                    ->execute([':section' => $section, ':lang' => $lang]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO pages (title, slug, section, meta_title, meta_description, `lead`, status, is_home, layout_type, hide_chrome, transparent_header, custom_css, custom_js, lang, translation_group_id, parent_id, created_at)
                 VALUES (:title, :slug, :section, :meta_title, :meta_description, :lead, :status, :is_home, :layout_type, :hide_chrome, :transparent_header, :custom_css, :custom_js, :lang, NULL, :parent_id, NOW())'
            );
            $stmt->execute([
                ':title' => $data['title'],
                ':slug' => $data['slug'],
                ':section' => self::normalizeSection($data['section'] ?? ''),
                ':meta_title' => $data['meta_title'] ?? null,
                ':meta_description' => $data['meta_description'] ?? null,
                ':lead' => $data['lead'] ?? null,
                ':status' => $data['status'] ?? 'draft',
                ':is_home' => !empty($data['is_home']) ? 1 : 0,
                ':layout_type' => $data['layout_type'] ?? 'no_sidebar',
                ':hide_chrome' => !empty($data['hide_chrome']) ? 1 : 0,
                ':transparent_header' => !empty($data['transparent_header']) ? 1 : 0,
                ':custom_css' => $data['custom_css'] ?? null,
                ':custom_js' => $data['custom_js'] ?? null,
                ':lang' => $lang,
                ':parent_id' => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            ]);
            $id = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE pages SET translation_group_id = id WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)')
                ->execute([':id' => $id]);

            return $id;
        });
    }

    public static function update(int $id, array $data, ?int $expectedLockVersion = null): void
    {
        // Адреса страниц изменились — память целей меню в этом запросе устарела.
        self::forgetMenuTargets();
        $current = self::findById($id);
        $parentId = array_key_exists('parent_id', $data)
            ? (!empty($data['parent_id']) ? (int) $data['parent_id'] : null)
            : (!empty($current['parent_id']) ? (int) $current['parent_id'] : null);
        if (!empty($data['is_home'])) {
            $parentId = null;
        }
        $data['parent_id'] = $parentId;

        Database::transaction(static function (\PDO $pdo) use ($id, $data, $parentId, $expectedLockVersion): void {
            $lockCurrent = $pdo->prepare('SELECT id, lang FROM pages WHERE id = :id FOR UPDATE');
            $lockCurrent->execute([':id' => $id]);
            $currentRow = $lockCurrent->fetch();
            if ($currentRow === false) {
                throw new \DomainException('Страница не найдена.');
            }
            $parentError = self::validateParent($parentId, $id, true);
            if ($parentError !== null) {
                throw new \DomainException($parentError);
            }

            // Признак главной снимаем только у страниц того же языка: у каждого
            // языка своя главная. Сброс по всей таблице обнулял главную соседнего
            // языка, и findHome() для него переставал её находить.
            if (!empty($data['is_home'])) {
                $targetLang = (string) ($data['lang'] ?? $currentRow['lang'] ?? Language::defaultCode());
                $pdo->prepare('UPDATE pages SET is_home = 0 WHERE lang = :lang')
                    ->execute([':lang' => $targetLang]);
            }

            // Шапка раздела одна на язык — освобождаем её у соседей.
            $section = self::normalizeSection($data['section'] ?? '');
            if ($section !== '') {
                $sectionLang = (string) ($data['lang'] ?? $currentRow['lang'] ?? Language::defaultCode());
                $pdo->prepare("UPDATE pages SET section = '' WHERE section = :section AND lang = :lang AND id <> :id")
                    ->execute([':section' => $section, ':lang' => $sectionLang, ':id' => $id]);
            }

            $stmt = $pdo->prepare(
                'UPDATE pages SET title = :title, slug = :slug, section = :section, meta_title = :meta_title,
                 meta_description = :meta_description, `lead` = :lead, status = :status, is_home = :is_home,
                 layout_type = :layout_type, hide_chrome = :hide_chrome,
                 transparent_header = :transparent_header, custom_css = :custom_css, custom_js = :custom_js, parent_id = :parent_id' . (isset($data['lang']) ? ', lang = :lang' : '') . ', lock_version = lock_version + 1
                 WHERE id = :id' . ($expectedLockVersion !== null ? ' AND lock_version = :expected_lock_version' : '')
            );
            $params = [
                ':title' => $data['title'],
                ':slug' => $data['slug'],
                ':section' => $section,
                ':meta_title' => $data['meta_title'] ?? null,
                ':meta_description' => $data['meta_description'] ?? null,
                ':lead' => $data['lead'] ?? null,
                ':status' => $data['status'],
                ':is_home' => !empty($data['is_home']) ? 1 : 0,
                ':layout_type' => $data['layout_type'] ?? 'no_sidebar',
                ':hide_chrome' => !empty($data['hide_chrome']) ? 1 : 0,
                ':transparent_header' => !empty($data['transparent_header']) ? 1 : 0,
                ':custom_css' => $data['custom_css'] ?? null,
                ':custom_js' => $data['custom_js'] ?? null,
                ':parent_id' => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
                ':id' => $id,
            ];
            if (isset($data['lang'])) {
                $params[':lang'] = (string) $data['lang'];
            }
            if ($expectedLockVersion !== null) {
                $params[':expected_lock_version'] = $expectedLockVersion;
            }
            $stmt->execute($params);
            if ($expectedLockVersion !== null && $stmt->rowCount() !== 1) {
                throw new ConcurrencyException('Страница была изменена другим пользователем.');
            }
        });
    }

    public static function delete(int $id): void
    {
        // Адреса страниц изменились — память целей меню в этом запросе устарела.
        self::forgetMenuTargets();
        // Мягкое удаление: страница отправляется в корзину (блоки сохраняются).
        $stmt = Database::pdo()->prepare('UPDATE pages SET deleted_at = NOW(), is_home = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function slugExists(
        string $slug,
        ?int $excludeId = null,
        ?string $lang = null,
        string $entityType = 'page'
    ): bool {
        $lang = $lang ?? Language::defaultCode();
        // Адрес уникален внутри своего типа: /about и /projects/about не спорят.
        $sql = 'SELECT COUNT(*) FROM pages WHERE slug = :slug AND lang = :lang
                AND entity_type = :entity_type AND deleted_at IS NULL';
        $params = [':slug' => $slug, ':lang' => $lang, ':entity_type' => $entityType];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function langCounts(): array
    {
        $pdo = Database::pdo();
        $counts = [];
        $sum = 0;
        foreach (Language::active() as $l) {
            $code = (string) $l['code'];
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM pages p
                 WHERE p.deleted_at IS NULL AND p.entity_type = 'page'
                   AND (p.lang = :code
                     OR (p.lang <> :code_neq
                         AND (EXISTS (SELECT 1 FROM page_translations pt WHERE pt.page_id = p.id AND pt.lang = :code_pt AND TRIM(COALESCE(pt.title, '')) <> '')
                              OR EXISTS (SELECT 1 FROM blocks b WHERE b.page_id = p.id AND b.lang = :code_b))
                         AND NOT EXISTS (SELECT 1 FROM pages p2 WHERE (p2.translation_group_id = COALESCE(NULLIF(p.translation_group_id, 0), p.id) OR p2.id = COALESCE(NULLIF(p.translation_group_id, 0), p.id)) AND p2.lang = :code_p2 AND p2.deleted_at IS NULL AND p2.id <> p.id)))"
            );
            $stmt->execute([
                ':code' => $code,
                ':code_neq' => $code,
                ':code_pt' => $code,
                ':code_b' => $code,
                ':code_p2' => $code,
            ]);
            $c = (int) $stmt->fetchColumn();
            $counts[$code] = $c;
            $sum += $c;
        }
        $counts['all'] = $sum;

        return $counts;
    }
}
