<?php

declare(strict_types=1);

namespace App\Core\Seo;

use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Http;

/**
 * Проверка индексации своего сайта: почему страница может не попасть в поиск.
 *
 * Это не отчёт поисковой системы, а причина, по которой отчёт будет плохим.
 * Google и Яндекс показывают следствие («страница не в индексе») с задержкой в
 * сутки-трое; здесь видно причину прямо сейчас и без единого ключа доступа.
 *
 * Проверки делятся на два вида. Одни считаются по базе и стоят несколько
 * запросов; другие требуют HTTP-обхода собственного сайта, и таких ровно
 * столько, сколько нужно: карта, robots и небольшая выборка живых адресов.
 * Полный обход тут не нужен — им занимается scripts/smoke.php.
 */
final class SeoAudit
{
    /**
     * Сколько живых адресов обходим за проход. Полный обход сайта на каждый
     * запуск cron — это часы работы и лишняя нагрузка ради тех же выводов:
     * системные ошибки (noindex, неверный canonical) видны и на выборке.
     */
    public const SAMPLE_SIZE = 12;

    /** Столько же новостей помещается в карту сайта — предел SitemapController. */
    public const SITEMAP_NEWS_LIMIT = 1000;

    /** Адреса примеров показываем не все: список должен читаться, а не пугать. */
    private const MAX_SAMPLES = 5;

    /** @return list<SeoFinding> */
    public static function run(bool $withHttp = true): array
    {
        $findings = array_merge(
            self::sitemapCoverage(),
            self::metaChecks(),
            self::redirectConflicts(),
        );

        if ($withHttp) {
            $findings = array_merge($findings, self::httpChecks());
        }

        return $findings;
    }

    /**
     * Карта сайта: новости за пределами предела в неё не попадают вовсе, и
     * узнать об этом иначе неоткуда — карта отдаётся без ошибки, просто короче.
     *
     * @return list<SeoFinding>
     */
    private static function sitemapCoverage(): array
    {
        $news = (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM news WHERE status = 'published' AND published_at <= NOW() AND deleted_at IS NULL"
        )->fetchColumn();

        if ($news <= self::SITEMAP_NEWS_LIMIT) {
            return [new SeoFinding(
                'sitemap_news_cap',
                SeoFinding::LEVEL_OK,
                'Все новости помещаются в карту сайта',
                'Опубликовано ' . $news . ' из ' . self::SITEMAP_NEWS_LIMIT . ', которые карта отдаёт.'
            )];
        }

        return [new SeoFinding(
            'sitemap_news_cap',
            SeoFinding::LEVEL_ERROR,
            'Часть новостей не попадает в карту сайта',
            'Опубликовано ' . $news . ' новостей, а карта отдаёт только ' . self::SITEMAP_NEWS_LIMIT
                . ' самых свежих. Остальные ' . ($news - self::SITEMAP_NEWS_LIMIT)
                . ' поисковик найдёт только по ссылкам со страниц.',
            $news - self::SITEMAP_NEWS_LIMIT,
            [],
            '/sitemap.xml'
        )];
    }

    /**
     * Описание и заголовок: пустое описание поисковик придумает сам, а два
     * одинаковых заголовка он считает дублем и оставляет в выдаче один адрес.
     *
     * @return list<SeoFinding>
     */
    private static function metaChecks(): array
    {
        $findings = [];
        $pdo = Database::pdo();

        $noDescription = [];
        foreach ([
            ['pages', "entity_type = 'page'", '/'],
            ['pages', "entity_type = 'project'", '/projects/'],
            ['news', '1=1', '/news/'],
        ] as [$table, $extra, $prefix]) {
            $rows = $pdo->query(
                "SELECT slug FROM {$table} WHERE status = 'published' AND deleted_at IS NULL AND {$extra}"
                . " AND (meta_description IS NULL OR meta_description = '') LIMIT 200"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $slug) {
                $noDescription[] = $prefix . $slug;
            }
        }

        $findings[] = $noDescription === []
            ? new SeoFinding('meta_description', SeoFinding::LEVEL_OK, 'У всех опубликованных материалов есть описание')
            : new SeoFinding(
                'meta_description',
                SeoFinding::LEVEL_WARNING,
                'Материалы без описания для поиска',
                'Поисковик соберёт сниппет сам из первого попавшегося текста страницы.',
                count($noDescription),
                array_slice($noDescription, 0, self::MAX_SAMPLES)
            );

        // Дубль заголовка: сравниваем то, что реально уйдёт в <title> —
        // meta_title, а если его нет, то заголовок материала.
        $dupes = $pdo->query(
            "SELECT COALESCE(NULLIF(meta_title, ''), title) AS t, COUNT(*) AS c"
            . " FROM news WHERE status = 'published' AND deleted_at IS NULL"
            . " GROUP BY t HAVING c > 1 ORDER BY c DESC LIMIT 20"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $findings[] = $dupes === []
            ? new SeoFinding('meta_title_duplicate', SeoFinding::LEVEL_OK, 'Заголовки новостей не повторяются')
            : new SeoFinding(
                'meta_title_duplicate',
                SeoFinding::LEVEL_WARNING,
                'Одинаковые заголовки у разных новостей',
                'Поисковик считает такие адреса дублями и оставляет в выдаче один.',
                count($dupes),
                array_slice(array_column($dupes, 't'), 0, self::MAX_SAMPLES)
            );

        return $findings;
    }

    /**
     * Редирект, перекрывающий живую страницу: сама страница открывается только
     * до тех пор, пока правило не сработает, а поисковик увидит переадресацию
     * и выкинет адрес из индекса.
     *
     * @return list<SeoFinding>
     */
    private static function redirectConflicts(): array
    {
        $rows = Database::pdo()->query(
            "SELECT r.from_path FROM redirects r"
            . " JOIN pages p ON p.slug = TRIM(LEADING '/' FROM r.from_path)"
            . " WHERE r.is_active = 1 AND p.status = 'published' AND p.deleted_at IS NULL"
            . " AND p.entity_type = 'page' LIMIT 50"
        )->fetchAll(\PDO::FETCH_COLUMN);

        if ($rows === []) {
            return [new SeoFinding('redirect_conflict', SeoFinding::LEVEL_OK, 'Редиректы не перекрывают живые страницы')];
        }

        return [new SeoFinding(
            'redirect_conflict',
            SeoFinding::LEVEL_ERROR,
            'Редирект перекрывает опубликованную страницу',
            'По этим адресам есть и страница, и правило переадресации. Поисковик увидит переадресацию, а страницу — нет.',
            count($rows),
            array_slice($rows, 0, self::MAX_SAMPLES),
            '/admin/redirects'
        )];
    }

    /**
     * Проверки, которые нельзя посчитать по базе: что сайт реально отдаёт.
     *
     * @return list<SeoFinding>
     */
    private static function httpChecks(): array
    {
        $base = rtrim(AppUrl::base(), '/');

        return array_merge(
            self::robotsCheck($base),
            self::sitemapCheck($base),
            self::sampleCheck($base),
        );
    }

    /** @return list<SeoFinding> */
    private static function robotsCheck(string $base): array
    {
        $res = Http::get($base . '/robots.txt', [], 10);
        $body = (string) ($res['body'] ?? '');

        if ((int) ($res['status'] ?? 0) !== 200) {
            return [new SeoFinding(
                'robots',
                SeoFinding::LEVEL_ERROR,
                'robots.txt не отдаётся',
                'Ответ сервера: ' . (int) ($res['status'] ?? 0) . '. Без него поисковик обходит сайт вслепую.'
            )];
        }

        // «Disallow: /» в блоке для всех закрывает сайт целиком — самая дорогая
        // ошибка из возможных, и заметить её иначе нечем.
        if (preg_match('/User-agent:\s*\*\s*(?:\R(?!User-agent).*)*?Disallow:\s*\/\s*$/mi', $body) === 1) {
            return [new SeoFinding(
                'robots',
                SeoFinding::LEVEL_ERROR,
                'robots.txt закрывает сайт целиком',
                'В блоке для всех роботов стоит «Disallow: /» — сайт не будет индексироваться.'
            )];
        }

        if (stripos($body, 'sitemap:') === false) {
            return [new SeoFinding(
                'robots',
                SeoFinding::LEVEL_WARNING,
                'В robots.txt нет ссылки на карту сайта',
                'Строка «Sitemap: ' . $base . '/sitemap.xml» помогает поисковику найти все адреса сразу.'
            )];
        }

        return [new SeoFinding('robots', SeoFinding::LEVEL_OK, 'robots.txt на месте и не закрывает сайт')];
    }

    /** @return list<SeoFinding> */
    private static function sitemapCheck(string $base): array
    {
        $res = Http::get($base . '/sitemap.xml', [], 20);
        $body = (string) ($res['body'] ?? '');

        if ((int) ($res['status'] ?? 0) !== 200 || $body === '') {
            return [new SeoFinding(
                'sitemap_http',
                SeoFinding::LEVEL_ERROR,
                'Карта сайта не отдаётся',
                'Ответ сервера: ' . (int) ($res['status'] ?? 0) . '.',
                0,
                [],
                '/sitemap.xml'
            )];
        }

        $count = substr_count($body, '<loc>');
        // Разбираем как XML: карта, которую поисковик не прочитает, для него
        // всё равно что отсутствует, а глазами такое не видно.
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($body) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$parsed) {
            return [new SeoFinding(
                'sitemap_http',
                SeoFinding::LEVEL_ERROR,
                'Карта сайта не разбирается как XML',
                'Поисковик такую карту прочитать не сможет.',
                0,
                [],
                '/sitemap.xml'
            )];
        }

        return [new SeoFinding(
            'sitemap_http',
            $count > 0 ? SeoFinding::LEVEL_OK : SeoFinding::LEVEL_ERROR,
            $count > 0 ? 'Карта сайта отдаётся' : 'Карта сайта пуста',
            'Адресов в карте: ' . $count . '.',
            $count,
            [],
            '/sitemap.xml'
        )];
    }

    /**
     * Выборка живых адресов: noindex и чужой canonical — системные ошибки,
     * они либо есть у всех страниц типа, либо нет ни у одной.
     *
     * @return list<SeoFinding>
     */
    private static function sampleCheck(string $base): array
    {
        $paths = ['/'];
        $rows = Database::pdo()->query(
            "SELECT CONCAT('/', slug) FROM pages WHERE status = 'published' AND deleted_at IS NULL"
            . " AND entity_type = 'page' AND is_home = 0 ORDER BY updated_at DESC LIMIT 6"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $news = Database::pdo()->query(
            "SELECT CONCAT('/news/', slug) FROM news WHERE status = 'published' AND deleted_at IS NULL"
            . " ORDER BY published_at DESC LIMIT 5"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $paths = array_slice(array_merge($paths, $rows, $news), 0, self::SAMPLE_SIZE);

        $noindex = [];
        $badCanonical = [];
        $broken = [];
        foreach ($paths as $path) {
            $res = Http::get($base . $path, [], 10);
            $status = (int) ($res['status'] ?? 0);
            if ($status !== 200) {
                $broken[] = $path . ' → ' . $status;
                continue;
            }
            $body = (string) ($res['body'] ?? '');
            if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $body) === 1) {
                $noindex[] = $path;
            }
            if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $body, $m) === 1) {
                $canonical = rtrim($m[1], '/');
                $expected = rtrim($base . $path, '/');
                if ($canonical !== $expected) {
                    $badCanonical[] = $path . ' → ' . $m[1];
                }
            }
        }

        $findings = [];
        $findings[] = $broken === []
            ? new SeoFinding('sample_status', SeoFinding::LEVEL_OK, 'Проверенные адреса отдают 200', 'Проверено адресов: ' . count($paths) . '.')
            : new SeoFinding(
                'sample_status',
                SeoFinding::LEVEL_ERROR,
                'Опубликованный адрес не открывается',
                'Поисковик выкинет такой адрес из индекса.',
                count($broken),
                array_slice($broken, 0, self::MAX_SAMPLES)
            );

        $findings[] = $noindex === []
            ? new SeoFinding('sample_noindex', SeoFinding::LEVEL_OK, 'Запрета индексации на проверенных адресах нет')
            : new SeoFinding(
                'sample_noindex',
                SeoFinding::LEVEL_ERROR,
                'Опубликованная страница закрыта от индексации',
                'В разметке стоит «noindex» — страница в поиск не попадёт.',
                count($noindex),
                array_slice($noindex, 0, self::MAX_SAMPLES)
            );

        $findings[] = $badCanonical === []
            ? new SeoFinding('sample_canonical', SeoFinding::LEVEL_OK, 'Canonical на проверенных адресах указывает на себя')
            : new SeoFinding(
                'sample_canonical',
                SeoFinding::LEVEL_WARNING,
                'Canonical указывает на другой адрес',
                'Поисковик покажет в выдаче тот адрес, на который указывает canonical, а не этот.',
                count($badCanonical),
                array_slice($badCanonical, 0, self::MAX_SAMPLES)
            );

        return $findings;
    }

    /**
     * Сводка по находкам: сколько ошибок и предупреждений.
     *
     * @param list<SeoFinding> $findings
     * @return array{errors: int, warnings: int, ok: int}
     */
    public static function summary(array $findings): array
    {
        $summary = ['errors' => 0, 'warnings' => 0, 'ok' => 0];
        foreach ($findings as $finding) {
            $summary[match ($finding->level) {
                SeoFinding::LEVEL_ERROR => 'errors',
                SeoFinding::LEVEL_WARNING => 'warnings',
                default => 'ok',
            }]++;
        }

        return $summary;
    }
}
