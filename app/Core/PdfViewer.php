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
     * тогда ссылка остаётся прямой. Языковой префикс берётся у страницы:
     * подписи кнопок книги переводятся.
     */
    public static function bookUrl(string $fileUrl, ?string $lang = null): ?string
    {
        $file = self::filePath($fileUrl);
        if ($file === null) {
            return null;
        }

        return Locale::url('/pdf', $lang ?? Locale::current()) . '?file=' . rawurlencode($file) . '&view=' . self::VIEW_BOOK;
    }
}
