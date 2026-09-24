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
    private const PAGE_COLUMNS = ['id', 'slug', 'lang', 'is_home', 'updated_at'];
    private const NEWS_COLUMNS = ['id', 'slug', 'lang', 'published_at', 'created_at'];
    private const PROJECT_COLUMNS = ['id', 'slug', 'lang', 'updated_at'];
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

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $baseUrl = AppUrl::base();
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Disallow: /api/\n";
        echo "Sitemap: " . $baseUrl . "/sitemap.xml\n";
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');

        $baseUrl = AppUrl::base();
        $pages = Database::pdo()->query("SELECT " . self::columns(self::PAGE_COLUMNS) . " FROM pages WHERE status = 'published' AND deleted_at IS NULL AND entity_type = 'page' ORDER BY updated_at DESC")->fetchAll();
        $news = Database::pdo()->query("SELECT " . self::columns(self::NEWS_COLUMNS) . " FROM news WHERE status = 'published' AND published_at <= NOW() AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000")->fetchAll();
        $projects = Database::pdo()->query("SELECT " . self::columns(self::PROJECT_COLUMNS) . " FROM pages WHERE entity_type = 'project' AND status = 'published' AND deleted_at IS NULL ORDER BY updated_at DESC")->fetchAll();

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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        // Pages
        foreach ($pages as $p) {
            $loc = self::canonicalUrl($baseUrl, 'pages', $p);
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . self::xmlEscape($loc) . '</loc>' . "\n";
            if (!empty($p['updated_at'])) {
                $xml .= '    <lastmod>' . \App\Core\DateFormatter::format((string) $p['updated_at'], 'c') . '</lastmod>' . "\n";
            }
            $xml .= '    <changefreq>weekly</changefreq>' . "\n";
            $xml .= '    <priority>' . (!empty($p['is_home']) ? '1.0' : '0.8') . '</priority>' . "\n";

            $xml .= self::alternateLinks($baseUrl, 'pages', $groups['pages'][(int) $p['id']] ?? []);

            $xml .= '  </url>' . "\n";
        }

        // News
        foreach ($news as $n) {
            $loc = self::canonicalUrl($baseUrl, 'news', $n);
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . self::xmlEscape($loc) . '</loc>' . "\n";
            $pubDate = !empty($n['published_at']) ? (string) $n['published_at'] : (string) $n['created_at'];
            $xml .= '    <lastmod>' . \App\Core\DateFormatter::format($pubDate, 'c') . '</lastmod>' . "\n";
            $xml .= '    <changefreq>daily</changefreq>' . "\n";
            $xml .= '    <priority>0.7</priority>' . "\n";

            $xml .= self::alternateLinks($baseUrl, 'news', $groups['news'][(int) $n['id']] ?? []);

            $xml .= '  </url>' . "\n";
        }

        // Projects
        foreach ($projects as $pr) {
            $loc = self::canonicalUrl($baseUrl, 'projects', $pr);
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . self::xmlEscape($loc) . '</loc>' . "\n";
            if (!empty($pr['updated_at'])) {
                $xml .= '    <lastmod>' . \App\Core\DateFormatter::format((string) $pr['updated_at'], 'c') . '</lastmod>' . "\n";
            }
            $xml .= '    <changefreq>monthly</changefreq>' . "\n";
            $xml .= '    <priority>0.6</priority>' . "\n";
            $xml .= self::alternateLinks($baseUrl, 'projects', $groups['projects'][(int) $pr['id']] ?? []);
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';
        echo $xml;
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

    /** @param array<string, array<string,mixed>> $translations язык → строка */
    private static function alternateLinks(string $baseUrl, string $type, array $translations): string
    {
        $links = [];
        foreach ($translations as $langCode => $row) {
            if (!self::isPublished($type, $row)) {
                continue;
            }
            $links[(string) $langCode] = self::canonicalUrl($baseUrl, $type, $row, (string) $langCode);
        }

        if (count($links) < 2) {
            return '';
        }

        $xml = '';
        foreach ($links as $langCode => $url) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . self::xmlEscape($langCode)
                . '" href="' . self::xmlEscape($url) . '"/>' . "\n";
        }
        $defaultLang = Language::defaultCode();
        if (isset($links[$defaultLang])) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                . self::xmlEscape($links[$defaultLang]) . '"/>' . "\n";
        }

        return $xml;
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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2004/05/atom">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . htmlspecialchars($siteTitle, ENT_QUOTES) . '</title>' . "\n";
        $xml .= '    <link>' . htmlspecialchars($baseUrl, ENT_QUOTES) . '</link>' . "\n";
        $xml .= '    <description>' . htmlspecialchars($siteTitle . ' RSS Feed', ENT_QUOTES) . '</description>' . "\n";
        $xml .= '    <language>' . htmlspecialchars($lang !== '' ? $lang : Language::defaultCode(), ENT_QUOTES) . '</language>' . "\n";

        foreach ($items as $n) {
            $link = self::canonicalUrl($baseUrl, 'news', $n);
            $pubDate = \App\Core\DateFormatter::format((string) $n['published_at'], 'r');
            $title = (string) $n['title'];
            $excerpt = (string) ($n['excerpt'] ?: $n['title']);
            $image = (string) ($n['image'] ?? '');

            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>' . "\n";
            $xml .= '      <link>' . htmlspecialchars($link, ENT_QUOTES) . '</link>' . "\n";
            $xml .= '      <guid isPermaLink="true">' . htmlspecialchars($link, ENT_QUOTES) . '</guid>' . "\n";
            $xml .= '      <pubDate>' . $pubDate . '</pubDate>' . "\n";
            $xml .= '      <description><![CDATA[' . $excerpt . ']]></description>' . "\n";
            if ($image !== '') {
                $imgUrl = str_starts_with($image, 'http') ? $image : $baseUrl . '/' . ltrim($image, '/');
                $xml .= '      <enclosure url="' . htmlspecialchars($imgUrl, ENT_QUOTES) . '" type="image/jpeg" />' . "\n";
            }
            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>';
        echo $xml;
    }
}
