<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Где в базе лежит контент и как дойти от строки до записи, которую правит
 * редактор. Общий ответ для «где используется файл» (`MediaUsage`) и «где
 * стоит битая ссылка» (`LinkChecker`): два списка таблиц разъехались бы при
 * первой новой таблице, и одна проверка видела бы её, а другая нет.
 *
 * Обход идёт по **всем** текстовым колонкам, а исключения перечислены
 * поимённо (`IGNORED_TABLES`): новая таблица контента попадает в проверку
 * сама, а не ждёт, пока о ней вспомнят.
 */
final class ContentLocator
{
    /**
     * Журналы, история и служебные таблицы: упоминание в них файл не
     * удерживает. История версий — намеренно: иначе однажды использованный
     * файл нельзя было бы удалить никогда. Заявки посетителей — тоже: это их
     * данные, а не контент сайта.
     */
    public const IGNORED_TABLES = [
        'files', 'migrations', 'block_revisions', 'content_revisions',
        'login_attempts', 'user_sessions', 'password_resets', 'backup_codes',
        'audit_log', 'error_log', 'not_found_log', 'search_log', 'seo_audits',
        'news_views', 'web_vitals', 'news_poll_votes', 'form_submissions',
        'subscribers', 'mail_queue', 'social_posts', 'webhooks', 'webhook_deliveries',
        'notifications', 'notification_recipients', 'notification_preferences',
        'notification_deliveries', 'webpush_subscriptions', 'webpush_queue',
        'repo_users', 'repo_categories', 'repo_files', 'link_checks',
    ];

    /**
     * Таблица => [вид владельца, колонка с его id]. Таблица контента, которой
     * здесь нет, всё равно проверяется — упоминание в ней показывается без
     * ссылки, строкой «таблица, запись #id».
     */
    private const TABLES = [
        'pages' => ['page', 'id'],
        'page_translations' => ['page', 'page_id'],
        'blocks' => ['page', 'page_id'],
        'news' => ['news', 'id'],
        'news_translations' => ['news', 'news_id'],
        'news_images' => ['news', 'news_id'],
        'news_polls' => ['news', 'news_id'],
        'heroes' => ['hero', 'id'],
        'hero_slides' => ['hero_slide', 'id'],
        'hero_slide_translations' => ['hero_slide', 'slide_id'],
        'team_members' => ['team', 'id'],
        'team_member_translations' => ['team', 'member_id'],
        'photo_albums' => ['album', 'id'],
        'photo_album_images' => ['album', 'album_id'],
        'photo_album_translations' => ['album', 'album_id'],
        'videos' => ['video', 'id'],
        'video_translations' => ['video', 'video_id'],
        'goals' => ['goal', 'id'],
        'goal_images' => ['goal', 'goal_id'],
        'goal_translations' => ['goal', 'goal_id'],
        'content_entries' => ['entry', 'id'],
        'content_entry_translations' => ['entry', 'entry_id'],
        'news_categories' => ['news_category', 'id'],
        'news_category_translations' => ['news_category', 'category_id'],
        'widgets' => ['widget', 'id'],
        'forms' => ['form', 'id'],
        'menu_items' => ['menu', 'id'],
        'languages' => ['language', 'id'],
        'redirects' => ['redirect', 'id'],
        'block_snippets' => ['snippet', 'id'],
        'settings' => ['setting', 'id'],
    ];

    /**
     * Вид владельца => [подпись, запрос «id, title», адрес правки]. В адресе
     * `{id}` — id владельца, `{a}` — вторая колонка запроса, если она нужна.
     */
    private const OWNERS = [
        'news' => ['Новость', 'SELECT id, title, deleted_at FROM news', '/admin/news/{id}/edit'],
        'hero' => ['Обложка', 'SELECT id, name AS title FROM heroes', '/admin/heroes/{id}/edit'],
        'hero_slide' => ['Слайд обложки', 'SELECT s.id, CONCAT(h.name, \' — \', COALESCE(NULLIF(s.title, \'\'), CONCAT(\'слайд #\', s.id))) AS title, s.hero_id AS a FROM hero_slides s JOIN heroes h ON h.id = s.hero_id', '/admin/heroes/{a}/slides/{id}/edit'],
        'team' => ['Сотрудник', 'SELECT id, name AS title FROM team_members', '/admin/team/{id}/edit'],
        'album' => ['Фотоальбом', 'SELECT id, title FROM photo_albums', '/admin/albums/{id}/edit'],
        'video' => ['Видео', 'SELECT id, title FROM videos', '/admin/videos/{id}/edit'],
        'goal' => ['Цель', 'SELECT id, name AS title FROM goals', '/admin/goals/{id}/edit'],
        'entry' => ['Запись каталога', 'SELECT e.id, e.title, e.deleted_at, t.slug AS a FROM content_entries e JOIN content_types t ON t.id = e.type_id', '/admin/content/{a}/{id}/edit'],
        'news_category' => ['Рубрика новостей', 'SELECT id, name AS title FROM news_categories', '/admin/news-categories/{id}/edit'],
        'widget' => ['Виджет', 'SELECT id, title FROM widgets', '/admin/widgets/{id}/edit'],
        'form' => ['Форма', 'SELECT id, name AS title FROM forms', '/admin/forms/{id}/edit'],
        'menu' => ['Пункт меню', 'SELECT id, title FROM menu_items', '/admin/menu/{id}/edit'],
        'language' => ['Язык', 'SELECT id, name AS title FROM languages', '/admin/languages/{id}/edit'],
        'redirect' => ['Редирект', 'SELECT id, from_path AS title FROM redirects', '/admin/redirects'],
        'snippet' => ['Шаблон страницы', 'SELECT id, name AS title FROM block_snippets', '/admin/snippets'],
    ];

    /** Настройка => раздел, где её правят (по началу ключа). */
    private const SETTING_SECTIONS = [
        'header_' => ['Конструктор шапки', '/admin/header'],
        'mobile_' => ['Конструктор шапки', '/admin/header'],
        'footer_' => ['Конструктор подвала', '/admin/footer'],
        'design_' => ['Дизайн сайта', '/admin/design'],
    ];

    /**
     * Колонки, которые нужны, чтобы дойти от строки до владельца: первичный
     * ключ, колонка владельца и ключ настройки.
     *
     * @return list<string>
     */
    public static function locatorColumns(string $table): array
    {
        $extra = array_filter([self::primaryKey(Database::pdo(), $table), self::TABLES[$table][1] ?? null]);
        if ($table === 'settings') {
            $extra[] = 'key';
        }

        return array_values(array_unique($extra));
    }

    /**
     * Текстовые колонки таблиц контента.
     *
     * @return array<string, list<string>>
     */
    public static function columns(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')
            ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $stmt->execute();
        $columns = [];
        foreach (Database::rows($stmt) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            if (in_array($table, self::IGNORED_TABLES, true) || !self::isIdentifier($table) || !self::isIdentifier($column)) {
                continue;
            }
            $columns[$table][] = $column;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    public static function rowText(array $row, array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $parts[] = $row[$column];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Упоминания → строки для редактора. Одна запись упоминается один раз,
     * даже если файл стоит в ней в трёх блоках: чинить её всё равно в одной
     * форме.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $hits
     * @return list<array{label: string, url: string, trashed: bool, table: string}>
     */
    public static function describe(array $hits): array
    {
        $byKind = [];
        $plain = [];
        foreach ($hits as [$table, $row]) {
            $map = self::TABLES[$table] ?? null;
            if ($map === null) {
                $id = self::firstId($row);
                $plain[$table . ':' . $id] = [
                    'label' => 'Таблица «' . $table . '»' . ($id !== '' ? ', запись #' . $id : ''),
                    'url' => '', 'trashed' => false, 'table' => $table,
                ];
                continue;
            }
            [$kind, $ownerColumn] = $map;
            if ($kind === 'setting') {
                $key = (string) ($row['key'] ?? '');
                [$section, $url] = self::settingSection($key);
                $plain['setting:' . $section] = ['label' => $section, 'url' => $url, 'trashed' => false, 'table' => $table];
                continue;
            }
            $ownerId = (int) ($row[$ownerColumn] ?? 0);
            if ($ownerId > 0) {
                $byKind[$kind][$ownerId] = $table;
            }
        }

        $out = [];
        foreach ($byKind as $kind => $owners) {
            foreach (self::resolve($kind, $owners) as $item) {
                $out[] = $item;
            }
        }

        return array_merge($out, array_values($plain));
    }

    /**
     * @param array<int, string> $owners id владельца => таблица упоминания
     * @return list<array{label: string, url: string, trashed: bool, table: string}>
     */
    private static function resolve(string $kind, array $owners): array
    {
        $ids = array_keys($owners);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($kind === 'page') {
            $stmt = Database::pdo()->prepare('SELECT id, title, entity_type, lang, deleted_at FROM pages WHERE id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $out = [];
            foreach (Database::rows($stmt) as $page) {
                $isProject = BlockOwner::isProject($page);
                $out[] = [
                    'label' => ($isProject ? 'Проект' : 'Страница') . ' «' . (string) $page['title'] . '»' . self::langSuffix($page),
                    'url' => BlockOwner::editUrlFor($page),
                    'trashed' => !empty($page['deleted_at']),
                    'table' => $owners[(int) $page['id']] ?? 'pages',
                ];
            }

            return $out;
        }

        [$label, $sql, $pattern] = self::OWNERS[$kind];
        $alias = str_contains($sql, ' s JOIN ') ? 's.' : (str_contains($sql, ' e JOIN ') ? 'e.' : '');
        $stmt = Database::pdo()->prepare($sql . ' WHERE ' . $alias . 'id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $out = [];
        foreach (Database::rows($stmt) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $out[] = [
                'label' => $label . ($title !== '' ? ' «' . $title . '»' : ' #' . (int) $row['id']),
                'url' => strtr($pattern, ['{id}' => (string) (int) $row['id'], '{a}' => rawurlencode((string) ($row['a'] ?? ''))]),
                'trashed' => !empty($row['deleted_at']),
                'table' => $owners[(int) $row['id']] ?? '',
            ];
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private static function settingSection(string $key): array
    {
        foreach (self::SETTING_SECTIONS as $prefix => $section) {
            if (str_starts_with($key, $prefix)) {
                return $section;
            }
        }

        return ['Настройки сайта', '/admin/settings'];
    }

    /** @param array<string, mixed> $page */
    private static function langSuffix(array $page): string
    {
        $lang = (string) ($page['lang'] ?? '');

        return $lang !== '' ? ' (' . strtoupper($lang) . ')' : '';
    }

    /** @param array<string, mixed> $row */
    private static function firstId(array $row): string
    {
        return isset($row['id']) ? (string) (int) $row['id'] : '';
    }

    public static function primaryKey(PDO $pdo, string $table): ?string
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                ORDER BY ORDINAL_POSITION LIMIT 1");
            $stmt->execute([$table]);
            $column = $stmt->fetchColumn();
            $cache[$table] = is_string($column) && self::isIdentifier($column) ? $column : null;
        }

        return $cache[$table];
    }

    public static function isIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }
}
