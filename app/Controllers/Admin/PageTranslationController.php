<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\PageTranslationFile;
use App\Core\Redirect;
use App\Core\Xlsx;
use App\Models\Language;

/**
 * «Перевод файлом» в разделе «Страницы»: выгрузка текстов всех страниц
 * одного языка таблицей и загрузка переведённой таблицы языковыми версиями.
 * Как устроен файл и почему загрузка собирает перевод из исходника — в
 * `PageTranslationFile`.
 */
final class PageTranslationController
{
    /** Предел загружаемой таблицы: она распаковывается и разбирается в памяти. */
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public function export(): void
    {
        Auth::requireLogin();

        $codes = Language::activeCodes();
        $query = Input::query();
        $from = $query->oneOf('from', $codes, Language::defaultCode());
        $to = $query->oneOf('to', $codes, '');
        if ($to === '' || $to === $from) {
            Flash::error('Выберите язык перевода, отличный от исходного.');
            Redirect::to('/admin/pages');
        }

        $body = PageTranslationFile::export($from, $to);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . PageTranslationFile::fileName($from, $to) . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $body;
    }

    public function import(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $file = $_FILES['translation'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Файл не выбран или не загрузился.');
            Redirect::to('/admin/pages');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            Flash::error('Файл больше 10 МБ — это не таблица перевода страниц.');
            Redirect::to('/admin/pages');
        }

        try {
            $rows = Xlsx::read((string) $file['tmp_name']);
            $result = PageTranslationFile::import($rows, Language::activeCodes(), Input::post()->bool('overwrite'));
        } catch (\InvalidArgumentException $e) {
            Flash::error('Перевод не загружен. ' . $e->getMessage());
            Redirect::to('/admin/pages');
        }

        $done = $result['created'] + $result['updated'];
        $parts = [
            'Создано языковых версий: ' . $result['created'] . ', обновлено: ' . $result['updated'] . '.',
        ];
        if ($result['skipped_existing'] !== []) {
            $parts[] = 'Пропущены — перевод уже есть (отметьте «Перезаписать», чтобы заменить): '
                . implode(', ', array_map(static fn (string $t): string => '«' . $t . '»', $result['skipped_existing'])) . '.';
        }
        if ($result['stale'] > 0) {
            $parts[] = 'Строк, где исходный текст на сайте изменился после выгрузки: ' . $result['stale'] . ' — выгрузите таблицу заново и переведите их.';
        }
        if ($result['untranslated'] > 0) {
            $parts[] = 'Пустых строк перевода: ' . $result['untranslated'] . ' — там остался исходный текст.';
        }
        if ($result['unknown'] > 0) {
            $parts[] = 'Строк, которых на сайте больше нет: ' . $result['unknown'] . '.';
        }
        if ($done > 0) {
            $parts[] = 'Новые версии сохранены черновиками — проверьте и опубликуйте.';
        }
        $message = implode(' ', $parts);
        $done > 0 && $result['stale'] === 0 && $result['skipped_existing'] === [] ? Flash::success($message) : Flash::error($message);

        Redirect::to('/admin/pages');
    }
}
