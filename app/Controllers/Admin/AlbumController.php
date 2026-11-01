<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\NewsAlbum;
use App\Core\Redirect;
use App\Core\View;
use App\Models\Language;
use App\Models\PhotoAlbum;
use App\Models\PhotoAlbumTranslation;

/**
 * Управление фотоальбомами: список + создание, редактирование с составом
 * фотографий (выбор из медиабиблиотеки), публикация, удаление.
 */
final class AlbumController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('admin/albums/index', ['items' => PhotoAlbum::all()]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = PhotoAlbum::create((string) ($_POST['title'] ?? ''));
        if ($id === null) {
            Flash::error('Укажите название альбома.');
            header('Location: /admin/albums');
            exit;
        }
        Flash::success('Альбом создан — добавьте фотографии.');
        header('Location: /admin/albums/' . $id . '/edit');
        exit;
    }

    /**
     * Альбомы для последних новостей-галерей: те, что опубликованы до
     * появления автосборки. Новые и обновлённые новости собирают альбом сами.
     */
    public function fromNews(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $limit = Input::post()->int('limit', NewsAlbum::BACKFILL_DEFAULT, 1, 200);
        $result = NewsAlbum::backfill($limit);
        $total = $result['created'] + $result['updated'];
        $total === 0
            ? Flash::warning('Новостей-галерей не нашлось: альбом собирается из новости, у которой больше одного фото (обложка и галерея) и вид «Умный макет» или «Галерея».')
            : Flash::success('Из новостей-галерей: создано альбомов — ' . $result['created'] . ', обновлено — ' . $result['updated'] . '.');
        Redirect::to('/admin/albums');
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $album = PhotoAlbum::findById((int) $params['id']);
        if (!$album) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/albums/form', [
            'album' => $album,
            'images' => PhotoAlbum::images((int) $album['id']),
            'translations' => PhotoAlbumTranslation::forAlbum((int) $album['id']),
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        if (PhotoAlbum::findById($id) === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        PhotoAlbum::update(
            $id,
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (string) ($_POST['cover_url'] ?? ''),
            !empty($_POST['is_published']),
            !empty($_POST['is_featured'])
        );
        $this->saveTranslations($id);
        Flash::success('Альбом сохранён.');
        header('Location: /admin/albums/' . $id . '/edit');
        exit;
    }

    /**
     * Сохраняет переводы (title, description) для всех НЕ-основных активных
     * языков из полей translations[<lang>][...].
     */
    private function saveTranslations(int $albumId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode) {
                continue;
            }
            $t = (array) ($input[$code] ?? []);
            PhotoAlbumTranslation::upsert($albumId, $code, [
                'title' => trim((string) ($t['title'] ?? '')),
                'description' => trim((string) ($t['description'] ?? '')),
            ]);
        }
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        PhotoAlbum::delete((int) $params['id']);
        Flash::success('Альбом удалён (вместе с составом; файлы остаются в медиабиблиотеке).');
        header('Location: /admin/albums');
        exit;
    }

    /**
     * Фото альбома одной формой: подписи, авторы, порядок, удаление и новые
     * снимки. Новые приходят двумя путями: со скриптом файл сразу уходит в
     * медиатеку и в форму попадает ссылка (`gallery_library[]`, общий мост
     * галереи новостей), без скрипта — сами файлы (`album_gallery[]`).
     *
     * @param array<string, string> $params
     */
    public function saveImages(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        if (PhotoAlbum::findById($id) === null) {
            Flash::error('Альбом не найден.');
            Redirect::to('/admin/albums');
        }

        $in = \App\Core\Input::post();
        $changed = PhotoAlbum::saveImages(
            $id,
            $in->map('caption'),
            $in->map('credit'),
            $in->map('sort', 11),
            $in->ids('delete')
        );

        $known = array_fill_keys(array_map(
            static fn (array $image): string => (string) $image['image_url'],
            PhotoAlbum::images($id)
        ), true);
        $added = 0;
        foreach ($in->strings('gallery_library') as $url) {
            if ($url === '' || str_starts_with($url, '//') || !\App\Core\UrlGuard::isSafeMedia($url) || isset($known[$url])) {
                continue;
            }
            if (PhotoAlbum::addImage($id, $url) !== null) {
                $known[$url] = true;
                $added++;
            }
        }
        $failed = 0;
        foreach (self::uploadedFiles('album_gallery') as $file) {
            try {
                $stored = \App\Core\Uploader::store($file, 'public', Auth::id());
                if (PhotoAlbum::addImage($id, \App\Models\FileEntry::publicUrl($stored)) !== null) {
                    $added++;
                }
            } catch (\Throwable $e) {
                $failed++;
                \App\Core\Logger::swallowed('Альбом: фото не загружено', $e);
            }
        }

        if ($failed > 0) {
            Flash::error('Не удалось загрузить фото: ' . $failed . '. Проверьте формат и размер файла.');
        } elseif ($added > 0 || $changed > 0) {
            Flash::success($added > 0 ? 'Добавлено фото: ' . $added . '.' : 'Фото альбома сохранены.');
        } else {
            Flash::success('Изменений нет.');
        }
        Redirect::to('/admin/albums/' . $id . '/edit#album-photos');
    }

    /**
     * Множественное поле файлов в виде списка одиночных загрузок.
     *
     * @return list<array<string, mixed>>
     */
    private static function uploadedFiles(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[] = [
                'name' => $raw['name'][$i],
                'type' => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => UPLOAD_ERR_OK,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }

        return $out;
    }
}
