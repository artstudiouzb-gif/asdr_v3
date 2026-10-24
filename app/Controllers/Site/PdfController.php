<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Asset;
use App\Core\Input;
use App\Core\Media;
use App\Core\View;

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
 */
final class PdfController
{
    /** Ссылки на PDF длиннее этого числа знаков не бывает; больше — подделка. */
    private const MAX_PATH = 500;

    public function show(): void
    {
        $file = self::filePath(Input::query()->str('file', '', self::MAX_PATH));
        if ($file === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        header('X-Robots-Tag: noindex');
        View::render('site/pdf_viewer', [
            'fileUrl' => $file,
            'fileName' => rawurldecode(basename($file)),
            'pdfjsUrl' => self::localAsset('/assets/vendor/pdfjs/pdf.min.mjs'),
            'workerUrl' => self::localAsset('/assets/vendor/pdfjs/pdf.worker.min.mjs'),
            'viewerScript' => self::localAsset('/assets/js/pdf-viewer.js'),
        ]);
    }

    /**
     * Путь к своему PDF или null. Принимается путь загрузок, а не адрес:
     * ссылка на странице бывает переписана на CDN (`Asset::rewriteMedia`), и
     * скрипт присылает уже её путь.
     */
    public static function filePath(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('#^/uploads/public/[^?\#\\\\]+\.pdf$#i', $value)) {
            return null;
        }

        return Media::localUploadPath(rawurldecode($value)) !== null ? $value : null;
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
