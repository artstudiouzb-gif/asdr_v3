<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Asset;
use App\Core\Input;
use App\Core\Locale;
use App\Core\PdfViewer;
use App\Core\Redirect;
use App\Core\View;
use App\Models\Language;

/**
 * Просмотр PDF прямо на сайте (/pdf?file=/uploads/public/….pdf).
 *
 * Сервер отдаёт PDF с `Content-Disposition: inline`, но Chrome на Android и
 * встроенные браузеры Telegram/Instagram своего просмотрщика не имеют и
 * скачивают файл. Поэтому на таких устройствах ссылку на PDF перехватывает
 * `frontend.js` (`navigator.pdfViewerEnabled`) и ведёт сюда, а документ рисует
 * PDF.js со своего домена. Компьютеры показывают PDF сами — им страница не
 * нужна, и без JavaScript ссылка остаётся прямой.
 *
 * Открывается только PDF из своих загрузок: чужой адрес превратил бы страницу
 * в просмотрщик для любого файла в интернете под нашим доменом.
 *
 * `view=book` — тот же файл книгой с листанием (см. PdfViewer). Её открывают
 * на любом устройстве: ссылку ставит сам блок «Документы», когда редактор
 * выбрал для документа этот вид.
 */
final class PdfController
{
    public function show(): void
    {
        $query = Input::query();
        $file = self::filePath($query->str('file', '', PdfViewer::MAX_PATH));
        if ($file === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $book = $query->oneOf('view', PdfViewer::VIEWS, PdfViewer::VIEW_DOCUMENT) === PdfViewer::VIEW_BOOK;

        $target = self::languageTarget($query->str('lang', '', 8), $file, Locale::current(), Language::publicCodes(), $book);
        if ($target !== null) {
            Redirect::to($target);
        }

        header('X-Robots-Tag: noindex');
        $vars = [
            'fileUrl' => $file,
            'fileName' => rawurldecode(basename($file)),
            'pdfjsUrl' => self::localAsset('/assets/vendor/pdfjs/pdf.min.js'),
            'workerUrl' => self::localAsset('/assets/vendor/pdfjs/pdf.worker.min.js'),
        ];
        if ($book) {
            View::render('site/pdf_book', $vars + [
                'flipUrl' => self::localAsset('/assets/vendor/page-flip/page-flip.browser.js'),
                'bookScript' => self::localAsset('/assets/js/pdf-book.js'),
            ]);
            return;
        }
        View::render('site/pdf_viewer', $vars + [
            'viewerScript' => self::localAsset('/assets/js/pdf-viewer.js'),
        ]);
    }

    /**
     * Адрес языковой версии просмотра или null, если переходить некуда.
     * Скрипт ведёт на `/pdf?…&lang=uz`, а не на `/uz/pdf`: начало адреса
     * перехода у него постоянное, и ничего из разметки в него не попадает —
     * префикс языка ставит сервер.
     *
     * @param list<string> $publicCodes
     */
    public static function languageTarget(string $lang, string $file, string $current, array $publicCodes, bool $book = false): ?string
    {
        if ($lang === '' || $lang === $current || !in_array($lang, $publicCodes, true)) {
            return null;
        }

        return Locale::url('/pdf', $lang) . '?file=' . rawurlencode($file) . ($book ? '&view=' . PdfViewer::VIEW_BOOK : '');
    }

    /** Путь к своему PDF или null (см. PdfViewer::filePath). */
    public static function filePath(string $value): ?string
    {
        return PdfViewer::filePath($value);
    }

    /**
     * Модуль и воркер PDF.js обязаны быть с того же адреса, что и страница:
     * воркер с чужого домена браузер не запустит, а модуль без CORS не
     * загрузит. Поэтому версия файла берётся, а префикс CDN снимается.
     */
    private static function localAsset(string $path): string
    {
        $url = Asset::url($path);
        $cdn = Asset::cdnBase();

        return $cdn !== '' && str_starts_with($url, $cdn) ? substr($url, strlen($cdn)) : $url;
    }
}
