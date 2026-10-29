<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Адреса страницы просмотра PDF (`/pdf`, Site\PdfController).
 *
 * Вид у просмотра два: обычный документ (его открывает `frontend.js` на
 * телефонах без своего просмотрщика) и книга с листанием — её редактор
 * выбирает у документа в блоке «Документы». Книга годится брошюре и отчёту,
 * а не постановлению: страница там картинка, текст в ней не выделить и не
 * найти, поэтому вид выбирается у документа, а не для всех PDF разом.
 */
final class PdfViewer
{
    public const VIEW_DOCUMENT = 'document';
    public const VIEW_BOOK = 'book';
    public const VIEWS = [self::VIEW_DOCUMENT, self::VIEW_BOOK];

    /** Ссылки на PDF длиннее этого числа знаков не бывает; больше — подделка. */
    public const MAX_PATH = 500;

    /**
     * Путь к своему PDF или null. Принимается путь загрузок, а не адрес:
     * ссылка на странице бывает переписана на CDN (`Asset::rewriteMedia`), и
     * скрипт присылает уже её путь. Открывается только свой файл — иначе под
     * нашим доменом показывался бы любой PDF из интернета.
     */
    public static function filePath(string $value): ?string
    {
        $value = trim($value);
        if (strlen($value) > self::MAX_PATH || !preg_match('#^/uploads/public/[^?\#\\\\]+\.pdf$#i', $value)) {
            return null;
        }

        return Media::localUploadPath(rawurldecode($value)) !== null ? $value : null;
    }

    /**
     * Адрес книги для ссылки документа или null, если файл не свой PDF —
     * тогда ссылка остаётся прямой. Адрес короткий — `/uz/book/<имя файла>`:
     * загрузки лежат одним каталогом, поэтому имени без «.pdf» хватает, а
     * путь в параметре (`/pdf?file=%2Fuploads%2F…`) читался как служебный.
     * Языковой префикс берётся у страницы: подписи кнопок книги переводятся.
     */
    public static function bookUrl(string $fileUrl, ?string $lang = null): ?string
    {
        $file = self::filePath($fileUrl);
        // Короткий адрес несёт одно имя: файлу во вложенном каталоге он не
        // подходит, и ссылка у такого остаётся прямой.
        if ($file === null || str_contains(rawurldecode(substr($file, strlen('/uploads/public/'))), '/')) {
            return null;
        }
        $name = rawurldecode(basename($file));
        $name = (string) preg_replace('/\.pdf$/i', '', $name);

        return Locale::url('/book/' . rawurlencode($name), $lang ?? Locale::current());
    }

    /**
     * Путь к PDF по имени из адреса книги или null. Имя — один сегмент без
     * каталогов: выход за пределы загрузок через него невозможен.
     */
    public static function fileFromBookName(string $name): ?string
    {
        $name = rawurldecode(trim($name));
        $name = (string) preg_replace('/\.pdf$/i', '', $name);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            return null;
        }

        return self::filePath('/uploads/public/' . rawurlencode($name) . '.pdf');
    }

    /**
     * Откуда книга: название документа и страница, где он стоит. Имя файла
     * посетителю ни о чём не говорит, а книга открывается в той же вкладке —
     * значит, шапке нужна дорога обратно. Берётся блок «Документы» с этим
     * файлом на опубликованной странице, сначала на языке книги. Не нашлось
     * — null.
     *
     * @return array{title:string,page_url:string,page_title:string}|null
     */
    public static function documentSource(string $file, string $lang): ?array
    {
        $stored = rawurldecode(basename($file));
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT b.data, p.lang, p.slug, p.title, p.is_home, p.section, p.entity_type
                 FROM blocks b JOIN pages p ON p.id = b.page_id
                 WHERE b.type = 'docs_list' AND b.is_active = 1
                   AND p.status = 'published' AND p.deleted_at IS NULL
                   AND b.data LIKE :needle
                 ORDER BY (p.lang = :lang) DESC, b.id
                 LIMIT 20"
            );
            $stmt->execute([':needle' => '%' . addcslashes($stored, '%_\\') . '%', ':lang' => $lang]);
            $rows = Database::rows($stmt);
        } catch (\Throwable $e) {
            Logger::swallowed('pdf book source', $e);
            return null;
        }

        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            foreach ((array) ($data['items'] ?? []) as $item) {
                $title = is_array($item) ? trim((string) ($item['title'] ?? '')) : '';
                if ($title === '') {
                    continue;
                }
                $urls = array_merge([(string) ($item['url'] ?? '')], array_map('strval', (array) ($item['files'] ?? [])));
                foreach ($urls as $url) {
                    if (rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) === $stored) {
                        return [
                            'title' => $title,
                            'page_url' => self::pageUrl($row),
                            'page_title' => TitleMarkup::plain(trim((string) ($row['title'] ?? ''))),
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Адрес страницы так, как его видит посетитель: главная — корень, шапка
     * раздела — сам раздел (свой адрес у неё отвечает 301), проект — под
     * /projects.
     *
     * @param array<string, mixed> $page
     */
    public static function pageUrl(array $page): string
    {
        $slug = trim((string) ($page['slug'] ?? ''));
        $section = (string) ($page['section'] ?? '');
        $path = match (true) {
            !empty($page['is_home']) => '/',
            in_array($section, ['news', 'projects'], true) => '/' . $section,
            ($page['entity_type'] ?? '') === 'project' => '/projects/' . $slug,
            default => '/' . $slug,
        };

        return Locale::url($path, (string) ($page['lang'] ?? '') ?: null);
    }
}
