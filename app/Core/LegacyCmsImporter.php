<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\News;
use App\Models\NewsImage;
use App\Models\NewsTranslation;
use App\Models\Redirect;

/**
 * Импорт новостей из совместимого REST API (/wp-json/wp/v2/posts) с
 * переносом фотографий. Чистые методы (mapPost/extractImageUrls/rewriteImages)
 * покрыты тестами; importAll — оркестрация с сетью и БД.
 *
 * По умолчанию импортирует как ЧЕРНОВИКИ (безопасно: контент-редактор
 * просматривает перед публикацией). Идемпотентно: посты с уже существующим
 * slug пропускаются, повторный запуск не плодит дубли.
 */
final class LegacyCmsImporter
{
    /** Разрешённые расширения картинок для переноса. */
    private const IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Кэш перенесённых картинок за один запуск: исходный URL => новый URL. */
    private static array $imageCache = [];

    /**
     * @param array{limit?:int,perPage?:int,status?:string,authorId?:?int,dryRun?:bool,langs?:array<string,string>} $opts
     *   langs — карта «код языка источника => код языка ArtStudio», первый = основной
     *   (в него пишется базовая строка новости, остальные — переводы). Пусто —
     *   одноязычный импорт.
     * @return array{imported:int,skipped:int,images:int,redirects:int,translations:int,errors:array<int,string>}
     */
    public static function importAll(string $baseUrl, array $opts = []): array
    {
        $base = rtrim($baseUrl, '/');
        if (!UrlGuard::isSafeRemote($base)) {
            throw new \InvalidArgumentException('Адрес исходной CMS небезопасен или указывает на внутреннюю сеть.');
        }
        $perPage = max(1, min(100, (int) ($opts['perPage'] ?? 20)));
        $limit = (int) ($opts['limit'] ?? 0); // 0 = все
        $status = ($opts['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $authorId = $opts['authorId'] ?? null;
        $dryRun = !empty($opts['dryRun']);
        /** @var array<string,string> $langs */
        $langs = (array) ($opts['langs'] ?? []);
        $primaryWp = $langs !== [] ? (string) array_key_first($langs) : '';

        self::$imageCache = [];
        $out = ['imported' => 0, 'skipped' => 0, 'images' => 0, 'redirects' => 0, 'translations' => 0, 'errors' => []];
        $page = 1;

        while (true) {
            $url = $base . '/wp-json/wp/v2/posts?_embed=1&per_page=' . $perPage . '&page=' . $page
                . ($primaryWp !== '' ? '&lang=' . rawurlencode($primaryWp) : '');
            $resp = Http::request('GET', $url, '', ['Accept: application/json']);
            if (($resp['status'] ?? 0) !== 200) {
                if ($page === 1) {
                    $out['errors'][] = 'REST недоступен (HTTP ' . ($resp['status'] ?? 0) . '): ' . $url;
                }
                break;
            }
            $posts = json_decode((string) $resp['body'], true);
            if (!is_array($posts) || $posts === []) {
                break;
            }

            foreach ($posts as $post) {
                if ($limit > 0 && $out['imported'] >= $limit) {
                    return $out;
                }
                try {
                    $mapped = self::mapPost($post);
                    if ($mapped['slug'] === '' || News::slugExists($mapped['slug'])) {
                        $out['skipped']++;
                        continue;
                    }
                    if ($dryRun) {
                        $out['imported']++;
                        continue;
                    }

                    $newsId = self::createFromPost($mapped, $status, $authorId, $base, $out);
                    $out['imported']++;

                    // Переводы (Polylang/WPML): post['translations'] = {код: id}.
                    $trans = is_array($post['translations'] ?? null) ? $post['translations'] : [];
                    foreach ($langs as $wpCode => $artCode) {
                        if ($wpCode === $primaryWp) {
                            continue;
                        }
                        $tid = (int) ($trans[$wpCode] ?? 0);
                        if ($tid <= 0) {
                            continue;
                        }
                        $tPost = self::fetchPost($base, $tid);
                        if ($tPost === null) {
                            continue;
                        }
                        $tm = self::mapPost($tPost);
                        $tContent = self::stripResponsiveAttrs(self::rewriteImages($tm['content'], self::transferAll($tm['content'], $base, $authorId, $out)));
                        NewsTranslation::upsert($newsId, (string) $artCode, [
                            'title' => $tm['title'],
                            'excerpt' => $tm['excerpt'],
                            'content' => $tContent,
                        ]);
                        $out['translations']++;
                    }
                } catch (\Throwable $e) {
                    $out['errors'][] = 'Пост "' . (($post['slug'] ?? '') ?: '?') . '": ' . $e->getMessage();
                }
            }

            $page++;
            if (count($posts) < $perPage) {
                break; // последняя страница
            }
        }

        return $out;
    }

    /**
     * Создаёт базовую новость из размеченного поста: переносит картинки тела и
     * обложку, пишет News, галерею и редирект. Возвращает id новости.
     *
     * @param array{title:string,slug:string,excerpt:string,content:string,published_at:string,featured_url:string,link:string} $mapped
     * @param array<string,mixed> $out
     */
    private static function createFromPost(array $mapped, string $status, ?int $authorId, string $base, array &$out): int
    {
        $map = self::transferAll($mapped['content'], $base, $authorId, $out);
        $content = self::stripResponsiveAttrs(self::rewriteImages($mapped['content'], $map));
        $galleryPaths = array_values($map);

        $cover = '';
        if ($mapped['featured_url'] !== '') {
            $cover = (string) (self::transferImage($mapped['featured_url'], $authorId) ?? '');
            if ($cover !== '' && !in_array($cover, $galleryPaths, true)) {
                $out['images']++;
            }
        }
        if ($cover === '' && $galleryPaths !== []) {
            $cover = $galleryPaths[0];
        }

        $newsId = News::create([
            'title' => $mapped['title'],
            'slug' => $mapped['slug'],
            'excerpt' => $mapped['excerpt'],
            'content' => $content,
            'image' => $cover,
            'status' => $status,
            'published_at' => $mapped['published_at'],
            'author_id' => $authorId,
        ]);

        foreach ($galleryPaths as $i => $p) {
            NewsImage::create($newsId, $p, null, $i);
        }

        $from = (string) parse_url($mapped['link'], PHP_URL_PATH);
        $to = '/news/' . $mapped['slug'];
        if ($from !== '' && trim($from, '/') !== '' && $from !== $to && Redirect::create($from, $to, 301)) {
            $out['redirects']++;
        }

        return $newsId;
    }

    /**
     * Переносит все картинки тела статьи (только с исходного домена) и возвращает
     * карту [старый src => новый URL] для перезаписи.
     *
     * @param array<string,mixed> $out
     * @return array<string,string>
     */
    private static function transferAll(string $html, string $base, ?int $authorId, array &$out): array
    {
        $map = [];
        foreach (self::extractImageUrls($html) as $src) {
            // Нормализуем (снимаем Photon-CDN и query) — так внутренние картинки,
            // завёрнутые в i0.wp.com, распознаются как «свои» и тянутся в оригинале.
            $abs = self::normalizeImageUrl(self::absoluteUrl($src, $base));
            if ($base !== '' && !self::sameOrigin($abs, $base)) {
                continue; // внешние картинки не тянем
            }
            $cached = isset(self::$imageCache[$abs]);
            $newUrl = self::transferImage($abs, $authorId);
            if ($newUrl !== null) {
                $map[$src] = $newUrl; // ключ — исходный src из HTML (для точной замены)
                if (!$cached) {
                    $out['images']++;
                }
            }
        }

        return $map;
    }

    /** Запрашивает один пост по id (для переводов). */
    private static function fetchPost(string $base, int $id): ?array
    {
        $resp = Http::request('GET', $base . '/wp-json/wp/v2/posts/' . $id . '?_embed=1', '', ['Accept: application/json']);
        if (($resp['status'] ?? 0) !== 200) {
            return null;
        }
        $post = json_decode((string) $resp['body'], true);

        return is_array($post) ? $post : null;
    }

    /**
     * Преобразует пост WP REST в поля новости ArtStudio.
     *
     * @param array<string,mixed> $post
     * @return array{title:string,slug:string,excerpt:string,content:string,published_at:string,featured_url:string,link:string}
     */
    public static function mapPost(array $post): array
    {
        $rendered = static fn ($v): string => is_array($v) ? (string) ($v['rendered'] ?? '') : (string) $v;

        $title = trim(html_entity_decode(strip_tags($rendered($post['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $slug = trim((string) ($post['slug'] ?? ''));
        if ($slug === '' && $title !== '') {
            $slug = Slug::make($title);
        }
        $content = $rendered($post['content'] ?? '');
        $excerpt = trim(html_entity_decode(strip_tags($rendered($post['excerpt'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $excerpt = mb_substr(preg_replace('/\s+/', ' ', $excerpt) ?? '', 0, 300);

        $date = (string) ($post['date'] ?? $post['date_gmt'] ?? '');
        $ts = $date !== '' ? strtotime($date) : false;
        $publishedAt = date('Y-m-d H:i:s', $ts !== false ? $ts : time());

        $featured = '';
        $media = $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
        if (is_string($media)) {
            $featured = $media;
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'content' => $content,
            'published_at' => $publishedAt,
            'featured_url' => $featured,
            'link' => (string) ($post['link'] ?? ''),
        ];
    }

    /** Сброс кэша перенесённых картинок (для тестов и повторных запусков). */
    public static function clearImageCache(): void
    {
        self::$imageCache = [];
    }

    /**
     * Все значения src из <img> и прямые ссылки на изображения из <a href="..."> в HTML.
     *
     * @return array<int,string>
     */
    public static function extractImageUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $trimmed = trim((string) $u);
                if ($trimmed !== '') {
                    $urls[] = $trimmed;
                }
            }
        }
        if (preg_match_all('/<a[^>]+href\s*=\s*["\']([^"\']+\.(?:jpe?g|png|gif|webp)(?:\?[^"\']*)?)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $trimmed = trim((string) $u);
                if ($trimmed !== '') {
                    $urls[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Возвращает список URL-кандидатов в порядке убывания разрешения:
     * 1) оригинальный файл без суффикса размера (-300x200, -1024x768);
     * 2) вариант -scaled (WP 5.3+ big image threshold);
     * 3) исходный переданный URL (fallback).
     *
     * @return list<string>
     */
    public static function imageUrlCandidates(string $url): array
    {
        $normalized = self::normalizeImageUrl($url);
        $path = (string) parse_url($normalized, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return [$normalized];
        }

        $candidates = [];
        if (preg_match('#^(.+)-\d+x\d+\.' . preg_quote($ext, '#') . '$#i', $normalized, $m)) {
            $baseStem = $m[1];
            $candidates[] = $baseStem . '.' . $ext;
            $candidates[] = $baseStem . '-scaled.' . $ext;
            $candidates[] = $normalized;
        } elseif (preg_match('#^(.+)-scaled\.' . preg_quote($ext, '#') . '$#i', $normalized, $m)) {
            $baseStem = $m[1];
            $candidates[] = $baseStem . '.' . $ext;
            $candidates[] = $normalized;
        } else {
            $candidates[] = $normalized;
            $baseStem = substr($normalized, 0, -(strlen($ext) + 1));
            $candidates[] = $baseStem . '-scaled.' . $ext;
        }

        // Если исходная ссылка — raster (jpg/jpeg/png), добавляем WebP-варианты
        // со старого сайта в конец списка: старая CMS и плагины конвертации
        // часто удаляют оригинальный JPG или хранят только WebP.
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $candidates[] = $baseStem . '.webp';
            $candidates[] = $baseStem . '-scaled.webp';
            if ($normalized !== $baseStem . '.' . $ext) {
                $candidates[] = (string) preg_replace('/\.' . preg_quote($ext, '#') . '$/i', '.webp', $normalized);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Заменяет старые URL картинок на новые по карте [старый => новый].
     *
     * @param array<string,string> $map
     */
    public static function rewriteImages(string $html, array $map): string
    {
        if ($map === []) {
            return $html;
        }

        return strtr($html, $map);
    }

    /**
     * Нормализует URL картинки к оригиналу на исходном домене:
     * — снимает обёртку Jetpack Photon (i0-3.wp.com/<домен>/…);
     * — отбрасывает query (?resize=…&ssl=1&w=…), возвращая полноразмерный файл.
     */
    public static function normalizeImageUrl(string $url): string
    {
        $url = (string) preg_replace('#^(https?:)?//i[0-9]\.wp\.com/#i', 'https://', $url);
        $url = (string) preg_replace('/\?.*$/', '', $url);

        return $url;
    }

    /**
     * Убирает адаптивные атрибуты srcset/sizes (после переноса они указывают на
     * старый CDN и после отключения исходного сайта «протухнут»). Браузер
     * откатится на переписанный src.
     */
    public static function stripResponsiveAttrs(string $html): string
    {
        $html = (string) preg_replace('/\s+srcset\s*=\s*"[^"]*"/i', '', $html);
        $html = (string) preg_replace("/\s+srcset\s*=\s*'[^']*'/i", '', $html);
        $html = (string) preg_replace('/\s+sizes\s*=\s*"[^"]*"/i', '', $html);
        $html = (string) preg_replace("/\s+sizes\s*=\s*'[^']*'/i", '', $html);

        return $html;
    }

    /** Абсолютизирует ссылку картинки (protocol-relative / относительная). */
    public static function absoluteUrl(string $src, string $base): string
    {
        if (str_starts_with($src, '//')) {
            $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
            return $scheme . ':' . $src;
        }
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        if ($src !== '' && $src[0] === '/') {
            return $base . $src;
        }

        return $base . '/' . ltrim($src, '/');
    }

    /**
     * Скачивает картинку и сохраняет в медиабиблиотеку через Uploader.
     * Возвращает публичный URL или null при ошибке.
     */
    private static function transferImage(string $url, ?int $uploadedBy): ?string
    {
        return self::importImage($url, $uploadedBy);
    }

    /**
     * Переносит картинку в медиабиблиотеку: сначала из локальной папки
     * wp-content/uploads (если задана $uploadsDir), иначе скачивает по URL.
     * Автоматически разрешает thumbnail-URL к полноразмерному оригиналу.
     * Возвращает публичный URL или null. Кэшируется по исходному URL.
     */
    public static function importImage(string $url, ?int $uploadedBy, ?string $uploadsDir = null): ?string
    {
        $normalizedUrl = self::normalizeImageUrl($url);
        if (isset(self::$imageCache[$normalizedUrl])) {
            return self::$imageCache[$normalizedUrl];
        }
        if (isset(self::$imageCache[$url])) {
            return self::$imageCache[$url];
        }

        $candidates = self::imageUrlCandidates($normalizedUrl);
        foreach ($candidates as $cand) {
            if (isset(self::$imageCache[$cand])) {
                $cached = self::$imageCache[$cand];
                self::$imageCache[$normalizedUrl] = $cached;
                self::$imageCache[$url] = $cached;
                return $cached;
            }
        }

        foreach ($candidates as $cand) {
            try {
                $tmp = tempnam(sys_get_temp_dir(), 'wpimg_');
                if ($tmp === false) {
                    continue;
                }
                $got = false;
                if ($uploadsDir !== null) {
                    $local = self::resolveLocal($cand, $uploadsDir);
                    if ($local !== null && is_file($local)) {
                        $got = @copy($local, $tmp); // копия: оригинал в папке не трогаем
                    }
                }
                if (!$got && UrlGuard::isSafeRemote($cand)) {
                    $got = self::download($cand, $tmp);
                }
                if (!$got) {
                    @unlink($tmp);
                    continue;
                }
                $pathExt = strtolower((string) pathinfo((string) parse_url($cand, PHP_URL_PATH), PATHINFO_EXTENSION));
                $detectedMime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $mimeExt = self::extFromMime($detectedMime);
                // Фактический формат скачанного файла имеет приоритет: URL может
                // заканчиваться на .jpg, но старый сервер (или плагин WebP Express)
                // отдаёт под ним WebP. Ошибочное расширение приведёт к отказу
                // валидатора Uploader::storeFromPath(), и картинка не сохранится.
                $ext = $mimeExt ?? (in_array($pathExt, self::IMG_EXT, true) ? $pathExt : null);
                if ($ext === null) {
                    @unlink($tmp);
                    continue;
                }
                $named = $tmp . '.' . $ext;
                @rename($tmp, $named);
                $stem = pathinfo((string) parse_url($cand, PHP_URL_PATH), PATHINFO_FILENAME);
                $origFileName = 'wp-' . ($stem !== '' ? $stem : 'image') . '.' . $ext;
                $file = Uploader::storeFromPath($named, $origFileName, (int) filesize($named), 'public', $uploadedBy, false);
                @unlink($named);

                $publicUrl = \App\Models\FileEntry::publicUrl($file);
                self::$imageCache[$url] = $publicUrl;
                self::$imageCache[$normalizedUrl] = $publicUrl;
                foreach ($candidates as $c) {
                    self::$imageCache[$c] = $publicUrl;
                }

                return $publicUrl;
            } catch (\Throwable) {
                // пробуем следующий кандидат с меньшим приоритетом
            }
        }

        return null;
    }

    // Ожидание одной картинки со старого сайта. Прежние 60 секунд означали,
    // что одна недоступная ссылка съедает весь шлюзовой таймаут импорта и
    // роняет пакет целиком, хотя остальные записи в нём здоровы. Картинка,
    // которая не начала отдаваться за 15 секунд, с большой вероятностью не
    // отдастся вовсе; пропуск такой картинки дешевле обрыва пакета.
    private const IMAGE_TIMEOUT = 15;

    /** Скачивание с закреплением проверенного публичного IP и лимитом 20 МБ. */
    private static function download(string $url, string $dest): bool
    {
        $response = Http::getSafeRemote(
            $url,
            ['User-Agent: ArtStudio-Legacy-Import/1.0'],
            self::IMAGE_TIMEOUT,
            20 * 1024 * 1024
        );
        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            return false;
        }

        return file_put_contents($dest, $response['body'], LOCK_EX) !== false;
    }

    private static function sameOrigin(string $url, string $base): bool
    {
        $a = parse_url($url);
        $b = parse_url($base);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        $schemeA = strtolower((string) ($a['scheme'] ?? ''));
        $schemeB = strtolower((string) ($b['scheme'] ?? ''));
        $hostA = strtolower((string) ($a['host'] ?? ''));
        $hostB = strtolower((string) ($b['host'] ?? ''));
        $portA = (int) ($a['port'] ?? ($schemeA === 'https' ? 443 : 80));
        $portB = (int) ($b['port'] ?? ($schemeB === 'https' ? 443 : 80));

        return $schemeA === $schemeB && $hostA !== '' && $hostA === $hostB && $portA === $portB;
    }

    /** Сопоставляет URL картинки локальному файлу в папке wp-content/uploads. */
    private static function resolveLocal(string $url, string $uploadsDir): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }
        if (preg_match('#/uploads/(.+)$#', $path, $m)) {
            $rel = $m[1];
        } else {
            $rel = ltrim($path, '/');
        }
        $rel = str_replace('..', '', rawurldecode($rel));

        $full = rtrim($uploadsDir, '/') . '/' . ltrim($rel, '/');
        if (is_file($full)) {
            return $full;
        }

        // Если в локальной папке нет .jpg/.png, но есть .webp — берём его
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $full);
        if ($webp !== null && $webp !== $full && is_file($webp)) {
            return $webp;
        }

        return $full;
    }

    private static function extFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
