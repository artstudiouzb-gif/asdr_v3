<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/**
 * Индекс поиска по сайту: по строке на материал и язык, текст очищен от
 * разметки и собран из всех мест, где он лежит.
 *
 * Прежде поиск шёл прямо по таблицам контента и видел только заголовок,
 * slug и лид страницы — текст её блоков, документы и альбомы не находились
 * вовсе, а тело новости сравнивалось через LIKE по LONGTEXT с HTML внутри.
 * Пять запросов с JOIN на каждый поиск, и все медленнее с каждым годом
 * архива.
 *
 * **Почему LIKE по подготовленному тексту, а не FULLTEXT.** Полнотекстовый
 * индекс MySQL режет слово по апострофу (`o‘lchanadigan` → `o` и
 * `lchanadigan`), не знает слов короче трёх букв и ищет только с начала
 * слова; варианты написания (две латиницы и кириллица, `SearchQuery`) он не
 * сопоставит. Выигрыш индекса — охват, а не скорость: LIKE читает таблицу
 * целиком что так, что этак (замерено на 1500 новостях по 3 КБ: редкое слово
 * 107 мс против 127 у прежнего пути, частое — 53 мс на 300 результатов).
 * Порядок по дате идёт по индексу (lang, sort_date), и частое слово
 * останавливает чтение на пределе выдачи.
 *
 * **Индекс производный и пересобирается целиком**: после правки контента
 * (`Cache::forgetPrefix('page:')` — через эту точку проходит любое
 * сохранение, пересборка идёт после ответа), ночью и командой
 * `php app/Console/search_index.php`. Пустой или недоступный индекс поиск
 * не ломает: `Search` откатывается на прямой запрос к таблицам.
 */
final class SearchIndex
{
    private const BODY_LIMIT = 60000;

    private static bool $scheduled = false;

    /** Пересобрать после ответа (веб) — правка не ждёт индекса. */
    public static function afterResponse(): void
    {
        if (self::$scheduled || PHP_SAPI === 'cli') {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            try {
                self::rebuild();
            } catch (\Throwable $e) {
                Logger::swallowed('search index rebuild', $e);
            }
        });
    }

    /** Есть ли что искать по индексу. */
    public static function ready(): bool
    {
        try {
            return (int) Database::pdo()->query('SELECT COUNT(*) FROM search_index')->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return int число строк индекса */
    public static function rebuild(): int
    {
        $rows = self::collect();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM search_index');
            $insert = $pdo->prepare(
                'INSERT INTO search_index (entity, entity_id, lang, type_label, title, slug, url, excerpt, body, sort_date, visible_from)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($rows as $r) {
                $insert->execute([
                    $r['entity'], $r['entity_id'], $r['lang'], mb_substr($r['type'], 0, 120),
                    mb_substr($r['title'], 0, 500), mb_substr($r['slug'], 0, 255), mb_substr($r['url'], 0, 1000),
                    mb_substr($r['excerpt'], 0, 500), mb_substr($r['body'], 0, self::BODY_LIMIT),
                    $r['sort_date'], $r['visible_from'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return count($rows);
    }

    /**
     * Все строки индекса. Публичен ради теста: что попадает в поиск, видно
     * без записи в базу.
     *
     * @return list<array{entity:string,entity_id:int,lang:string,type:string,title:string,slug:string,url:string,excerpt:string,body:string,sort_date:?string,visible_from:?string}>
     */
    public static function collect(): array
    {
        $langs = Language::publicCodes();
        if ($langs === []) {
            $langs = [Language::defaultCode()];
        }
        $out = [];
        foreach (['pages', 'news', 'team', 'entries', 'albums'] as $part) {
            try {
                array_push($out, ...match ($part) {
                    'pages' => self::pages($langs),
                    'news' => self::news($langs),
                    'team' => self::team($langs),
                    'entries' => self::entries($langs),
                    'albums' => self::albums($langs),
                });
            } catch (\Throwable $e) {
                Logger::swallowed('search index: ' . $part, $e);
            }
        }

        return $out;
    }

    /** @param list<string> $langs @return list<array<string, mixed>> */
    private static function pages(array $langs): array
    {
        $pdo = Database::pdo();
        $pages = Database::rows($pdo->query(
            "SELECT id, entity_type, slug, lang, title, `lead`, meta_description, updated_at, created_at
             FROM pages
             WHERE deleted_at IS NULL AND status = 'published' AND is_home = 0 AND section = ''"
        ));
        $translations = [];
        foreach (Database::rows($pdo->query('SELECT page_id, lang, title, `lead`, meta_description FROM page_translations')) as $t) {
            $translations[(int) $t['page_id']][(string) $t['lang']] = $t;
        }
        $blocks = [];
        foreach (Database::rows($pdo->query('SELECT page_id, lang, type, data FROM blocks WHERE is_active = 1 ORDER BY sort_order')) as $b) {
            $blocks[(int) $b['page_id']][(string) $b['lang']][] = $b;
        }

        $out = [];
        foreach ($pages as $p) {
            $id = (int) $p['id'];
            $base = (string) $p['lang'];
            $isProject = $p['entity_type'] === 'project';
            $versionLangs = [$base => $p];
            // Перевод полями (механизм А) — только у страниц; проект
            // переводится отдельной записью своего языка.
            if (!$isProject) {
                foreach ($translations[$id] ?? [] as $lang => $t) {
                    if ($lang !== $base && trim((string) $t['title']) !== '') {
                        $versionLangs[$lang] = $t;
                    }
                }
            }
            foreach ($versionLangs as $lang => $v) {
                if (!in_array($lang, $langs, true)) {
                    continue;
                }
                $lead = trim((string) ($v['lead'] ?? '')) !== '' ? (string) $v['lead'] : (string) $p['lead'];
                $stack = $blocks[$id][$lang] ?? ($lang === $base ? ($blocks[$id][''] ?? []) : []);
                $texts = [];
                foreach ($stack as $b) {
                    $data = json_decode((string) $b['data'], true);
                    array_push($texts, ...PageTranslationFile::readerTexts((string) $b['type'], is_array($data) ? $data : []));
                    if ($b['type'] === 'docs_list' && is_array($data)) {
                        array_push($out, ...self::documents($data, $id, $lang));
                    }
                }
                $out[] = self::row(
                    $isProject ? 'project' : 'page',
                    $id,
                    $lang,
                    Lang::t($isProject ? 'Проект' : 'Страница', $lang),
                    trim((string) $v['title']) !== '' ? (string) $v['title'] : (string) $p['title'],
                    (string) $p['slug'],
                    Locale::url(($isProject ? 'projects/' : '') . $p['slug'], $lang),
                    $lead,
                    [$lead, (string) ($v['meta_description'] ?? ''), ...$texts],
                    (string) ($p['updated_at'] ?? $p['created_at'])
                );
            }
        }

        return $out;
    }

    /**
     * Документы блока «Документы» — отдельными результатами: ищут обычно
     * сам документ, а не страницу, на которой он стоит.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function documents(array $data, int $pageId, string $lang): array
    {
        $out = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $files = is_array($item['files'] ?? null) ? $item['files'] : [];
            $url = (string) ($files[$lang] ?? ($item['url'] ?? ''));
            if ($url === '') {
                $url = (string) (array_values(array_filter($files, 'is_string'))[0] ?? '');
            }
            if ($title === '' || $url === '' || !UrlGuard::isSafeLink($url)) {
                continue;
            }
            $number = trim((string) ($item['number'] ?? ''));
            $description = (string) ($item['description'] ?? '');
            $out[] = self::row(
                'document',
                $pageId,
                $lang,
                Lang::t('Документ', $lang),
                $title,
                '',
                $url,
                $number !== '' ? '№ ' . $number : $description,
                [$number, $description, (string) ($item['meta'] ?? '')],
                (string) ($item['date'] ?? '') !== '' ? (string) $item['date'] : null
            );
        }

        return $out;
    }

    /** @param list<string> $langs @return list<array<string, mixed>> */
    private static function news(array $langs): array
    {
        $pdo = Database::pdo();
        $news = Database::rows($pdo->query(
            "SELECT id, slug, lang, title, excerpt, content, published_at
             FROM news WHERE deleted_at IS NULL AND status = 'published'"
        ));
        $translations = [];
        foreach (Database::rows($pdo->query('SELECT news_id, lang, title, excerpt, content FROM news_translations')) as $t) {
            $translations[(int) $t['news_id']][(string) $t['lang']] = $t;
        }
        $out = [];
        foreach ($news as $n) {
            $id = (int) $n['id'];
            $base = (string) $n['lang'];
            $versions = [$base => $n];
            foreach ($translations[$id] ?? [] as $lang => $t) {
                if ($lang !== $base && trim((string) $t['title']) !== '') {
                    $versions[$lang] = $t;
                }
            }
            foreach ($versions as $lang => $v) {
                if (!in_array($lang, $langs, true)) {
                    continue;
                }
                $excerpt = trim((string) $v['excerpt']) !== '' ? (string) $v['excerpt'] : (string) $n['excerpt'];
                $content = trim((string) $v['content']) !== '' ? (string) $v['content'] : (string) $n['content'];
                $out[] = self::row(
                    'news', $id, $lang, Lang::t('Новость', $lang),
                    trim((string) $v['title']) !== '' ? (string) $v['title'] : (string) $n['title'],
                    (string) $n['slug'],
                    Locale::url('news/' . $n['slug'], $lang),
                    $excerpt,
                    [$excerpt, $content],
                    $n['published_at'] !== null ? (string) $n['published_at'] : null,
                    $n['published_at'] !== null ? (string) $n['published_at'] : null
                );
            }
        }

        return $out;
    }

    /** @param list<string> $langs @return list<array<string, mixed>> */
    private static function team(array $langs): array
    {
        $pdo = Database::pdo();
        $members = Database::rows($pdo->query(
            "SELECT id, name, position, department, unit, created_at FROM team_members WHERE status = 'published' ORDER BY sort_order, id"
        ));
        if ($members === []) {
            return [];
        }
        $translations = [];
        foreach (Database::rows($pdo->query('SELECT member_id, lang, name, position, department, unit FROM team_member_translations')) as $t) {
            $translations[(int) $t['member_id']][(string) $t['lang']] = $t;
        }
        $out = [];
        foreach ($langs as $lang) {
            $teamPage = self::teamPageSlug($lang);
            if ($teamPage === null) {
                continue;
            }
            foreach ($members as $m) {
                $t = $translations[(int) $m['id']][$lang] ?? [];
                $pick = static fn (string $k): string => trim((string) ($t[$k] ?? '')) !== '' ? (string) $t[$k] : (string) ($m[$k] ?? '');
                $anchor = trim((string) $m['department']) !== '' ? '#team-' . Slug::make((string) $m['department']) : '';
                $out[] = self::row(
                    'team', (int) $m['id'], $lang, Lang::t('Сотрудник', $lang),
                    $pick('name'), '',
                    Locale::url($teamPage, $lang) . $anchor,
                    $pick('position'),
                    [$pick('position'), $pick('department'), $pick('unit')],
                    (string) $m['created_at']
                );
            }
        }

        return $out;
    }

    /** @param list<string> $langs @return list<array<string, mixed>> */
    private static function entries(array $langs): array
    {
        $pdo = Database::pdo();
        $entries = Database::rows($pdo->query(
            "SELECT ce.id, ce.title, ce.slug, ce.data, ce.created_at, ct.slug AS type_slug, ct.name AS type_name, ct.root_url
             FROM content_entries ce JOIN content_types ct ON ct.id = ce.type_id
             WHERE ce.deleted_at IS NULL AND ce.status = 'published' AND ct.is_public = 1"
        ));
        $translations = [];
        foreach (Database::rows($pdo->query('SELECT entry_id, lang, title, data FROM content_entry_translations')) as $t) {
            $translations[(int) $t['entry_id']][(string) $t['lang']] = $t;
        }
        $out = [];
        foreach ($entries as $e) {
            foreach ($langs as $lang) {
                $t = $translations[(int) $e['id']][$lang] ?? [];
                $out[] = self::row(
                    'entry', (int) $e['id'], $lang, Lang::t((string) $e['type_name'], $lang),
                    trim((string) ($t['title'] ?? '')) !== '' ? (string) $t['title'] : (string) $e['title'],
                    (string) $e['slug'],
                    Locale::url(ContentType::entryPath(['slug' => $e['type_slug'], 'root_url' => $e['root_url'] ?? 0], (string) $e['slug']), $lang),
                    '',
                    [...self::jsonTexts((string) ($t['data'] ?? '')), ...self::jsonTexts((string) $e['data'])],
                    (string) $e['created_at']
                );
            }
        }

        return $out;
    }

    /** @param list<string> $langs @return list<array<string, mixed>> */
    private static function albums(array $langs): array
    {
        $pdo = Database::pdo();
        $albums = Database::rows($pdo->query('SELECT id, title, slug, description, created_at FROM photo_albums WHERE is_published = 1'));
        if ($albums === []) {
            return [];
        }
        $translations = [];
        foreach (Database::rows($pdo->query('SELECT album_id, lang, title, description FROM photo_album_translations')) as $t) {
            $translations[(int) $t['album_id']][(string) $t['lang']] = $t;
        }
        $captions = [];
        foreach (Database::rows($pdo->query("SELECT album_id, caption FROM photo_album_images WHERE caption <> ''")) as $c) {
            $captions[(int) $c['album_id']][] = (string) $c['caption'];
        }
        $out = [];
        foreach ($albums as $a) {
            foreach ($langs as $lang) {
                $t = $translations[(int) $a['id']][$lang] ?? [];
                $description = trim((string) ($t['description'] ?? '')) !== '' ? (string) $t['description'] : (string) $a['description'];
                $out[] = self::row(
                    'album', (int) $a['id'], $lang, Lang::t('Фотоальбом', $lang),
                    trim((string) ($t['title'] ?? '')) !== '' ? (string) $t['title'] : (string) $a['title'],
                    (string) $a['slug'],
                    Locale::url('albums/' . $a['slug'], $lang),
                    $description,
                    [$description, ...($captions[(int) $a['id']] ?? [])],
                    (string) $a['created_at']
                );
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function jsonTexts(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        array_walk_recursive($data, static function (mixed $v) use (&$out): void {
            if (is_string($v) && !preg_match('~^(https?://|/)\S*$~', $v)) {
                $out[] = $v;
            }
        });

        return $out;
    }

    private static function teamPageSlug(string $lang): ?string
    {
        $stmt = Database::pdo()->prepare(
            "SELECT p.slug FROM blocks b
             INNER JOIN pages p ON p.id = b.page_id
             WHERE b.type = 'team_list' AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY (p.lang = ?) DESC, p.id ASC LIMIT 1"
        );
        $stmt->execute([$lang]);
        $slug = $stmt->fetchColumn();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * @param list<string> $parts
     * @return array{entity:string,entity_id:int,lang:string,type:string,title:string,slug:string,url:string,excerpt:string,body:string,sort_date:?string,visible_from:?string}
     */
    private static function row(
        string $entity,
        int $id,
        string $lang,
        string $type,
        string $title,
        string $slug,
        string $url,
        string $excerpt,
        array $parts,
        ?string $sortDate,
        ?string $visibleFrom = null
    ): array {
        return [
            'entity' => $entity,
            'entity_id' => $id,
            'lang' => $lang,
            'type' => $type,
            'title' => TitleMarkup::plain($title),
            'slug' => $slug,
            'url' => $url,
            'excerpt' => mb_substr(self::plain($excerpt), 0, 300),
            // Апострофы приведены к одному знаку, как в запросе
            // (SearchQuery::normalize): иначе «o‘lchov» в тексте не
            // находился запросом «o'lchov» — LIKE сравнивает знаки буквально.
            'body' => SearchQuery::apostrophes(self::plain(implode("\n", [$title, ...$parts]))),
            'sort_date' => self::date($sortDate),
            'visible_from' => self::date($visibleFrom),
        ];
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $formatted = DateFormatter::format($value, 'Y-m-d H:i:s');

        return $formatted !== '' ? $formatted : null;
    }
}
