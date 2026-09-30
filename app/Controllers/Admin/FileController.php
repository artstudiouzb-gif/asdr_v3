<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\InputBag;
use App\Core\MediaMetadataSchema;
use App\Core\MediaUsage;
use App\Core\RbacGuard;
use App\Core\Redirect;
use App\Core\Uploader;
use App\Core\View;
use App\Models\FileEntry;

final class FileController
{
    public function index(): void
    {
        Auth::requireLogin();
        $canManageProtected = RbacGuard::can('manage_protected_files');
        $query = Input::query();
        $date = $query->str('date');
        $filters = [
            'q' => $query->str('q', '', 120),
            'type' => $query->oneOf('type', ['', 'image', 'video', 'document'], ''),
            'date' => preg_match('/^\d{4}-\d{2}$/', $date) === 1 ? $date : '',
            'sort' => $query->oneOf('sort', ['date_desc', 'date_asc', 'size_desc', 'name_asc'], 'date_desc'),
            'per_page' => $query->oneOf('per_page', ['24', '48', '96'], '48'),
            'page' => $query->int('page', 1, 1, 100000),
            'usage' => $query->oneOf('usage', ['unused'], ''),
        ];
        $filters['per_page'] = (int) $filters['per_page'];
        // «Не используется» считается одним проходом по базе, а не запросом:
        // упоминания лежат в тексте и JSON десятков таблиц, SQL-фильтра для
        // них нет. Поэтому отбор идёт в PHP по всему списку, а страница
        // вырезается уже из отобранного.
        $unused = null;
        if ($filters['usage'] === 'unused') {
            $referenced = MediaUsage::referencedKeys();
            $unused = array_values(array_filter(
                FileEntry::filtered($filters, $canManageProtected, 100000, 0),
                static fn (array $file): bool => !isset($referenced[MediaUsage::keyOf($file)])
            ));
        }
        $total = $unused !== null ? count($unused) : FileEntry::filteredCount($filters, $canManageProtected);
        $pages = max(1, (int) ceil($total / $filters['per_page']));
        $filters['page'] = min($filters['page'], $pages);
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $filterParams = array_filter($filters, static fn (mixed $value, string $key): bool => match ($key) {
            'page' => (int) $value > 1,
            'per_page' => (int) $value !== 48,
            default => $value !== '',
        }, ARRAY_FILTER_USE_BOTH);

        View::render('admin/files/index', [
            'items' => $unused !== null
                ? array_slice($unused, $offset, $filters['per_page'])
                : FileEntry::filtered($filters, $canManageProtected, $filters['per_page'], $offset),
            'availableDates' => FileEntry::availableDates(),
            'canManageProtected' => $canManageProtected,
            'filters' => $filters,
            'filterParams' => $filterParams,
            'total' => $total,
            'pages' => $pages,
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
        $get = Input::query();
        $type = $get->oneOf('type', array_values(FileEntry::libraryTypes()), 'image');
        $sort = $get->oneOf('sort', array_values(FileEntry::librarySorts()), 'date_desc');
        $limit = $get->int('limit', 300, 1, 500);
        $offset = $get->int('offset', 0, 0);
        $query = $get->str('q');

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
        $post = Input::post();
        $metadataId = $post->int('metadata_id', 0, 0);
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
                    'alt_text' => self::nullableText($post->str('alt_text'), 255),
                    'caption' => self::nullableText($post->str('caption'), 255),
                    'description' => self::nullableText($post->str('description'), 4000),
                    'credit' => self::nullableText($post->str('credit'), 255),
                    'focal_x' => self::nullablePercent($post->str('focal_x')),
                    'focal_y' => self::nullablePercent($post->str('focal_y')),
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

        $accessType = $post->oneOf('access_type', ['public', 'protected'], 'public');
        if ($accessType === 'protected') {
            RbacGuard::requirePermission('manage_protected_files');
        }

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::error('Выберите файл для загрузки.');
            Redirect::to('/admin/files');
        }

        try {
            // .css/.js — код страницы: разрешаем только супер-администратору,
            // тому же, кто правит поля «произвольный CSS/JS» у страницы.
            Uploader::store($_FILES['file'], $accessType, Auth::id(), null, Auth::isSuperAdmin());
            Flash::success('Файл загружен.');
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
        }

        Redirect::to('/admin/files');
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
            // Используемый файл не удаляем — иначе сломались бы связанные
            // записи. Вместо числа показываем сами места: «используется в
            // трёх местах» не говорит, куда идти.
            $usage = MediaUsage::find($file);
            if ($usage !== []) {
                Flash::error('Файл нельзя удалить: он стоит в записях сайта. Уберите его из мест ниже и повторите удаление.');
                Redirect::to('/admin/files/' . (int) $file['id'] . '/usage');
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

        Redirect::to('/admin/files');
    }

    /**
     * Где используется файл: список записей со ссылками на их формы.
     *
     * @param array<string, string> $params
     */
    public function usage(array $params): void
    {
        Auth::requireLogin();

        $file = FileEntry::findById((int) $params['id']);
        if (!$file) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        if (($file['access_type'] ?? '') === 'protected') {
            RbacGuard::requirePermission('manage_protected_files');
        }

        View::render('admin/files/usage', [
            'file' => $file,
            'usage' => MediaUsage::find($file),
        ]);
    }

    /** @param array<string, string> $params */
    public function regenerateToken(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_protected_files');
        Csrf::verifyRequest();

        FileEntry::regenerateToken((int) $params['id']);
        Flash::success('Токен доступа обновлён.');
        Redirect::to('/admin/files');
    }

    public function bulkDelete(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        // Список приходит полями ids[] или одной строкой JSON (массовое
        // удаление из сетки медиатеки собирает его скриптом).
        $post = Input::post();
        $ids = $post->ids('ids');
        if ($ids === []) {
            $decoded = json_decode($post->str('ids'), true);
            $ids = is_array($decoded) ? (new InputBag(['ids' => $decoded]))->ids('ids') : [];
        }
        if ($ids === []) {
            Flash::error('Не выбраны файлы для удаления.');
            Redirect::to('/admin/files');
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
            if (MediaUsage::find($file) !== []) {
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
            Flash::error("Выбранные файлы ({$skippedCount}) используются в контенте и не могут быть удалены. Где именно — ссылка «Где используется» у файла.");
        }

        Redirect::to('/admin/files');
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
