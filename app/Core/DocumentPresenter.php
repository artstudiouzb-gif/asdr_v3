<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Общая подготовка карточек документов для docs_list и news_docs.
 */
final class DocumentPresenter
{
    /** @var array<string, string> */
    private const ICONS = [
        'pdf' => 'file-type-pdf',
        'doc' => 'file-type-doc',
        'docx' => 'file-type-doc',
        'xls' => 'file-type-xls',
        'xlsx' => 'file-type-xls',
        'csv' => 'file-type-xls',
        'ppt' => 'file-type-ppt',
        'pptx' => 'file-type-ppt',
        'zip' => 'file-zip',
        'rar' => 'file-zip',
        '7z' => 'file-zip',
    ];

    /** @var list<string> */
    private const DOWNLOADABLE = [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
        'xls', 'xlsx', 'ods', 'csv',
        'ppt', 'pptx', 'odp',
        'zip', 'rar', '7z',
    ];

    /**
     * Описание длиннее этого числа знаков в поле «Мета» — это не подпись
     * формата, а текст о документе. Поле «Мета» рассчитано на «PDF · 1,2 МБ»
     * и выводится капслоком; редакторы, у которых не было поля описания,
     * писали туда абзацы, и абзац набирался заглавными.
     */
    private const META_AS_DESCRIPTION = 60;

    /**
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>>|null $languages языки сайта; null — из базы
     * @return array{title:string,url:string,meta:string,extension:string,icon:string,direct_file:bool,search:string,description:string,cover:string,files:list<array{lang:string,label:string,name:string,url:string,meta:string,extension:string,direct_file:bool,current:bool}>}
     */
    public static function prepare(array $document, ?string $lang = null, ?array $languages = null): array
    {
        $title = trim((string) ($document['title'] ?? ''));
        $meta = trim((string) ($document['meta'] ?? ''));
        $description = self::description($document, $meta);
        if ($description !== '' && trim((string) ($document['description'] ?? '')) === '') {
            // Текст из «Меты» уехал в описание — подпись формата считается заново.
            $meta = '';
        }

        $files = self::files($document, $lang ?? Locale::current(), $languages);
        // Главный файл карточки — версия на языке страницы, затем общий файл,
        // затем первая из языковых: посетитель узбекской страницы скачивает
        // узбекский текст, не выбирая его из списка.
        $url = $files !== [] ? $files[0]['url'] : '';
        $extension = self::extension($url);
        if ($meta === '') {
            $meta = $files !== [] ? $files[0]['meta'] : '';
        }

        $search = mb_strtolower(trim($title . ' ' . $meta . ' ' . $extension . ' ' . strip_tags($description)));

        $cover = trim((string) ($document['cover'] ?? ''));
        if ($cover !== '' && !UrlGuard::isSafeMedia($cover)) {
            $cover = '';
        }

        return [
            'title' => $title,
            'url' => $url,
            'meta' => $meta,
            'extension' => $extension !== '' ? $extension : 'other',
            'icon' => self::ICONS[$extension] ?? 'file-text',
            'direct_file' => in_array($extension, self::DOWNLOADABLE, true),
            'search' => $search,
            'description' => $description,
            'cover' => $cover,
            'files' => $files,
        ];
    }

    /**
     * Описание документа — короткий форматированный текст из мини-редактора.
     * Чистится и на выводе: данные блока приезжают ещё и из файла шаблона
     * страницы, а там разметку никто не проверял.
     *
     * @param array<string, mixed> $document
     */
    private static function description(array $document, string $meta): string
    {
        $html = trim((string) ($document['description'] ?? ''));
        if ($html !== '') {
            return trim(HtmlSanitizer::sanitizeLead($html));
        }
        if (mb_strlen($meta) > self::META_AS_DESCRIPTION) {
            return '<p>' . htmlspecialchars($meta, ENT_QUOTES) . '</p>';
        }

        return '';
    }

    /**
     * Файлы документа: общий (`url`) и версии по языкам (`files` — код языка
     * => адрес). Первым идёт файл для языка страницы, остальные — в порядке
     * языков сайта. Один и тот же адрес дважды не выводится.
     *
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>>|null $languages языки сайта; null — из базы
     * @return list<array{lang:string,label:string,name:string,url:string,meta:string,extension:string,direct_file:bool,current:bool}>
     */
    public static function files(array $document, string $lang, ?array $languages = null): array
    {
        $byLang = is_array($document['files'] ?? null) ? $document['files'] : [];
        // Своё название ссылки редактора: «Постановление (PDF)» вместо
        // «Скачать», «Русская версия» вместо названия языка.
        $names = is_array($document['file_labels'] ?? null) ? $document['file_labels'] : [];
        $labels = [];
        foreach ($languages ?? \App\Models\Language::site() as $language) {
            $labels[(string) $language['code']] = trim((string) ($language['name'] ?? '')) ?: strtoupper((string) $language['code']);
        }

        $list = [];
        foreach ($labels as $code => $label) {
            $url = trim((string) ($byLang[$code] ?? ''));
            if ($url !== '' && UrlGuard::isSafeLink($url)) {
                $list[] = self::file($code, $label, $url, $code === $lang, trim((string) ($names[$code] ?? '')));
            }
        }

        $common = trim((string) ($document['url'] ?? ''));
        if ($common !== '' && UrlGuard::isSafeLink($common) && !in_array($common, array_column($list, 'url'), true)) {
            // Общий файл без языка: становится главным, если версии на языке
            // страницы нет, и стоит перед остальными переводами.
            $hasCurrent = in_array(true, array_column($list, 'current'), true);
            $entry = self::file('', '', $common, !$hasCurrent, trim((string) ($document['link_label'] ?? '')));
            $hasCurrent ? $list[] = $entry : array_unshift($list, $entry);
        }

        usort($list, static fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current']);

        return $list;
    }

    /** @return array{lang:string,label:string,name:string,url:string,meta:string,extension:string,direct_file:bool,current:bool} */
    private static function file(string $lang, string $label, string $url, bool $current, string $name = ''): array
    {
        $extension = self::extension($url);
        $parts = [];
        if ($extension !== '') {
            $parts[] = strtoupper($extension);
        }
        $size = self::localFileSize($url);
        if ($size !== null) {
            $parts[] = Format::fileSize($size);
        }

        return [
            'lang' => $lang,
            'label' => $label,
            'name' => $name,
            'url' => $url,
            'meta' => implode(' · ', $parts),
            'extension' => $extension !== '' ? $extension : 'other',
            'direct_file' => in_array($extension, self::DOWNLOADABLE, true),
            'current' => $current,
        ];
    }

    /**
     * Семейство формата для значка-листа: цвет у PDF, текстов, таблиц,
     * презентаций и архивов свой, остальное — нейтральный лист.
     */
    public static function family(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'pdf',
            'doc', 'docx', 'odt', 'rtf', 'txt' => 'doc',
            'xls', 'xlsx', 'ods', 'csv' => 'xls',
            'ppt', 'pptx', 'odp' => 'ppt',
            'zip', 'rar', '7z' => 'zip',
            default => 'other',
        };
    }

    private static function extension(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower((string) pathinfo(rawurldecode($path), PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) ? $extension : '';
    }

    private static function localFileSize(string $url): ?int
    {
        $urlPrefix = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
        $diskBase = rtrim((string) Config::get('paths.public_uploads', ''), '/');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($diskBase === '' || !str_starts_with($path, $urlPrefix . '/')) {
            return null;
        }

        $relative = substr($path, strlen($urlPrefix));
        if ($relative === '' || str_contains(str_replace('\\', '/', $relative), '/../')) {
            return null;
        }

        $file = $diskBase . $relative;
        $size = is_file($file) ? @filesize($file) : false;

        return $size === false ? null : (int) $size;
    }
}
