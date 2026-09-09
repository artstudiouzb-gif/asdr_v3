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

    /** @return array{title:string,url:string,meta:string,extension:string,icon:string,direct_file:bool,search:string} */
    public static function prepare(array $document): array
    {
        $title = trim((string) ($document['title'] ?? ''));
        $url = trim((string) ($document['url'] ?? ''));
        $extension = self::extension($url);
        $meta = trim((string) ($document['meta'] ?? ''));

        if ($meta === '') {
            $parts = [];
            if ($extension !== '') {
                $parts[] = strtoupper($extension);
            }
            $size = self::localFileSize($url);
            if ($size !== null) {
                $parts[] = Format::fileSize($size);
            }
            $meta = implode(' · ', $parts);
        }

        $search = mb_strtolower(trim($title . ' ' . $meta . ' ' . $extension));

        return [
            'title' => $title,
            'url' => $url,
            'meta' => $meta,
            'extension' => $extension !== '' ? $extension : 'other',
            'icon' => self::ICONS[$extension] ?? 'file-text',
            'direct_file' => in_array($extension, self::DOWNLOADABLE, true),
            'search' => $search,
        ];
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
