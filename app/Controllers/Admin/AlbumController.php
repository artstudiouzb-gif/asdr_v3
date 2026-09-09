<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
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

    /** @param array<string, string> $params */
    public function addImage(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $ok = PhotoAlbum::addImage($id, (string) ($_POST['image_url'] ?? ''), (string) ($_POST['caption'] ?? ''), (string) ($_POST['credit'] ?? ''));
        if ($ok === null) {
            Flash::error('Выберите изображение (URL пуст).');
        } else {
            Flash::success('Фото добавлено в альбом.');
        }
        header('Location: /admin/albums/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function deleteImage(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        PhotoAlbum::deleteImage((int) $params['imageId']);
        Flash::success('Фото убрано из альбома.');
        header('Location: /admin/albums/' . (int) $params['id'] . '/edit');
        exit;
    }
}
