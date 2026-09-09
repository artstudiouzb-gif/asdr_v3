<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\ConcurrencyException;
use App\Core\Database;
use App\Core\Translations;
use App\Core\Video;
use App\Core\Logger;
use App\Core\UzbekText;

final class News
{
    public const LAYOUTS = ['standard', 'gallery', 'video', 'side_image', 'premium', 'card'];

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $publishedRequestCache = [];

    public static function normalizeLayout(mixed $layout): string
    {
        $layout = is_string($layout) ? $layout : 'standard';
        return in_array($layout, self::LAYOUTS, true) ? $layout : 'standard';
    }

    /**
     * Централизованный выбор обложки новости (задача 68). Приоритет:
     *   1) явно заданное изображение (news.image),
     *   2) обложка YouTube-видео (news.video_url),
     *   3) первое фото из галереи (news_images),
     *   4) логотип сайта (settings.logo_url).
     * Возвращает URL или null, если ничего нет.
     */
    public static function getCoverImage(array $row): ?string
    {
        $image = trim((string) ($row['image'] ?? ''));
        if ($image !== '') {
            return $image;
        }

        $ytId = Video::youtubeId($row['video_url'] ?? null);
        if ($ytId !== null) {
            return Video::youtubeThumbnail($ytId);
        }

        $prefetchedGallery = trim((string) ($row['first_gallery_image'] ?? ''));
        if ($prefetchedGallery !== '') {
            return $prefetchedGallery;
        }

        if (!empty($row['id'])) {
            $galleryPath = NewsImage::firstPath((int) $row['id']);
            if ($galleryPath !== null) {
                return $galleryPath;
            }
        }

        $logo = trim((string) Setting::get('logo_url', ''));
        return $logo !== '' ? $logo : null;
    }
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM news WHERE deleted_at IS NULL ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    public static function trashed(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM news WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC');

        return $stmt->fetchAll();
    }

    /** Список с фильтрами админки (задача 91). */
    public static function filter(?string $status = null, ?string $lang = null): array
    {
        $sql = 'SELECT n.* FROM news n';
        $params = [];
        if ($lang !== null && $lang !== '' && $lang !== Language::defaultCode()) {
            $sql .= ' INNER JOIN news_translations nt ON nt.news_id = n.id AND nt.lang = :lang';
            $params[':lang'] = $lang;
        }
        $sql .= ' WHERE n.deleted_at IS NULL';
        if ($status === 'published' || $status === 'draft') {
            $sql .= ' AND n.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY n.created_at DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Фильтрованный и постраничный список для административной панели. */
    public static function adminList(array $filters): array
    {
        [$from, $params] = self::adminListFrom($filters);
        $orders = [
            'newest' => 'n.created_at DESC, n.id DESC',
            'oldest' => 'n.created_at ASC, n.id ASC',
            'title_asc' => 'n.title ASC, n.id ASC',
            'title_desc' => 'n.title DESC, n.id DESC',
            'published_desc' => 'n.published_at DESC, n.id DESC',
        ];
        $order = $orders[$filters['sort'] ?? 'newest'] ?? $orders['newest'];
        $sql = "SELECT n.* {$from} ORDER BY {$order} LIMIT :limit OFFSET :offset";
        $stmt = Database::pdo()->prepare($sql);
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
                "SELECT news_id, title FROM news_translations WHERE news_id IN ({$placeholders}) AND lang = ? AND TRIM(COALESCE(title, '')) <> ''"
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
        $from = 'FROM news n';
        $params = [];
        $where = ['n.deleted_at IS NULL'];

        $langFilter = (string) ($filters['lang'] ?? '');
        if ($langFilter !== '' && $langFilter !== 'all') {
            $where[] = '(n.lang = :lang'
                . ' OR (n.lang <> :lang_neq'
                . '     AND EXISTS (SELECT 1 FROM news_translations nt WHERE nt.news_id = n.id AND nt.lang = :lang_nt AND TRIM(COALESCE(nt.title, \'\')) <> \'\')'
                . '     AND NOT EXISTS (SELECT 1 FROM news n2 WHERE (n2.translation_group_id = COALESCE(NULLIF(n.translation_group_id, 0), n.id) OR n2.id = COALESCE(NULLIF(n.translation_group_id, 0), n.id)) AND n2.lang = :lang_n2 AND n2.deleted_at IS NULL AND n2.id <> n.id)))';
            $params[':lang'] = $langFilter;
            $params[':lang_neq'] = $langFilter;
            $params[':lang_nt'] = $langFilter;
            $params[':lang_n2'] = $langFilter;
        }

        if (in_array($filters['status'] ?? '', ['published', 'draft'], true)) {
            $where[] = 'n.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        $categoryFilter = (string) ($filters['category'] ?? '');
        if ($categoryFilter === 'none') {
            $where[] = 'n.category_id IS NULL';
        } elseif ((int) $categoryFilter > 0) {
            $where[] = 'n.category_id = :category';
            $params[':category'] = (int) $categoryFilter;
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(n.title LIKE :q_title OR n.slug LIKE :q_slug'
                . ' OR EXISTS (SELECT 1 FROM news_translations nqs WHERE nqs.news_id = n.id AND nqs.title LIKE :q_translation))';
            $like = '%' . (string) $filters['q'] . '%';
            $params[':q_title'] = $like;
            $params[':q_slug'] = $like;
            $params[':q_translation'] = $like;
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'n.published_at >= :date_from';
            $params[':date_from'] = (string) $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'n.published_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params[':date_to'] = (string) $filters['to'] . ' 00:00:00';
        }

        $from .= ' WHERE ' . implode(' AND ', $where);

        return [$from, $params];
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'published'], true)) {
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE news SET status = :s WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':s' => $status, ':id' => $id]);
        self::bustPageCache();
    }

    /** Полная копия новости с переводами и галереей (черновик, slug -copy). */
    public static function duplicate(int $id): ?int
    {
        $news = self::findById($id);
        if (!$news) {
            return null;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $lang = (string) ($news['lang'] ?? Language::defaultCode());
            $newSlug = \App\Core\Duplicator::uniqueCopySlug(
                (string) $news['slug'],
                static fn (string $s) => self::slugExists($s, null, $lang)
            );
            $newId = \App\Core\Duplicator::copyRow('news', $news, [
                'slug' => $newSlug,
                'status' => 'draft',
                'deleted_at' => null,
                'translation_group_id' => null,
            ]);
            $pdo->prepare(
                'UPDATE news SET translation_group_id = id
                 WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)'
            )->execute([':id' => $newId]);
            \App\Core\Duplicator::copyChildren('news_translations', 'news_id', $id, $newId);
            \App\Core\Duplicator::copyChildren('news_images', 'news_id', $id, $newId);
            \App\Core\Duplicator::copyChildren('news_polls', 'news_id', $id, $newId);

            $pdo->commit();

            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function restore(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE news SET deleted_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::bustPageCache();
    }

    public static function forceDelete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM news WHERE id = :id');
        $stmt->execute([':id' => $id]);
        ContentRevision::deleteForEntity('news', $id);
        self::bustPageCache();
    }

    /**
     * Сбрасывает кэш скомпилированных блоков страниц: динамические блоки
     * (новости на главной и т.п.) кэшируются в составе HTML страницы, поэтому
     * при изменении новостей их нужно пересобрать.
     */
    private static function bustPageCache(): void
    {
        self::$publishedRequestCache = [];
        \App\Core\Cache::forgetPrefix('page:');
    }

    /**
     * Строит общую часть публичной выборки для одного языка.
     *
     * Независимая запись нужного языка всегда имеет приоритет. Перевод из
     * legacy-таблицы используется только пока для этой группы вообще не
     * создана самостоятельная запись языка назначения. Поэтому черновик новой
     * архитектуры нельзя случайно обойти старым опубликованным переводом.
     *
     * @return array{join:string, where:string, params:array<string, string>, translation_alias:?string}
     */
    private static function publicLanguageParts(string $lang, string $alias, string $prefix): array
    {
        $default = Language::defaultCode();
        if ($lang === $default) {
            return [
                'join' => '',
                'where' => "{$alias}.lang = :{$prefix}_exact_lang",
                'params' => ["{$prefix}_exact_lang" => $lang],
                'translation_alias' => null,
            ];
        }

        $translationAlias = $prefix . '_translation';
        $shadowAlias = $prefix . '_shadow';

        return [
            // «Есть ли у группы собственная запись нужного языка» спрашивается
            // один раз производной таблицей, а не коррелированным NOT EXISTS
            // на каждую строку. Прежний вид сравнивал выражение
            // COALESCE(NULLIF(tgid,0), id) с таким же выражением, поэтому под
            // него не подходил никакой индекс: EXPLAIN показывал DEPENDENT
            // SUBQUERY с type=ALL, то есть полный проход по news для каждой
            // строки-кандидата. Замерено на 409 новостях: страница /uz/news
            // отвечала 155 мс против 12 мс у русской, и рост был квадратичным.
            'join' => " LEFT JOIN news_translations {$translationAlias}
                        ON {$translationAlias}.news_id = {$alias}.id
                       AND {$translationAlias}.lang = :{$prefix}_legacy_lang
                        LEFT JOIN (
                            SELECT DISTINCT COALESCE(NULLIF(translation_group_id, 0), id) AS group_key
                            FROM news
                            WHERE deleted_at IS NULL AND lang = :{$prefix}_shadow_lang
                        ) {$shadowAlias}
                        ON {$shadowAlias}.group_key
                           = COALESCE(NULLIF({$alias}.translation_group_id, 0), {$alias}.id)",
            'where' => "(
                {$alias}.lang = :{$prefix}_exact_lang
                OR (
                    {$alias}.lang = :{$prefix}_default_lang
                    AND {$translationAlias}.id IS NOT NULL
                    AND (
                        TRIM(COALESCE({$translationAlias}.title, '')) <> ''
                        OR TRIM(COALESCE({$translationAlias}.content, '')) <> ''
                    )
                    AND {$shadowAlias}.group_key IS NULL
                )
            )",
            'params' => [
                "{$prefix}_legacy_lang" => $lang,
                "{$prefix}_exact_lang" => $lang,
                "{$prefix}_default_lang" => $default,
                "{$prefix}_shadow_lang" => $lang,
            ],
            'translation_alias' => $translationAlias,
        ];
    }

    /**
     * Накладывает legacy-перевод только на резервные базовые строки. Поля
     * самостоятельной языковой записи никогда не заменяются legacy-данными.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function localizePublicRows(array $rows, string $lang): array
    {
        if ($rows === [] || $lang === Language::defaultCode()) {
            return $rows;
        }

        $fallbackIds = [];
        foreach ($rows as $row) {
            if ((string) ($row['lang'] ?? '') !== $lang) {
                $fallbackIds[] = (int) $row['id'];
            }
        }
        $translations = NewsTranslation::forNewsIds($fallbackIds, $lang);

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            if ((string) ($row['lang'] ?? '') !== $lang && isset($translations[$id])) {
                $row = self::applyTranslation($row, $translations[$id]);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Опубликованные новости, локализованные под указанный язык.
     *
     * Фильтр по категории — сравнение идентификатора, а не текста: рубрика
     * одна на все языковые версии новости, переводится только её название.
     * Прежний фильтр по бейджу сравнивал строки и требовал разбора перевода
     * прямо в SQL.
     */
    public static function published(int $limit = 20, int $offset = 0, ?string $lang = null, ?int $categoryId = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $cacheKey = implode('|', [$limit, $offset, $lang, $categoryId ?? 0]);
        if (isset(self::$publishedRequestCache[$cacheKey])) {
            return self::$publishedRequestCache[$cacheKey];
        }

        $parts = self::publicLanguageParts($lang, 'n', 'published');
        $params = $parts['params'];
        $where = "n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
                  AND {$parts['where']}";
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND n.category_id = :category';
            $params['category'] = $categoryId;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT n.*,
                    (SELECT ni.path FROM news_images ni WHERE ni.news_id = n.id
                     ORDER BY ni.sort_order ASC, ni.id ASC LIMIT 1) AS first_gallery_image
             FROM news n{$parts['join']}
             WHERE {$where}
             ORDER BY n.published_at DESC, n.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return self::$publishedRequestCache[$cacheKey] = self::localizePublicRows($stmt->fetchAll(), $lang);
    }

    /** Количество опубликованных новостей одного языка, опционально по категории. */
    public static function publishedCount(?int $categoryId = null, ?string $lang = null): int
    {
        $lang = $lang ?? Language::defaultCode();
        $parts = self::publicLanguageParts($lang, 'n', 'counted');
        $params = $parts['params'];
        $where = "n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
                  AND {$parts['where']}";
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND n.category_id = :category';
            $params[':category'] = $categoryId;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM news n{$parts['join']} WHERE {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Категории, у которых есть опубликованные новости на этом языке —
     * рубрикатор страницы «Новости».
     *
     * Считается по языковой выборке, а не по всей таблице: иначе на узбекской
     * версии предлагалась бы рубрика, в которой на этом языке нет ни одной
     * новости, и фильтр приводил бы к пустой ленте.
     *
     * @return list<array<string, mixed>>
     */
    public static function publishedCategories(?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $parts = self::publicLanguageParts($lang, 'n', 'cats');
        $stmt = Database::pdo()->prepare(
            "SELECT DISTINCT n.category_id
             FROM news n{$parts['join']}
             WHERE n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
               AND n.category_id IS NOT NULL
               AND {$parts['where']}"
        );
        $stmt->execute($parts['params']);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            NewsCategory::all($lang, true),
            static fn (array $category): bool => in_array((int) $category['id'], $ids, true)
        ));
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM news WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Псевдоним для поиска опубликованной новости по слагу.
     */
    public static function findBySlug(string $slug, ?string $lang = null): ?array
    {
        return self::findPublishedBySlug($slug, $lang);
    }

    /**
     * Ищет опубликованную новость по слагу и локализует под язык.
     */
    public static function findPublishedBySlug(string $slug, ?string $lang = null): ?array
    {
        $lang = $lang ?? Language::defaultCode();

        // 1. Самостоятельная опубликованная запись нужного языка.
        $stmtExact = Database::pdo()->prepare(
            "SELECT * FROM news WHERE slug = :slug AND lang = :lang AND status = 'published' AND published_at <= NOW() AND deleted_at IS NULL LIMIT 1"
        );
        $stmtExact->execute([':slug' => $slug, ':lang' => $lang]);
        $exactRow = $stmtExact->fetch();
        if ($exactRow) {
            return $exactRow;
        }

        // 2. Находим группу по slug другой языковой версии.
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM news WHERE slug = :slug AND status = 'published' AND published_at <= NOW() AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $groupId = (int) ($row['translation_group_id'] ?: $row['id']);
        $stmtTrans = Database::pdo()->prepare(
            "SELECT * FROM news
             WHERE COALESCE(NULLIF(translation_group_id, 0), id) = :group_id
               AND lang = :lang
               AND status = 'published' AND published_at <= NOW() AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmtTrans->execute([':group_id' => $groupId, ':lang' => $lang]);
        $transRow = $stmtTrans->fetch();
        if ($transRow) {
            return $transRow;
        }

        // Самостоятельный черновик подавляет старый перевод: иначе его можно
        // было бы обойти публичным legacy-контентом.
        $stmtIndependent = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM news
             WHERE COALESCE(NULLIF(translation_group_id, 0), id) = :group_id
               AND lang = :lang AND deleted_at IS NULL"
        );
        $stmtIndependent->execute([':group_id' => $groupId, ':lang' => $lang]);
        if ((int) $stmtIndependent->fetchColumn() > 0) {
            return $row;
        }

        // Legacy-перевод допустим только у опубликованной базовой записи.
        $default = Language::defaultCode();
        $stmtBase = Database::pdo()->prepare(
            "SELECT * FROM news
             WHERE COALESCE(NULLIF(translation_group_id, 0), id) = :group_id
               AND lang = :default_lang
               AND status = 'published' AND published_at <= NOW() AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmtBase->execute([':group_id' => $groupId, ':default_lang' => $default]);
        $base = $stmtBase->fetch();
        if ($base && $lang !== $default) {
            $legacy = NewsTranslation::find((int) $base['id'], $lang);
            if ($legacy !== null && (
                trim((string) ($legacy['title'] ?? '')) !== ''
                || trim((string) ($legacy['content'] ?? '')) !== ''
            )) {
                return self::applyTranslation($base, $legacy);
            }
        }

        // Контроллер покажет штатное уведомление об отсутствующем переводе.
        return $base ?: $row;
    }

    /**
     * Накладывает перевод указанного языка на базовую строку. Пустые поля
     * перевода откатываются к значению языка по умолчанию (graceful fallback).
     */
    public static function localize(array $row, string $lang): array
    {
        $translation = NewsTranslation::find((int) $row['id'], $lang);
        return self::applyTranslation($row, $translation);
    }

    private static function applyTranslation(array $row, ?array $translation): array
    {
        // Пустая строка здесь проверяется без trim: часть полей несёт HTML и
        // JSON, и менять на них правило заодно с переездом на общий метод
        // значило бы протащить смену поведения под видом уборки.
        $row = Translations::overlayFields($row, $translation, [
            'title', 'badge', 'excerpt', 'lead_html', 'content', 'key_points', 'event_meta',
            'timeline_json', 'docs', 'poll_question', 'poll_options_json',
            ...\App\Core\NewsCard::FIELDS,
        ], false);

        if ($translation === null) {
            return $row;
        }

        $row['meta_title'] = $translation['meta_title'] ?? null;
        $row['meta_description'] = $translation['meta_description'] ?? null;

        return Translations::overlayFields($row, $translation, ['hashtags']);
    }

    public static function cleanHashtags(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $raw = preg_split('/[\s,]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        if ($raw === false || $raw === []) {
            return null;
        }

        $tags = [];
        foreach ($raw as $word) {
            $clean = ltrim(trim($word), '#');
            $clean = UzbekText::normalizeApostrophes($clean);
            if ($clean === '') {
                continue;
            }

            // Единый редакционный формат для сайта и соцсетей: первая буква
            // заглавная, остальная часть — строчная. Дубли удаляем без учёта
            // регистра, чтобы #Реформы и #реформы не выводились дважды.
            $clean = mb_strtolower($clean);
            $clean = mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
            $key = mb_strtolower($clean);
            if (!isset($tags[$key])) {
                $tags[$key] = '#' . $clean;
            }
        }

        return $tags !== [] ? implode(' ', array_values($tags)) : null;
    }

    /**
     * Языки с контентом сразу для списка новостей в виде списка кодов ['ru', 'uz'] (или с картой целевых постов при $withTargets = true).
     *
     * @param list<int> $ids
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
     * Карта языков с контентом и ID целевых записей для кликабельных баджей ['ru' => 15, 'uz' => 98].
     *
     * @param list<int> $ids
     * @return array<int, array<string, int>> id => [langCode => targetId]
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
                "SELECT n1.id AS ref_id, n2.lang, n2.id AS target_id, n2.title
                 FROM news n1
                 JOIN news n2 ON (n2.translation_group_id = COALESCE(NULLIF(n1.translation_group_id, 0), n1.id)
                               OR n2.id = COALESCE(NULLIF(n1.translation_group_id, 0), n1.id)
                               OR (n1.translation_group_id > 0 AND n2.translation_group_id = n1.translation_group_id))
                 WHERE n1.id IN ($in) AND n2.deleted_at IS NULL"
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
            Logger::swallowed('News::availableLangsForIds: не удалось прочитать группы переводов', $e);
        }

        try {
            $stmtLegacy = $pdo->prepare(
                "SELECT news_id, lang FROM news_translations
                 WHERE news_id IN ($in)
                   AND (TRIM(COALESCE(title, '')) <> '' OR TRIM(COALESCE(content, '')) <> '')"
            );
            $stmtLegacy->execute($ids);
            foreach ($stmtLegacy->fetchAll() as $row) {
                $id = (int) $row['news_id'];
                $lang = (string) $row['lang'];
                if (isset($map[$id]) && !isset($map[$id][$lang])) {
                    $map[$id][$lang] = $id;
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('News::availableLangsForIds: не удалось прочитать news_translations', $e);
        }

        foreach ($ids as $id) {
            if (empty($map[$id])) {
                $map[$id] = [$default => $id];
            }
        }

        return $map;
    }

    /**
     * Опубликованные записи группы переводов по языкам. Логика общая для всех
     * сущностей и живёт в App\Core\Translations — здесь только удобное имя
     * для вызовов из моделей и публикатора.
     *
     * @return array<string, array<string,mixed>> lang => строка новости
     */
    public static function groupRowsByLang(int $id): array
    {
        return \App\Core\Translations::rows('news', $id);
    }

    /**
     * Запись группы, от имени которой публикуется пост: строка основного
     * языка. Без этого кнопка «опубликовать» у русской версии ставила в
     * очередь вторую задачу, и в канал уходило два поста вместо одного.
     */
    public static function socialPrimaryId(int $id): int
    {
        return \App\Core\Translations::primaryId('news', $id);
    }

    public static function availableLangs(int $id): array
    {
        $pdo = Database::pdo();

        $stmtNews = $pdo->prepare(
            'SELECT COALESCE(NULLIF(translation_group_id, 0), id) FROM news WHERE id = :id LIMIT 1'
        );
        $stmtNews->execute([':id' => $id]);
        $groupId = (int) ($stmtNews->fetchColumn() ?: $id);

        $stmtGroup = $pdo->prepare(
            "SELECT id, lang, status, published_at
             FROM news
             WHERE COALESCE(NULLIF(translation_group_id, 0), id) = :group_id
               AND deleted_at IS NULL"
        );
        $stmtGroup->execute([':group_id' => $groupId]);
        $allIndependentLangs = [];
        $langs = [];
        $baseId = null;
        $baseIsPublished = false;
        $default = Language::defaultCode();
        foreach ($stmtGroup->fetchAll() as $record) {
            $recordLang = (string) ($record['lang'] ?? '');
            if ($recordLang === '') {
                continue;
            }
            $allIndependentLangs[$recordLang] = true;
            $publishedAt = (string) ($record['published_at'] ?? '');
            $publishedTimestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
            $isPublished = (string) ($record['status'] ?? '') === 'published'
                && $publishedTimestamp !== false
                && $publishedTimestamp <= time();
            if ($isPublished) {
                $langs[] = $recordLang;
            }
            if ($recordLang === $default) {
                $baseId = (int) $record['id'];
                $baseIsPublished = $isPublished;
            }
        }

        // Legacy-перевод объявляется только для опубликованной базы и только
        // если отдельной записи этого языка ещё не существует.
        if ($baseId !== null && $baseIsPublished) {
            $stmt = $pdo->prepare(
                "SELECT lang FROM news_translations
                 WHERE news_id = :id
                   AND (TRIM(COALESCE(title, '')) <> '' OR TRIM(COALESCE(content, '')) <> '')"
            );
            $stmt->execute([':id' => $baseId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $legacyLang) {
                if (is_string($legacyLang) && $legacyLang !== '' && !isset($allIndependentLangs[$legacyLang])) {
                    $langs[] = $legacyLang;
                }
            }
        }

        return array_values(array_unique($langs));
    }

    public static function slugExists(string $slug, ?int $excludeId = null, ?string $lang = null): bool
    {
        $lang = $lang ?? Language::defaultCode();
        $sql = 'SELECT COUNT(*) FROM news WHERE slug = :slug AND lang = :lang AND deleted_at IS NULL';
        $params = [':slug' => $slug, ':lang' => $lang];

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
                "SELECT COUNT(*) FROM news n
                 WHERE n.deleted_at IS NULL
                   AND (n.lang = :code
                     OR (n.lang <> :code_neq
                         AND EXISTS (SELECT 1 FROM news_translations nt WHERE nt.news_id = n.id AND nt.lang = :code_nt AND TRIM(COALESCE(nt.title, '')) <> '')
                         AND NOT EXISTS (SELECT 1 FROM news n2 WHERE (n2.translation_group_id = COALESCE(NULLIF(n.translation_group_id, 0), n.id) OR n2.id = COALESCE(NULLIF(n.translation_group_id, 0), n.id)) AND n2.lang = :code_n2 AND n2.deleted_at IS NULL AND n2.id <> n.id)))"
            );
            $stmt->execute([
                ':code' => $code,
                ':code_neq' => $code,
                ':code_nt' => $code,
                ':code_n2' => $code,
            ]);
            $c = (int) $stmt->fetchColumn();
            $counts[$code] = $c;
            $sum += $c;
        }
        $counts['all'] = $sum;

        return $counts;
    }

    /**
     * Категория новости: 0 и пустая строка означают «без категории».
     * Несуществующий id тоже гасим в NULL — иначе внешний ключ уронил бы
     * сохранение целой новости из-за одного выпадающего списка.
     */
    private static function normalizeCategoryId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        return NewsCategory::find($id) !== null ? $id : null;
    }

    public static function create(array $data): int
    {
        $lang = (string) ($data['lang'] ?? Language::defaultCode());
        $stmt = Database::pdo()->prepare(
            'INSERT INTO news (title, slug, excerpt, lead_html, content, image, video_url, audio_url, audio_title, hashtags, timeline_json, layout_type, sidebar_layout, focal_x, focal_y, category_id, meta_title, meta_description, status, published_at, author_id, lang, translation_group_id, created_at)
             VALUES (:title, :slug, :excerpt, :lead_html, :content, :image, :video_url, :audio_url, :audio_title, :hashtags, :timeline_json, :layout_type, :sidebar_layout, :focal_x, :focal_y, :category_id, :meta_title, :meta_description, :status, :published_at, :author_id, :lang, NULL, NOW())'
        );
        $stmt->execute([
            ':title' => $data['title'],
            ':slug' => $data['slug'],
            ':excerpt' => $data['excerpt'],
            ':lead_html' => $data['lead_html'] ?? null,
            ':content' => $data['content'],
            ':image' => $data['image'] ?? null,
            ':video_url' => $data['video_url'] ?? null,
            ':audio_url' => $data['audio_url'] ?? null,
            ':audio_title' => $data['audio_title'] ?? null,
            ':hashtags' => self::cleanHashtags($data['hashtags'] ?? null),
            ':timeline_json' => $data['timeline_json'] ?? null,
            ':layout_type' => self::normalizeLayout($data['layout_type'] ?? 'standard'),
            ':sidebar_layout' => $data['sidebar_layout'] ?? 'right_sidebar',
            ':focal_x' => $data['focal_x'] ?? null,
            ':focal_y' => $data['focal_y'] ?? null,
            ':category_id' => self::normalizeCategoryId($data['category_id'] ?? null),
            ':meta_title' => $data['meta_title'] ?? null,
            ':meta_description' => $data['meta_description'] ?? null,
            ':status' => $data['status'],
            ':published_at' => $data['published_at'] ?? null,
            ':author_id' => $data['author_id'] ?? null,
            ':lang' => $lang,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        Database::pdo()->prepare('UPDATE news SET translation_group_id = id WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)')->execute([':id' => $id]);
        self::bustPageCache();

        return $id;
    }

    public static function update(int $id, array $data, ?int $expectedLockVersion = null): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE news SET title = :title, slug = :slug, excerpt = :excerpt, lead_html = :lead_html, content = :content,
             image = :image, video_url = :video_url, audio_url = :audio_url, audio_title = :audio_title, hashtags = :hashtags,
             timeline_json = :timeline_json,
             layout_type = :layout_type, sidebar_layout = :sidebar_layout,
             focal_x = :focal_x, focal_y = :focal_y, category_id = :category_id,
             meta_title = :meta_title, meta_description = :meta_description,
             status = :status, published_at = :published_at, lock_version = lock_version + 1
             WHERE id = :id' . ($expectedLockVersion !== null ? ' AND lock_version = :expected_lock_version' : '')
        );
        $params = [
            ':title' => $data['title'],
            ':slug' => $data['slug'],
            ':excerpt' => $data['excerpt'],
            ':lead_html' => $data['lead_html'] ?? null,
            ':content' => $data['content'],
            ':image' => $data['image'] ?? null,
            ':video_url' => $data['video_url'] ?? null,
            ':audio_url' => $data['audio_url'] ?? null,
            ':audio_title' => $data['audio_title'] ?? null,
            ':hashtags' => self::cleanHashtags($data['hashtags'] ?? null),
            ':timeline_json' => $data['timeline_json'] ?? null,
            ':layout_type' => self::normalizeLayout($data['layout_type'] ?? 'standard'),
            ':sidebar_layout' => $data['sidebar_layout'] ?? 'right_sidebar',
            ':focal_x' => $data['focal_x'] ?? null,
            ':focal_y' => $data['focal_y'] ?? null,
            ':category_id' => self::normalizeCategoryId($data['category_id'] ?? null),
            ':meta_title' => $data['meta_title'] ?? null,
            ':meta_description' => $data['meta_description'] ?? null,
            ':status' => $data['status'],
            ':published_at' => $data['published_at'],
            ':id' => $id,
        ];
        if ($expectedLockVersion !== null) {
            $params[':expected_lock_version'] = $expectedLockVersion;
        }
        $stmt->execute($params);
        if ($expectedLockVersion !== null && $stmt->rowCount() !== 1) {
            throw new ConcurrencyException('Новость была изменена другим пользователем.');
        }
        self::bustPageCache();
    }

    public static function delete(int $id): void
    {
        // Мягкое удаление: запись отправляется в корзину.
        $stmt = Database::pdo()->prepare('UPDATE news SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::bustPageCache();
    }

    /** Дополнительные поля детальной страницы (эскиз): бейдж, тезисы, мероприятие, документы. */
    public static function updateExtras(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE news SET badge = :badge, badge_color = :badge_color, press_release_url = :press_release_url,
             key_points = :key_points, event_meta = :event_meta, docs = :docs,
             card_title = :card_title, card_badge = :card_badge, card_stats = :card_stats,
             card_signature = :card_signature, card_note = :card_note,
             source_note = :source_note WHERE id = :id'
        );
        $badgeColor = \App\Core\NewsBadge::normalizeColor($data['badge_color'] ?? '');
        $stmt->execute([
            ':badge' => ($data['badge'] ?? '') !== '' ? $data['badge'] : null,
            ':badge_color' => $badgeColor !== '' ? $badgeColor : null,
            ':press_release_url' => ($data['press_release_url'] ?? '') !== '' ? $data['press_release_url'] : null,
            ':key_points' => ($data['key_points'] ?? '') !== '' ? $data['key_points'] : null,
            ':event_meta' => ($data['event_meta'] ?? '') !== '' ? $data['event_meta'] : null,
            ':docs' => !empty($data['docs']) ? json_encode($data['docs'], JSON_UNESCAPED_UNICODE) : null,
            ':card_title' => ($data['card_title'] ?? '') !== '' ? $data['card_title'] : null,
            ':card_badge' => ($data['card_badge'] ?? '') !== '' ? $data['card_badge'] : null,
            ':card_stats' => ($data['card_stats'] ?? '') !== '' ? $data['card_stats'] : null,
            ':card_signature' => ($data['card_signature'] ?? '') !== '' ? $data['card_signature'] : null,
            ':card_note' => ($data['card_note'] ?? '') !== '' ? $data['card_note'] : null,
            ':source_note' => ($data['source_note'] ?? '') !== '' ? $data['source_note'] : null,
            ':id' => $id,
        ]);
        self::bustPageCache();
    }

    /** Счётчик просмотров детальной страницы (без учёта повторов — простая метрика). */
    public static function incrementViews(int $id): void
    {
        try {
            $pdo = Database::pdo();
            $pdo->prepare('UPDATE news SET views = views + 1 WHERE id = :id')->execute([':id' => $id]);
            $pdo->prepare(
                'INSERT INTO news_views (news_id, view_date, views_count) VALUES (:id, CURRENT_DATE(), 1)
                 ON DUPLICATE KEY UPDATE views_count = views_count + 1'
            )->execute([':id' => $id]);
        } catch (\Throwable $e) {
            // Игнорируем ошибки при учёте просмотров
        }
    }

    /**
     * Соседние опубликованные новости по дате публикации (для «предыдущая/следующая»).
     *
     * @return array{prev: ?array, next: ?array}
     */
    public static function adjacent(array $news, ?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $pub = (string) ($news['published_at'] ?? '');
        $id = (int) $news['id'];
        $pick = static function (string $op, string $order, string $prefix) use ($pub, $id, $lang): ?array {
            $parts = self::publicLanguageParts($lang, 'n', $prefix);
            $params = $parts['params'];
            $params[':adjacent_published'] = $pub;
            $params[':adjacent_published_equal'] = $pub;
            $params[':adjacent_id'] = $id;
            $stmt = Database::pdo()->prepare(
                "SELECT n.* FROM news n{$parts['join']}
                 WHERE n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
                   AND {$parts['where']}
                   AND (n.published_at {$op} :adjacent_published
                        OR (n.published_at = :adjacent_published_equal AND n.id {$op} :adjacent_id))
                 ORDER BY n.published_at {$order}, n.id {$order}
                 LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }

            return self::localizePublicRows([$row], $lang)[0] ?? null;
        };

        return [
            'prev' => $pick('<', 'DESC', 'adjacent_prev'),
            'next' => $pick('>', 'ASC', 'adjacent_next'),
        ];
    }

    /** Похожие новости: последние опубликованные, исключая текущую. */
    public static function related(int $excludeId, int $limit = 4, ?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $limit = max(1, min(12, $limit));
        $parts = self::publicLanguageParts($lang, 'n', 'related');
        $params = $parts['params'];
        $params[':excluded_id'] = $excludeId;
        $stmt = Database::pdo()->prepare(
            "SELECT n.*,
                    (SELECT ni.path FROM news_images ni WHERE ni.news_id = n.id
                     ORDER BY ni.sort_order ASC, ni.id ASC LIMIT 1) AS first_gallery_image
             FROM news n{$parts['join']}
             WHERE n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
               AND {$parts['where']} AND n.id <> :excluded_id
             ORDER BY n.published_at DESC, n.id DESC LIMIT {$limit}"
        );
        $stmt->execute($params);

        return self::localizePublicRows($stmt->fetchAll() ?: [], $lang);
    }

    /** Самые читаемые новости за период (days = 0 — за всё время). */
    public static function mostViewed(int $days = 30, int $limit = 5, ?string $lang = null): array
    {
        $lang = $lang ?? Language::defaultCode();
        $limit = max(1, min(20, $limit));
        try {
            $pdo = Database::pdo();
            $parts = self::publicLanguageParts($lang, 'n', 'viewed');
            if ($days <= 0) {
                $stmt = $pdo->prepare(
                    "SELECT n.id, n.title, n.slug, n.image, n.lang, n.translation_group_id,
                            n.views AS period_views, n.published_at
                     FROM news n{$parts['join']}
                     WHERE n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
                       AND {$parts['where']}
                     ORDER BY n.views DESC, n.id DESC LIMIT {$limit}"
                );
                $stmt->execute($parts['params']);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT n.id, n.title, n.slug, n.image, n.lang, n.translation_group_id,
                            n.published_at, SUM(nv.views_count) AS period_views
                     FROM news_views nv
                     JOIN news n ON nv.news_id = n.id
                     {$parts['join']}
                     WHERE nv.view_date >= DATE_SUB(CURRENT_DATE(), INTERVAL :days DAY)
                       AND n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL
                       AND {$parts['where']}
                     GROUP BY n.id, n.title, n.slug, n.image, n.lang, n.translation_group_id, n.published_at
                     ORDER BY period_views DESC, n.id DESC LIMIT {$limit}"
                );
                foreach ($parts['params'] as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
                $stmt->execute();
            }
            $rows = $stmt->fetchAll() ?: [];
            return self::localizePublicRows($rows, $lang);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
