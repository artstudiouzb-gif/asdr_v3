<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\MediaMetadataSchema;
use App\Core\RbacGuard;
use App\Core\Uploader;
use App\Core\View;
use App\Models\FileEntry;

final class FileController
{
    public function index(): void
    {
        Auth::requireLogin();
        $canManageProtected = RbacGuard::can('manage_protected_files');
        View::render('admin/files/index', [
            'items' => FileEntry::filtered($_GET, $canManageProtected),
            'availableDates' => FileEntry::availableDates(),
            'canManageProtected' => $canManageProtected,
        ]);
    }

    /**
     * JSON-список публичных файлов для модальной медиабиблиотеки.
     * Изображения возвращаются вместе с редакционными метаданными, поэтому их
     * можно описать до сохранения новости и затем переиспользовать.
     */
    public function library(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        try {
            MediaMetadataSchema::ensure();
        } catch (\Throwable $e) {
            $this->json(['items' => [], 'error' => 'Не удалось подготовить метаданные медиабиблиотеки.'], 500);
        }

        // Фильтр по виду файлов: image (по умолчанию), raster, svg, video,
        // audio, document, all_files, all. Список видов — у модели, чтобы
        // выдача и счётчики боковой колонки не разошлись.
        $type = (string) ($_GET['type'] ?? 'image');
        $type = in_array($type, FileEntry::libraryTypes(), true) ? $type : 'image';
        $sort = (string) ($_GET['sort'] ?? 'date_desc');
        $sort = in_array($sort, FileEntry::librarySorts(), true) ? $sort : 'date_desc';
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 300)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $query = trim((string) ($_GET['q'] ?? ''));

        $items = [];
        foreach (FileEntry::libraryFiltered($type, $limit, $offset, $query, $sort) as $file) {
            $items[] = self::libraryItem($file);
        }

        echo json_encode([
            'items' => $items,
            'counts' => FileEntry::libraryCounts($query),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function upload(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        // AJAX-сохранение описания уже загруженного файла. Оно использует
        // существующий защищённый POST-маршрут, поэтому отдельный публичный API
        // для метаданных не появляется.
        $metadataId = (int) ($_POST['metadata_id'] ?? 0);
        if ($metadataId > 0) {
            $file = FileEntry::findById($metadataId);
            if ($file === null) {
                $this->json(['ok' => false, 'error' => 'Файл не найден.'], 404);
            }
            if (($file['access_type'] ?? '') === 'protected') {
                RbacGuard::requirePermission('manage_protected_files');
            }

            try {
                $updated = FileEntry::updateMetadata($metadataId, [
                    'alt_text' => self::nullableText($_POST['alt_text'] ?? '', 255),
                    'caption' => self::nullableText($_POST['caption'] ?? '', 255),
                    'description' => self::nullableText($_POST['description'] ?? '', 4000),
                    'credit' => self::nullableText($_POST['credit'] ?? '', 255),
                    'focal_x' => self::nullablePercent($_POST['focal_x'] ?? null),
                    'focal_y' => self::nullablePercent($_POST['focal_y'] ?? null),
                ]);
            } catch (\Throwable $e) {
                $this->json(['ok' => false, 'error' => 'Не удалось сохранить метаданные файла.'], 500);
            }

            if ($updated === null) {
                $this->json(['ok' => false, 'error' => 'Файл не найден.'], 404);
            }

            $this->json([
                'ok' => true,
                'item' => self::libraryItem($updated),
            ]);
        }

        $accessType = ($_POST['access_type'] ?? 'public') === 'protected' ? 'protected' : 'public';
        if ($accessType === 'protected') {
            RbacGuard::requirePermission('manage_protected_files');
        }

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::error('Выберите файл для загрузки.');
            header('Location: /admin/files');
            exit;
        }

        try {
            // .css/.js — код страницы: разрешаем только супер-администратору,
            // тому же, кто правит поля «произвольный CSS/JS» у страницы.
            Uploader::store($_FILES['file'], $accessType, Auth::id(), null, Auth::isSuperAdmin());
            Flash::success('Файл загружен.');
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
        }

        header('Location: /admin/files');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $file = FileEntry::findById((int) $params['id']);
        if ($file) {
            if (($file['access_type'] ?? '') === 'protected') {
                RbacGuard::requirePermission('manage_protected_files');
            }
            // Переиспользование файлов: не удаляем файл, который ещё где-то
            // используется — иначе сломались бы связанные сущности.
            $publicUrl = FileEntry::publicUrl($file);
            $refs = \App\Core\MediaCleaner::referenceCount($publicUrl);
            // Сама запись files считается владением файла. Для явного удаления
            // из медиабиблиотеки вычитаем её и проверяем внешние ссылки.
            $externalRefs = max(0, $refs - 1);
            if ($externalRefs > 0) {
                Flash::error("Файл используется в {$externalRefs} местах и не может быть удалён. Сначала уберите его из этих записей.");
                header('Location: /admin/files');
                exit;
            }

            $basePath = $file['access_type'] === 'protected'
                ? Config::get('paths.protected_uploads')
                : Config::get('paths.public_uploads');
            $path = rtrim((string) $basePath, '/') . '/' . $file['stored_name'];
            if (is_file($path)) {
                unlink($path);
            }

            // Удаляем сопутствующие WebP-варианты.
            $base = preg_replace('/\.[^.]+$/', '', $path) ?? $path;
            foreach (\App\Core\Media::variantSuffixes() as $suffix) {
                $variant = $base . $suffix;
                if (is_file($variant)) {
                    @unlink($variant);
                }
            }

            FileEntry::delete((int) $file['id']);
            Flash::success('Файл удалён.');
        }

        header('Location: /admin/files');
        exit;
    }

    /** @param array<string, string> $params */
    public function regenerateToken(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_protected_files');
        Csrf::verifyRequest();

        FileEntry::regenerateToken((int) $params['id']);
        Flash::success('Токен доступа обновлён.');
        header('Location: /admin/files');
        exit;
    }

    public function bulkDelete(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $ids = $_POST['ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        if (!is_array($ids) || empty($ids)) {
            Flash::error('Не выбраны файлы для удаления.');
            header('Location: /admin/files');
            exit;
        }

        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($ids as $id) {
            $file = FileEntry::findById((int) $id);
            if (!$file) {
                continue;
            }
            if (($file['access_type'] ?? '') === 'protected'
                && !RbacGuard::can('manage_protected_files')) {
                http_response_code(403);
                View::render('errors/403');
                return;
            }
            $publicUrl = FileEntry::publicUrl($file);
            $externalRefs = max(0, \App\Core\MediaCleaner::referenceCount($publicUrl) - 1);
            if ($externalRefs > 0) {
                $skippedCount++;
                continue;
            }
            $basePath = $file['access_type'] === 'protected'
                ? Config::get('paths.protected_uploads')
                : Config::get('paths.public_uploads');
            $path = rtrim((string) $basePath, '/') . '/' . $file['stored_name'];
            if (is_file($path)) {
                unlink($path);
            }
            $base = preg_replace('/\.[^.]+$/', '', $path) ?? $path;
            foreach (\App\Core\Media::variantSuffixes() as $suffix) {
                $variant = $base . $suffix;
                if (is_file($variant)) {
                    @unlink($variant);
                }
            }
            FileEntry::delete((int) $file['id']);
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            Flash::success("Удалено файлов: {$deletedCount}." . ($skippedCount > 0 ? " Пропущено (используются в записях): {$skippedCount}." : ''));
        } elseif ($skippedCount > 0) {
            Flash::error("Выбранные файлы ({$skippedCount}) используются в контенте и не могут быть удалены.");
        }

        header('Location: /admin/files');
        exit;
    }

    /** @return array<string, mixed> */
    private static function libraryItem(array $file): array
    {
        return [
            'id' => (int) $file['id'],
            'url' => FileEntry::publicUrl($file),
            // Сетку окна выбора рисует JS, и ему нужен отдельный мелкий адрес:
            // подставлять в карточку оригинал — это сотни килобайт на файл.
            'thumb' => \App\Core\Media::thumbUrl(FileEntry::publicUrl($file)),
            'name' => (string) $file['original_name'],
            'mime_type' => (string) ($file['mime_type'] ?? ''),
            // Вес нужен подвалу окна выбора: по имени файла не видно, тянет
            // ли страница эту картинку.
            'size' => (int) ($file['size'] ?? 0),
            'created_at' => (string) ($file['created_at'] ?? ''),
            'alt_text' => (string) ($file['alt_text'] ?? ''),
            'caption' => (string) ($file['caption'] ?? ''),
            'description' => (string) ($file['description'] ?? ''),
            'credit' => (string) ($file['credit'] ?? ''),
            'focal_x' => $file['focal_x'] === null || $file['focal_x'] === '' ? null : (int) $file['focal_x'],
            'focal_y' => $file['focal_y'] === null || $file['focal_y'] === '' ? null : (int) $file['focal_y'],
        ];
    }

    private static function nullableText(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private static function nullablePercent(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
