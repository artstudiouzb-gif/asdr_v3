<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\News;
use App\Models\NewsImage;
use App\Models\NewsTranslation;
use App\Models\Redirect;

/**
 * Импорт новостей из WXR (Polylang и WPML) с переносом медиа.
 *
 * Polylang экспортирует язык и translation group напрямую. WPML в стандартном
 * WXR обычно не экспортирует таблицу icl_translations, поэтому для него
 * используется детерминированный план сопоставления:
 *  1) одинаковая дата/время публикации;
 *  2) одинаковая featured-картинка (с выбором ближайшей по времени группы);
 *  3) оставшаяся RU/UZ пара в тот же день с разницей не более двух часов.
 * Неоднозначные неосновные записи не импортируются молча: импорт завершается
 * ошибкой до записи в БД.
 */
final class LegacyWxrImporter
{
    /**
     * @return array{site:string,attachments:array<int,string>,posts:array<int,array<string,mixed>>,comments:int}
     */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($rss === false || !isset($rss->channel)) {
            return ['site' => '', 'attachments' => [], 'posts' => [], 'comments' => 0];
        }

        $ns = $rss->getNamespaces(true);
        $vendorHost = 'word' . 'press.org';
        $wp = $ns['wp'] ?? 'http://' . $vendorHost . '/export/1.2/';
        $content = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerpt = $ns['excerpt'] ?? 'http://' . $vendorHost . '/export/1.2/excerpt/';

        $channel = $rss->channel;
        $site = '';
        foreach ($channel->children($wp) as $name => $val) {
            if ($name === 'base_site_url' || ($name === 'base_blog_url' && $site === '')) {
                $site = rtrim((string) $val, '/');
            }
        }

        $attachments = [];
        $posts = [];
        $comments = 0;
        foreach ($channel->item as $item) {
            $w = $item->children($wp);
            $type = (string) $w->post_type;
            $id = (int) $w->post_id;

            if ($type === 'attachment') {
                $url = (string) $w->attachment_url;
                if ($id > 0 && $url !== '') {
                    $attachments[$id] = $url;
                }
                continue;
            }
            if ($type !== 'post') {
                continue;
            }

            $lang = '';
            $group = '';
            $categoryLabels = [];
            foreach ($item->category as $cat) {
                $domain = (string) $cat['domain'];
                $nicename = (string) $cat['nicename'];
                if ($domain === 'language') {
                    $lang = $nicename;
                } elseif ($domain === 'post_translations') {
                    $group = $nicename;
                } elseif ($domain === 'category') {
                    $categoryLabels[] = trim((string) $cat);
                }
            }

            $thumbId = 0;
            foreach ($w->postmeta as $meta) {
                if ((string) $meta->meta_key === '_thumbnail_id') {
                    $thumbId = (int) $meta->meta_value;
                }
            }

            $link = (string) $item->link;
            if ($lang === '') {
                $lang = self::inferWpmlLanguage($link, $categoryLabels);
            }

            $body = (string) $item->children($content)->encoded;
            $posts[] = [
                'id' => $id,
                'title' => (string) $item->title,
                'slug' => (string) $w->post_name,
                'link' => $link,
                'date' => (string) $w->post_date,
                'status' => (string) $w->status,
                'content' => $body,
                'excerpt' => (string) $item->children($excerpt)->encoded,
                'lang' => $lang,
                'group' => $group,
                'thumb_id' => $thumbId,
                'gallery_ids' => self::extractGalleryIds($body),
            ];
            $comments += count($w->comment);
        }

        return ['site' => $site, 'attachments' => $attachments, 'posts' => $posts, 'comments' => $comments];
    }

    /**
     * Строит чистый план импорта без сети и БД. Полезен для dry-run и тестов.
     *
     * @param array{site:string,attachments:array<int,string>,posts:array<int,array<string,mixed>>,comments?:int} $data
     * @param array<string,string> $langs source code => ArtStudio code; первый язык основной
     * @return array{groups:array<int,array<int,array<string,mixed>>>,unresolved:array<int,array<string,mixed>>,published:int,drafts:int,comments:int}
     */
    public static function plan(array $data, array $langs = []): array
    {
        $posts = array_values(array_filter(
            $data['posts'],
            static fn (array $p): bool => (string) ($p['status'] ?? '') === 'publish'
        ));
        $drafts = count($data['posts']) - count($posts);
        $primary = $langs !== [] ? (string) array_key_first($langs) : 'uz';

        // Если WXR содержит нативные Polylang-группы — используем только их.
        $hasNativeGroups = false;
        foreach ($posts as $p) {
            if ((string) ($p['group'] ?? '') !== '') {
                $hasNativeGroups = true;
                break;
            }
        }
        if ($hasNativeGroups) {
            $grouped = [];
            foreach ($posts as $p) {
                $key = (string) ($p['group'] ?? '') !== '' ? 'g:' . $p['group'] : 'p:' . $p['id'];
                $grouped[$key][] = $p;
            }
            return [
                'groups' => array_values($grouped),
                'unresolved' => [],
                'published' => count($posts),
                'drafts' => $drafts,
                'comments' => (int) ($data['comments'] ?? 0),
            ];
        }

        // WPML fallback: точные даты связывают абсолютное большинство переводов.
        $used = [];
        $groups = [];
        $byDate = [];
        foreach ($posts as $p) {
            $byDate[(string) ($p['date'] ?? '')][] = $p;
        }
        foreach ($byDate as $sameDate) {
            if (count($sameDate) < 2) {
                continue;
            }
            $seenLangs = [];
            $valid = false;
            foreach ($sameDate as $p) {
                $lang = (string) ($p['lang'] ?? '');
                if ($lang === '' || isset($seenLangs[$lang])) {
                    $valid = false;
                    break;
                }
                $seenLangs[$lang] = true;
                $valid = true;
            }
            if (!$valid || !isset($seenLangs[$primary])) {
                continue;
            }
            $groups[] = $sameDate;
            foreach ($sameDate as $p) {
                $used[(int) $p['id']] = true;
            }
        }

        // Каждая оставшаяся запись основного языка формирует самостоятельную новость.
        foreach ($posts as $p) {
            $id = (int) $p['id'];
            if (!isset($used[$id]) && (string) ($p['lang'] ?? '') === $primary) {
                $groups[] = [$p];
                $used[$id] = true;
            }
        }

        // Запоздалые переводы WPML часто повторно используют ту же featured-картинку.
        foreach ($posts as $p) {
            $id = (int) $p['id'];
            $lang = (string) ($p['lang'] ?? '');
            if (isset($used[$id]) || $lang === $primary || $lang === '') {
                continue;
            }
            $sig = self::mediaSignature((int) ($p['thumb_id'] ?? 0), $data['attachments']);
            if ($sig === '') {
                continue;
            }
            $candidates = [];
            foreach ($groups as $idx => $group) {
                if (self::groupHasLang($group, $lang)) {
                    continue;
                }
                $base = self::groupPrimary($group, $primary);
                if ($base === null || self::mediaSignature((int) ($base['thumb_id'] ?? 0), $data['attachments']) !== $sig) {
                    continue;
                }
                $candidates[] = [self::timeDistance($p, $base), $idx];
            }
            usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            if ($candidates !== [] && (count($candidates) === 1 || $candidates[0][0] < $candidates[1][0])) {
                $groups[$candidates[0][1]][] = $p;
                $used[$id] = true;
            }
        }

        // Последний безопасный fallback: перевод в тот же день, не дальше 2 часов.
        foreach ($posts as $p) {
            $id = (int) $p['id'];
            $lang = (string) ($p['lang'] ?? '');
            if (isset($used[$id]) || $lang === $primary || $lang === '') {
                continue;
            }
            $candidates = [];
            foreach ($groups as $idx => $group) {
                if (self::groupHasLang($group, $lang)) {
                    continue;
                }
                $base = self::groupPrimary($group, $primary);
                if ($base === null || substr((string) $base['date'], 0, 10) !== substr((string) $p['date'], 0, 10)) {
                    continue;
                }
                $distance = self::timeDistance($p, $base);
                if ($distance <= 7200) {
                    $candidates[] = [$distance, $idx];
                }
            }
            usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            if ($candidates !== [] && (count($candidates) === 1 || $candidates[0][0] < $candidates[1][0])) {
                $groups[$candidates[0][1]][] = $p;
                $used[$id] = true;
            }
        }

        $unresolved = [];
        foreach ($posts as $p) {
            if (!isset($used[(int) $p['id']])) {
                $unresolved[] = $p;
            }
        }

        usort($groups, static function (array $a, array $b) use ($primary): int {
            $ap = self::groupPrimary($a, $primary) ?? $a[0];
            $bp = self::groupPrimary($b, $primary) ?? $b[0];
            return strcmp((string) ($ap['date'] ?? ''), (string) ($bp['date'] ?? ''));
        });

        return [
            'groups' => $groups,
            'unresolved' => $unresolved,
            'published' => count($posts),
            'drafts' => $drafts,
            'comments' => (int) ($data['comments'] ?? 0),
        ];
    }

    /**
     * Пакет ограничивается либо числом записей (`limit`), либо бюджетом времени
     * (`timeBudget`, секунды), и начинается с группы `offset`. Курсор нужен
     * затем, что без него каждый следующий пакет пролистывал уже перенесённое
     * с начала плана, спрашивая News::slugExists() на каждую группу: стоимость
     * пакета росла вместе с его номером, и импорт упирался в шлюзовой таймаут
     * тем вернее, чем ближе был к концу. Порядок групп детерминирован (usort
     * стабилен, вход — порядок записей в XML), а контроллер сверяет sha256
     * файла перед каждым пакетом, поэтому курсор не может съехать.
     *
     * @param array{status?:string,authorId?:?int,langs?:array<string,string>,uploadsDir?:?string,limit?:int,offset?:int,timeBudget?:float,dryRun?:bool} $opts
     * @return array{imported:int,skipped:int,images:int,redirects:int,translations:int,errors:array<int,string>,source_published:int,source_drafts:int,source_comments:int,planned_news:int,unresolved:int,cursor:int,total:int}
     */
    public static function importFile(string $path, array $opts = []): array
    {
        $out = [
            'imported' => 0, 'skipped' => 0, 'images' => 0, 'redirects' => 0, 'translations' => 0, 'errors' => [],
            'source_published' => 0, 'source_drafts' => 0, 'source_comments' => 0, 'planned_news' => 0, 'unresolved' => 0,
            'cursor' => 0, 'total' => 0,
        ];
        if (!is_file($path)) {
            $out['errors'][] = 'Файл не найден: ' . $path;
            return $out;
        }
        $data = self::parse((string) file_get_contents($path));
        if ($data['posts'] === []) {
            $out['errors'][] = 'В файле не найдено записей типа «post» (проверьте формат экспорта WXR).';
            return $out;
        }

        $status = ($opts['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $authorId = $opts['authorId'] ?? null;
        $uploadsDir = $opts['uploadsDir'] ?? null;
        LegacyCmsImporter::clearImageCache();
        $limit = (int) ($opts['limit'] ?? 0);
        $offset = max(0, (int) ($opts['offset'] ?? 0));
        $budget = max(0.0, (float) ($opts['timeBudget'] ?? 0.0));
        $startedAt = microtime(true);
        $dryRun = !empty($opts['dryRun']);
        /** @var array<string,string> $langs */
        $langs = (array) ($opts['langs'] ?? []);
        $primaryWp = $langs !== [] ? (string) array_key_first($langs) : 'uz';
        $site = $data['site'];
        $att = $data['attachments'];

        $plan = self::plan($data, $langs);
        $out['source_published'] = $plan['published'];
        $out['source_drafts'] = $plan['drafts'];
        $out['source_comments'] = $plan['comments'];
        $out['planned_news'] = count($plan['groups']);
        $out['unresolved'] = count($plan['unresolved']);

        if ($plan['unresolved'] !== []) {
            $ids = array_map(static fn (array $p): string => (string) ($p['id'] ?? '?'), $plan['unresolved']);
            $out['errors'][] = 'Не удалось однозначно связать переводы WPML (post_id: ' . implode(', ', $ids) . '). Импорт не запущен.';
            return $out;
        }

        $groups = $plan['groups'];
        $total = count($groups);
        $out['total'] = $total;
        $index = min($offset, $total);
        $processed = 0;

        for (; $index < $total; $index++) {
            if ($limit > 0 && $out['imported'] >= $limit) {
                break;
            }
            // Бюджет проверяется только со второй записи пакета: пакет обязан
            // сдвинуть курсор хотя бы на одну группу, иначе одна медленная
            // запись остановила бы импорт навсегда, каждый раз возвращая
            // «перенесено 0» на том же месте.
            if ($budget > 0.0 && $processed > 0 && (microtime(true) - $startedAt) >= $budget) {
                break;
            }
            $group = $groups[$index];
            $processed++;
            $primary = self::groupPrimary($group, $primaryWp) ?? $group[0];
            $slug = (string) ($primary['slug'] ?? '');
            if ($slug === '') {
                $slug = Slug::make((string) ($primary['title'] ?? ''));
            }
            if ($slug === '') {
                $out['skipped']++;
                continue;
            }

            try {
                if (News::slugExists($slug)) {
                    $out['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $out['imported']++;
                    foreach ($group as $p) {
                        if ((int) $p['id'] !== (int) $primary['id'] && isset($langs[(string) ($p['lang'] ?? '')])) {
                            $out['translations']++;
                        }
                    }
                    continue;
                }

                [$body, $gallery, $bodyImages] = self::transferBody((string) $primary['content'], $site, $authorId, $uploadsDir, $att, true);
                $out['images'] += $bodyImages;
                $featuredUrl = (int) ($primary['thumb_id'] ?? 0) > 0 ? ($att[(int) $primary['thumb_id']] ?? '') : '';
                $cover = '';
                if ($featuredUrl !== '') {
                    $cover = (string) (LegacyCmsImporter::importImage($featuredUrl, $authorId, $uploadsDir) ?? '');
                    if ($cover !== '' && !in_array($cover, $gallery, true)) {
                        $out['images']++;
                    }
                }
                if ($cover === '' && $gallery !== []) {
                    $cover = $gallery[0];
                }

                $newsId = News::create([
                    'title' => self::plain((string) $primary['title']),
                    'slug' => $slug,
                    'excerpt' => mb_substr(self::plain((string) $primary['excerpt']), 0, 300),
                    'content' => $body,
                    'image' => $cover,
                    'status' => $status,
                    'published_at' => self::date((string) $primary['date']),
                    'author_id' => $authorId,
                ]);
                $out['imported']++;

                foreach (array_values(array_unique($gallery)) as $i => $pathUrl) {
                    NewsImage::create($newsId, $pathUrl, null, $i);
                }

                if (self::createRedirect((string) $primary['link'], '/news/' . $slug)) {
                    $out['redirects']++;
                }

                foreach ($group as $p) {
                    if ((int) $p['id'] === (int) $primary['id']) {
                        continue;
                    }
                    $sourceLang = (string) ($p['lang'] ?? '');
                    $artCode = $langs[$sourceLang] ?? '';
                    if ($artCode === '') {
                        continue;
                    }
                    [$translatedBody, , $translatedImages] = self::transferBody((string) $p['content'], $site, $authorId, $uploadsDir, $att, false);
                    $out['images'] += $translatedImages;
                    NewsTranslation::upsert($newsId, $artCode, [
                        'title' => self::plain((string) $p['title']),
                        'excerpt' => mb_substr(self::plain((string) $p['excerpt']), 0, 300),
                        'content' => $translatedBody,
                    ]);
                    $out['translations']++;
                    // Переводная запись использует тот же новый slug; Locale определит язык.
                    if (self::createRedirect((string) $p['link'], '/news/' . $slug)) {
                        $out['redirects']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['errors'][] = 'Запись "' . $slug . '": ' . $e->getMessage();
            }
        }
        $out['cursor'] = $index;

        return $out;
    }

    /** @return list<int> */
    public static function extractGalleryIds(string $html): array
    {
        $ids = [];

        // 1. Классический шорткод [gallery ids="1,2,3"] или [gallery ids='1,2,3']
        if (preg_match_all('/\[gallery\b[^\]]*\bids\s*=\s*(["\'])([^"\']+)\1[^\]]*\]/i', $html, $matches)) {
            foreach ($matches[2] as $csv) {
                foreach (explode(',', (string) $csv) as $id) {
                    $n = (int) trim($id);
                    if ($n > 0) {
                        $ids[$n] = $n;
                    }
                }
            }
        }

        // 2. Gutenberg галерея <!-- wp:gallery {"ids":[1,2,3],...} -->
        if (preg_match_all('/<!--\s*wp:gallery\b[^>]*?"ids"\s*:\s*\[([0-9,\s]+)\]/i', $html, $matches)) {
            foreach ($matches[1] as $csv) {
                foreach (explode(',', (string) $csv) as $id) {
                    $n = (int) trim($id);
                    if ($n > 0) {
                        $ids[$n] = $n;
                    }
                }
            }
        }

        // 3. Gutenberg одиночный блок <!-- wp:image {"id":123,...} -->
        if (preg_match_all('/<!--\s*wp:image\b[^>]*?"id"\s*:\s*(\d+)/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $n = (int) trim($id);
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        }

        // 4. HTML-класс wp-image-123
        if (preg_match_all('/\bwp-image-(\d+)\b/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $n = (int) trim($id);
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        }

        // 5. HTML data-id="123"
        if (preg_match_all('/\bdata-id\s*=\s*["\'](\d+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $n = (int) trim($id);
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        }

        return array_values($ids);
    }

    public static function stripGalleryShortcodes(string $html): string
    {
        return (string) preg_replace('/\[gallery\b[^\]]*\]/i', '', $html);
    }

    /** @param list<string> $categories */
    private static function inferWpmlLanguage(string $link, array $categories): string
    {
        $path = strtolower((string) parse_url($link, PHP_URL_PATH));
        if (preg_match('#^/ru(?:/|$)#', $path)) {
            return 'ru';
        }
        if (preg_match('#^/en(?:/|$)#', $path)) {
            return 'en';
        }
        foreach ($categories as $label) {
            $normalized = mb_strtolower(trim($label));
            if ($normalized === 'новости') {
                return 'ru';
            }
            if ($normalized === 'news') {
                return 'en';
            }
        }
        return 'uz';
    }

    /** @param array<int,array<string,mixed>> $group */
    private static function groupPrimary(array $group, string $primary): ?array
    {
        foreach ($group as $p) {
            if ((string) ($p['lang'] ?? '') === $primary) {
                return $p;
            }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $group */
    private static function groupHasLang(array $group, string $lang): bool
    {
        foreach ($group as $p) {
            if ((string) ($p['lang'] ?? '') === $lang) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $attachments */
    private static function mediaSignature(int $thumbId, array $attachments): string
    {
        $url = $thumbId > 0 ? (string) ($attachments[$thumbId] ?? '') : '';
        if ($url === '') {
            return '';
        }
        $name = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = (string) preg_replace('/-scaled$/', '', $stem);
        $stem = (string) preg_replace('/-\d+x\d+$/', '', $stem);
        return $stem . ($ext !== '' ? '.' . $ext : '');
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function timeDistance(array $a, array $b): int
    {
        $at = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $bt = strtotime((string) ($b['date'] ?? '')) ?: 0;
        return abs($at - $bt);
    }

    private static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Возвращает признак «редирект создан», а не приписывает счётчик в массив
     * итогов по ссылке. Причина та же, что у transferBody(): параметр
     * `array<string,mixed> &$out` расширял тип итогового массива до
     * `array<string,mixed>` на всю оставшуюся часть importFile(), и
     * объявленная форма ответа переставала проверяться анализатором.
     */
    private static function createRedirect(string $oldLink, string $to): bool
    {
        $from = (string) parse_url($oldLink, PHP_URL_PATH);

        return $from !== '' && trim($from, '/') !== '' && $from !== $to && Redirect::create($from, $to, 301);
    }

    /**
     * Число перенесённых картинок возвращается, а не приписывается в $out по
     * ссылке: параметр `array<string,mixed> &$out` расширял тип итогового
     * массива до `array<string,mixed>` на всю оставшуюся часть importFile(),
     * и объявленная форма ответа переставала проверяться.
     *
     * @param array<int,string> $attachments
     * @return array{0:string,1:array<int,string>,2:int}
     */
    private static function transferBody(
        string $html,
        string $site,
        ?int $authorId,
        ?string $uploadsDir,
        array $attachments,
        bool $includeGallery
    ): array {
        $map = [];
        $gallery = [];
        $images = 0;
        foreach (LegacyCmsImporter::extractImageUrls($html) as $src) {
            $abs = LegacyCmsImporter::normalizeImageUrl(LegacyCmsImporter::absoluteUrl($src, $site !== '' ? $site : ''));
            $newUrl = LegacyCmsImporter::importImage($abs, $authorId, $uploadsDir);
            if ($newUrl !== null) {
                $map[$src] = $newUrl;
                $gallery[] = $newUrl;
                $images++;
            }
        }

        if ($includeGallery) {
            foreach (self::extractGalleryIds($html) as $attachmentId) {
                $url = (string) ($attachments[$attachmentId] ?? '');
                if ($url === '') {
                    continue;
                }
                $newUrl = LegacyCmsImporter::importImage($url, $authorId, $uploadsDir);
                if ($newUrl !== null) {
                    $gallery[] = $newUrl;
                    $images++;
                }
            }
        }

        $clean = LegacyCmsImporter::stripResponsiveAttrs(LegacyCmsImporter::rewriteImages($html, $map));
        $clean = self::stripGalleryShortcodes($clean);

        return [$clean, array_values(array_unique($gallery)), $images];
    }

    private static function date(string $d): string
    {
        $ts = $d !== '' && $d !== '0000-00-00 00:00:00' ? strtotime($d) : false;
        return date('Y-m-d H:i:s', $ts !== false ? $ts : time());
    }
}
