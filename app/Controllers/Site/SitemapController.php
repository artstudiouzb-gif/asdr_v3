<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Locale;
use App\Core\Translations;
use App\Models\Language;
use App\Models\Page;
use App\Models\News;
use App\Models\Project;

final class SitemapController
{
    /*
     * Колонки, которые карта сайта и RSS действительно читают. Строка целиком
     * возил тело каждой записи (LONGTEXT content) — до тысячи новостей на
     * запрос, а карта не кэшируется и её обходят все поисковики. Служебные
     * колонки для проверки публикации версий rowsBatch() добавляет сам.
     */
    private const PAGE_COLUMNS = ['id', 'slug', 'lang', 'translation_group_id', 'is_home', 'updated_at'];
    private const NEWS_COLUMNS = ['id', 'slug', 'lang', 'translation_group_id', 'image', 'published_at', 'created_at', 'updated_at'];
    private const PROJECT_COLUMNS = ['id', 'slug', 'lang', 'translation_group_id', 'cover_image', 'updated_at'];
    private const RSS_COLUMNS = ['id', 'slug', 'lang', 'title', 'excerpt', 'image', 'published_at'];

    /** @param list<string> $columns */
    private static function columns(array $columns, string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return implode(', ', array_map(static fn (string $column): string => $prefix . $column, $columns));
    }

    public function xml(): void
    {
        $this->sitemap();
    }

    /**
     * Служебные адреса, которые поисковику обходить незачем. Внутренний поиск
     * — главное: Google прямо просит закрывать его выдачу от обхода, иначе
     * бесконечное число адресов `?q=` съедает лимит обхода сайта, а в индекс
     * попадают «страницы» из случайных слов. Открытые данные (`/opendata`)
     * сюда не входят намеренно: это публичный раздел, его ищут.
     *
     * Косая черта на конце — каталог целиком. Без неё адрес закрывается
     * точно (сам адрес, его параметры и вложенные пути): правило robots.txt —
     * префикс, и «Disallow: /health» закрыло бы заодно страницу /healthcare.
     */
    public const ROBOTS_DISALLOW = [
        '/admin/', '/api/', '/repo/', '/push/', '/script/',
        '/install', '/search', '/captcha.png', '/unsubscribe', '/goals/random',
        '/health', '/_vitals',
    ];

    /**
     * Адреса из списка выше, которые живут и под языковым префиксом
     * (`/uz/search`). Остальные — машинные: префикса у них не бывает, и
     * строка на каждый язык только раздувала бы файл.
     */
    private const ROBOTS_LOCALIZED = ['/search', '/goals/random'];

    /**
     * Метки рекламных кампаний и счётчиков. Для Яндекса — директива
     * Clean-param: адреса, отличающиеся только ими, он склеивает с чистым и
     * не тратит на них обход. Google директиву пропускает, ему то же самое
     * сообщает canonical (SeoHelper::CANONICAL_PARAMS — закрытый список).
     */
    public const ROBOTS_CLEAN_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'fbclid', 'gclid', 'yclid', 'ysclid', '_openstat',
    ];

    /**
     * Файл ключа IndexNow: по протоколу поисковик скачивает его и сверяет с
     * ключом из уведомления. Ключ заводит воркер при первом проходе; пока его
     * нет, файла тоже нет — отправлять уведомления было бы нечем.
     */
    public function indexNowKey(): void
    {
        $key = \App\Core\Seo\IndexNow::key();
        if ($key === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $key;
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo self::robotsTxt(AppUrl::base(), Language::activeCodes(), Language::defaultCode());
    }

    /**
     * @param list<string> $langs активные языки
     */
    public static function robotsTxt(string $baseUrl, array $langs, string $defaultLang): string
    {
        $prefixes = [''];
        foreach ($langs as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '' && $code !== $defaultLang && preg_match('/^[a-z]{2,8}$/', $code) === 1) {
                $prefixes[] = '/' . $code;
            }
        }

        $txt = "User-agent: *\n";
        foreach (self::ROBOTS_DISALLOW as $path) {
            $scoped = in_array($path, self::ROBOTS_LOCALIZED, true) ? $prefixes : [''];
            $forms = str_ends_with($path, '/') || str_contains($path, '.')
                ? [$path]
                : [$path . '$', $path . '?', $path . '/'];
            foreach ($scoped as $prefix) {
                foreach ($forms as $form) {
                    $txt .= 'Disallow: ' . $prefix . $form . "\n";
                }
            }
        }
        $txt .= "Allow: /\n";
        $txt .= "\n";
        $txt .= 'Clean-param: ' . implode('&', self::ROBOTS_CLEAN_PARAMS) . "\n";
        $txt .= "\n";
        $txt .= 'Sitemap: ' . rtrim($baseUrl, '/') . "/sitemap.xml\n";

        return $txt;
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo self::sitemapXml(AppUrl::base());
    }

    /**
     * Карта сайта целиком.
     *
     * Правила, из которых выведено остальное:
     * - **Каждая языковая версия — свой `<url>`** со всем набором hreflang,
     *   включая себя. Прежде у перевода полями (механизм А) своего `<url>` не
     *   было: он мелькал только ссылкой alternate у оригинала, а Google просит
     *   перечислять каждую версию, иначе связку языков он принимает не всегда.
     *   Один и тот же адрес при этом не повторяется — версии группы приходят
     *   и строкой оригинала, и строкой перевода.
     * - **Адресов с редиректом в карте нет.** Страница-шапка раздела отвечает
     *   301 на `/news` или `/projects` (вся группа перевода — Page::sectionOf),
     *   а редирект в карте поисковик считает ошибкой карты. Вместо неё в карту
     *   идёт сам раздел.
     * - **lastmod — последняя правка**, а не дата публикации: по нему поисковик
     *   решает, стоит ли переобходить адрес, и поправленная новость с датой
     *   публикации в lastmod осталась бы в индексе старой.
     * - **Картинки — расширением image:** обложка новости, проекта и альбома.
     *   Так их находит поиск по картинкам и «Новости» с крупным кадром.
     */
    public static function sitemapXml(string $baseUrl): string
    {
        $pdo = Database::pdo();
        $shellGroups = array_map('intval', $pdo->query(
            "SELECT DISTINCT COALESCE(NULLIF(translation_group_id, 0), id) FROM pages
              WHERE section <> '' AND entity_type = 'page' AND status = 'published' AND deleted_at IS NULL"
        )->fetchAll(\PDO::FETCH_COLUMN));
        $shellGroups = array_flip($shellGroups);

        $pages = array_values(array_filter(
            $pdo->query("SELECT " . self::columns(self::PAGE_COLUMNS) . " FROM pages WHERE status = 'published' AND deleted_at IS NULL AND entity_type = 'page' ORDER BY updated_at DESC")->fetchAll(),
            static function (array $row) use ($shellGroups): bool {
                $group = (int) ($row['translation_group_id'] ?? 0);

                return !isset($shellGroups[$group > 0 ? $group : (int) $row['id']]);
            }
        ));
        $news = $pdo->query("SELECT " . self::columns(self::NEWS_COLUMNS) . " FROM news WHERE status = 'published' AND published_at <= NOW() AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000")->fetchAll();
        $projects = $pdo->query("SELECT " . self::columns(self::PROJECT_COLUMNS) . " FROM pages WHERE entity_type = 'project' AND status = 'published' AND deleted_at IS NULL ORDER BY updated_at DESC")->fetchAll();

        // Языковые версии читаются пакетом на каждый тип: поштучный запрос давал
        // по два обращения к базе на запись. Спрашиваем App\Core\Translations —
        // он знает оба механизма перевода, тогда как прежний помощник видел
        // только связанные записи, и карта сайта расходилась с <head> страницы.
        // publishedOnly здесь false: у карты сайта своя, более строгая проверка
        // (у новости учитывается ещё и published_at).
        $groups = [
            'pages' => Translations::rowsBatch('pages', array_column($pages, 'id'), false, self::PAGE_COLUMNS),
            'news' => Translations::rowsBatch('news', array_column($news, 'id'), false, self::NEWS_COLUMNS),
            'projects' => Translations::rowsBatch('projects', array_column($projects, 'id'), false, self::PROJECT_COLUMNS),
        ];

        $entries = [];
        $newest = ['news' => '', 'projects' => ''];

        foreach ($pages as $p) {
            $entries[] = self::recordEntry($baseUrl, 'pages', $p, $groups['pages'][(int) $p['id']] ?? [], 'weekly', !empty($p['is_home']) ? '1.0' : '0.8');
        }
        foreach ($news as $n) {
            $entries[] = self::recordEntry($baseUrl, 'news', $n, $groups['news'][(int) $n['id']] ?? [], 'daily', '0.7');
            $newest['news'] = max($newest['news'], self::lastModified('news', $n));
        }
        foreach ($projects as $pr) {
            $entries[] = self::recordEntry($baseUrl, 'projects', $pr, $groups['projects'][(int) $pr['id']] ?? [], 'monthly', '0.6');
            $newest['projects'] = max($newest['projects'], self::lastModified('projects', $pr));
        }

        $langs = Language::activeCodes();
        $sections = [];
        if ($news !== []) {
            $sections[] = ['path' => 'news', 'lastmod' => $newest['news'], 'changefreq' => 'daily', 'priority' => '0.9'];
        }
        if ($projects !== []) {
            $sections[] = ['path' => 'projects', 'lastmod' => $newest['projects'], 'changefreq' => 'weekly', 'priority' => '0.8'];
        }
        $albumEntries = self::albumEntries($baseUrl);
        if ($albumEntries !== []) {
            $sections[] = ['path' => 'albums', 'lastmod' => '', 'changefreq' => 'weekly', 'priority' => '0.6'];
        }
        [$catalogSections, $catalogEntries] = self::catalogEntries($baseUrl, $langs);
        foreach ($sections as $section) {
            $entries[] = self::sectionEntry($baseUrl, $section, $langs);
        }

        $entries = array_merge($entries, $albumEntries, $catalogSections, $catalogEntries);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        $seen = [];
        foreach ($entries as $entry) {
            $xml .= self::renderEntry($entry, $seen);
        }

        return $xml . '</urlset>';
    }

    /**
     * Запись с языковыми версиями: адрес и дата правки на каждом языке, где
     * версия опубликована.
     *
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $translations язык → строка
     * @return array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string}
     */
    private static function recordEntry(string $baseUrl, string $type, array $row, array $translations, string $changefreq, string $priority): array
    {
        $links = [];
        $lastmod = [];
        $images = [];
        foreach ($translations as $langCode => $version) {
            if (!self::isPublished($type, $version)) {
                continue;
            }
            $langCode = (string) $langCode;
            $links[$langCode] = self::canonicalUrl($baseUrl, $type, $version, $langCode);
            $lastmod[$langCode] = self::lastModified($type, $version);
            $images[$langCode] = self::imagesOf($baseUrl, $type, $version);
        }

        // Одноязычная запись: пакет версий пуст, если запись не нашлась
        // повторно (гонка с удалением) — тогда карта отдаёт её как есть.
        if ($links === []) {
            $own = (string) ($row['lang'] ?? Language::defaultCode());
            $links[$own] = self::canonicalUrl($baseUrl, $type, $row);
            $lastmod[$own] = self::lastModified($type, $row);
            $images[$own] = self::imagesOf($baseUrl, $type, $row);
        }

        return compact('links', 'lastmod', 'images', 'changefreq', 'priority');
    }

    /**
     * Раздел-список (`/news`, `/projects`, `/albums`, каталоги) на каждом
     * активном языке: список существует на всех языках сразу.
     *
     * @param array{path: string, lastmod: string, changefreq: string, priority: string} $section
     * @param list<string> $langs
     * @return array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string}
     */
    private static function sectionEntry(string $baseUrl, array $section, array $langs): array
    {
        $links = [];
        $lastmod = [];
        foreach ($langs as $code) {
            $links[$code] = $baseUrl . Locale::url($section['path'], $code);
            $lastmod[$code] = $section['lastmod'];
        }

        return [
            'links' => $links,
            'lastmod' => $lastmod,
            'images' => [],
            'changefreq' => $section['changefreq'],
            'priority' => $section['priority'],
        ];
    }

    /**
     * Фотоальбомы. Перевод у них полями (механизм А): адрес один, а язык
     * существует там, где заполнен перевод, — иначе посетитель получает
     * страницу «перевода нет» с noindex, и в карте ей не место.
     *
     * @return list<array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string}>
     */
    private static function albumEntries(string $baseUrl): array
    {
        $albums = Database::pdo()->query(
            "SELECT id, slug, cover_url, created_at FROM photo_albums WHERE is_published = 1 ORDER BY created_at DESC, id DESC"
        )->fetchAll();
        if ($albums === []) {
            return [];
        }

        $available = \App\Models\PhotoAlbum::availableLangsForIds(array_column($albums, 'id'));
        $result = [];
        foreach ($albums as $album) {
            $cover = self::absoluteUrl($baseUrl, (string) ($album['cover_url'] ?? ''));
            $links = [];
            $lastmod = [];
            $images = [];
            foreach ($available[(int) $album['id']] ?? [] as $code) {
                $links[$code] = $baseUrl . Locale::url('albums/' . ltrim((string) $album['slug'], '/'), $code);
                $lastmod[$code] = \App\Core\DateFormatter::format((string) ($album['created_at'] ?? ''), 'c');
                $images[$code] = $cover !== '' ? [$cover] : [];
            }
            if ($links !== []) {
                $result[] = ['links' => $links, 'lastmod' => $lastmod, 'images' => $images, 'changefreq' => 'monthly', 'priority' => '0.5'];
            }
        }

        return $result;
    }

    /**
     * Публичные каталоги и их записи. Адрес — ContentType::path/entryPath,
     * то есть тот же, что отдаёт сайт (`/catalog/<type>` или корень).
     * Запись без переводов живёт на основном языке: под другими префиксами
     * она показывала бы тот же текст, и в карте это были бы дубли.
     *
     * @param list<string> $langs
     * @return array{0: list<array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string}>, 1: list<array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string}>}
     */
    private static function catalogEntries(string $baseUrl, array $langs): array
    {
        $pdo = Database::pdo();
        $types = [];
        foreach ($pdo->query("SELECT id, slug, root_url, has_translations FROM content_types WHERE is_public = 1 ORDER BY id")->fetchAll() as $type) {
            $types[(int) $type['id']] = $type;
        }
        if ($types === []) {
            return [[], []];
        }

        $rows = $pdo->query(
            "SELECT id, type_id, slug, updated_at FROM content_entries
              WHERE status = 'published' AND deleted_at IS NULL
                AND type_id IN (" . implode(',', array_keys($types)) . ")
              ORDER BY type_id, sort_order, id"
        )->fetchAll();

        // Переводы — одним запросом на весь каталог, а не на каждую запись
        // (ContentEntry::availableLangs спрашивает по одной). Условие
        // заполненности то же, что у него.
        $translated = [];
        foreach ($pdo->query(
            "SELECT t.entry_id, t.lang FROM content_entry_translations t
               JOIN content_entries e ON e.id = t.entry_id
              WHERE e.status = 'published' AND e.deleted_at IS NULL
                AND (TRIM(COALESCE(t.title, '')) <> ''
                     OR TRIM(COALESCE(t.data, '')) NOT IN ('', '[]', '{}', 'null'))"
        )->fetchAll() as $t) {
            $translated[(int) $t['entry_id']][] = (string) $t['lang'];
        }

        $default = Language::defaultCode();
        $newest = [];
        $entries = [];
        foreach ($rows as $row) {
            $type = $types[(int) $row['type_id']];
            $codes = [$default];
            if ((int) ($type['has_translations'] ?? 0) === 1) {
                $codes = array_values(array_intersect($langs, array_unique([$default, ...($translated[(int) $row['id']] ?? [])])));
            }
            $modified = \App\Core\DateFormatter::format((string) ($row['updated_at'] ?? ''), 'c');
            $newest[(int) $row['type_id']] = max($newest[(int) $row['type_id']] ?? '', $modified);
            $path = \App\Models\ContentType::entryPath($type, ltrim((string) $row['slug'], '/'));
            $links = [];
            $lastmod = [];
            foreach ($codes as $code) {
                $links[$code] = $baseUrl . Locale::url($path, $code);
                $lastmod[$code] = $modified;
            }
            $entries[] = ['links' => $links, 'lastmod' => $lastmod, 'images' => [], 'changefreq' => 'monthly', 'priority' => '0.5'];
        }

        $sections = [];
        foreach ($types as $id => $type) {
            $sections[] = self::sectionEntry($baseUrl, [
                'path' => \App\Models\ContentType::path($type),
                'lastmod' => $newest[$id] ?? '',
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ], (int) ($type['has_translations'] ?? 0) === 1 ? $langs : [$default]);
        }

        return [$sections, $entries];
    }

    /**
     * @param array{links: array<string, string>, lastmod: array<string, string>, images: array<string, list<string>>, changefreq: string, priority: string} $entry
     * @param array<string, true> $seen адреса, уже попавшие в карту
     */
    private static function renderEntry(array $entry, array &$seen): string
    {
        $alternates = '';
        if (count($entry['links']) > 1) {
            foreach ($entry['links'] as $code => $url) {
                $alternates .= '    <xhtml:link rel="alternate" hreflang="' . self::xmlEscape((string) $code)
                    . '" href="' . self::xmlEscape($url) . '"/>' . "\n";
            }
            $defaultLang = Language::defaultCode();
            if (isset($entry['links'][$defaultLang])) {
                $alternates .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                    . self::xmlEscape($entry['links'][$defaultLang]) . '"/>' . "\n";
            }
        }

        $xml = '';
        foreach ($entry['links'] as $code => $url) {
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . self::xmlEscape($url) . "</loc>\n";
            $lastmod = (string) ($entry['lastmod'][$code] ?? '');
            if ($lastmod !== '') {
                $xml .= '    <lastmod>' . $lastmod . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . $entry['changefreq'] . "</changefreq>\n";
            $xml .= '    <priority>' . $entry['priority'] . "</priority>\n";
            $xml .= $alternates;
            foreach ($entry['images'][$code] ?? [] as $image) {
                $xml .= '    <image:image><image:loc>' . self::xmlEscape($image) . "</image:loc></image:image>\n";
            }
            $xml .= "  </url>\n";
        }

        return $xml;
    }

    /**
     * Дата последней правки в ISO 8601. У новости правка раньше публикации —
     * это черновик, отложенный на будущее: для поиска она появилась в момент
     * публикации.
     *
     * @param array<string, mixed> $row
     */
    private static function lastModified(string $type, array $row): string
    {
        $updated = \App\Core\DateFormatter::format((string) ($row['updated_at'] ?? ''), 'c');
        if ($type !== 'news') {
            return $updated;
        }
        $published = \App\Core\DateFormatter::format((string) ($row['published_at'] ?? $row['created_at'] ?? ''), 'c');

        return max($updated, $published);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function imagesOf(string $baseUrl, string $type, array $row): array
    {
        $field = match ($type) {
            'news' => 'image',
            'projects' => 'cover_image',
            default => '',
        };
        $url = $field !== '' ? self::absoluteUrl($baseUrl, (string) ($row[$field] ?? '')) : '';

        return $url !== '' ? [$url] : [];
    }

    /** Абсолютный адрес своего файла; чужие http(s)-адреса — как есть. */
    private static function absoluteUrl(string $baseUrl, string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Адрес записи на указанном языке.
     *
     * Язык передаётся отдельно, а не берётся из строки: у перевода полями
     * (механизм А) строка — это базовая запись с наложенными полями, и её
     * `lang` остаётся языком оригинала. Раньше язык читался из строки, и
     * такой перевод дал бы hreflang="uz" с адресом русской версии.
     *
     * @param array<string, mixed> $row
     */
    private static function canonicalUrl(string $baseUrl, string $type, array $row, ?string $lang = null): string
    {
        $lang = $lang ?? (string) ($row['lang'] ?? Language::defaultCode());
        $slug = ltrim((string) ($row['slug'] ?? ''), '/');
        $path = match ($type) {
            'news' => 'news/' . $slug,
            'projects' => 'projects/' . $slug,
            default => !empty($row['is_home']) ? '' : $slug,
        };

        return $baseUrl . Locale::url($path, $lang);
    }

    /** @param array<string, mixed> $row */
    private static function isPublished(string $type, array $row): bool
    {
        if (($row['status'] ?? '') !== 'published' || !empty($row['deleted_at'])) {
            return false;
        }

        if ($type !== 'news') {
            return true;
        }

        $publishedAt = trim((string) ($row['published_at'] ?? ''));
        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;

        return $timestamp !== false && $timestamp <= time();
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @param array<string, string> $params */
    public function rss(array $params = []): void
    {
        header('Content-Type: application/rss+xml; charset=utf-8');

        $baseUrl = AppUrl::base();
        $siteTitle = \App\Models\Setting::get('site_name', 'ArtStudio');

        $lang = (string) ($params['lang'] ?? '');
        $sql = "SELECT " . self::columns(self::RSS_COLUMNS, 'n') . " FROM news n WHERE n.status = 'published' AND n.published_at <= NOW() AND n.deleted_at IS NULL";
        $sqlParams = [];
        if ($lang !== '') {
            $sql .= " AND n.lang = :lang";
            $sqlParams[':lang'] = $lang;
        }
        $sql .= " ORDER BY n.published_at DESC LIMIT 50";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($sqlParams);
        $items = $stmt->fetchAll();

        $selfUrl = $baseUrl . ($lang !== '' ? '/rss/' . rawurlencode($lang) : '/rss.xml');
        $x = static fn (string $value): string => self::xmlEscape($value);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        // Пространство имён Atom — 2005/Atom: прежний адрес (2004/05/atom) не
        // существует, и валидаторы лент отвергали документ целиком.
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . $x($siteTitle) . '</title>' . "\n";
        $xml .= '    <link>' . $x($baseUrl) . '</link>' . "\n";
        $xml .= '    <description>' . $x($siteTitle . ' RSS Feed') . '</description>' . "\n";
        $xml .= '    <language>' . $x($lang !== '' ? $lang : Language::defaultCode()) . '</language>' . "\n";
        $xml .= '    <atom:link href="' . $x($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        if ($items !== []) {
            $xml .= '    <lastBuildDate>' . \App\Core\DateFormatter::format((string) $items[0]['published_at'], DATE_RSS) . '</lastBuildDate>' . "\n";
        }

        foreach ($items as $n) {
            $link = self::canonicalUrl($baseUrl, 'news', $n);
            $pubDate = \App\Core\DateFormatter::format((string) $n['published_at'], DATE_RSS);
            $title = (string) $n['title'];
            $excerpt = (string) ($n['excerpt'] ?: $n['title']);
            $image = self::absoluteUrl($baseUrl, (string) ($n['image'] ?? ''));

            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . $x($title) . '</title>' . "\n";
            $xml .= '      <link>' . $x($link) . '</link>' . "\n";
            $xml .= '      <guid isPermaLink="true">' . $x($link) . '</guid>' . "\n";
            $xml .= '      <pubDate>' . $pubDate . '</pubDate>' . "\n";
            // Экранирование, а не CDATA: «]]>» в анонсе обрывал секцию, и
            // лента переставала разбираться.
            $xml .= '      <description>' . $x($excerpt) . '</description>' . "\n";
            $enclosure = $image !== '' ? self::enclosure($image, (string) ($n['image'] ?? '')) : '';
            $xml .= $enclosure;
            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>';
        echo $xml;
    }

    /**
     * Вложение RSS обязано назвать тип и длину: прежде тип был всегда
     * image/jpeg (при webp и png), а длины не было вовсе — агрегаторы такое
     * вложение пропускают. Длина известна только у своего файла.
     */
    private static function enclosure(string $absoluteUrl, string $rawUrl): string
    {
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'];
        $ext = strtolower((string) pathinfo((string) parse_url($absoluteUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!isset($types[$ext])) {
            return '';
        }
        $length = 0;
        $local = str_starts_with($rawUrl, '/') && !str_starts_with($rawUrl, '//')
            ? \App\Core\Media::localUploadPath(explode('?', $rawUrl)[0])
            : null;
        if (is_string($local) && is_file($local)) {
            $length = (int) filesize($local);
        }

        return '      <enclosure url="' . self::xmlEscape($absoluteUrl) . '" length="' . $length
            . '" type="' . $types[$ext] . '"/>' . "\n";
    }
}
