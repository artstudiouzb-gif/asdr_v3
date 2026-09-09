<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\RbacGuard;
use App\Core\Uploader;
use App\Models\FileEntry;

/**
 * Приём файлов по частям (chunked upload) через нативный JS File API. Позволяет
 * загружать большие файлы (видео, PDF-презентации) в обход ограничений
 * upload_max_filesize / post_max_size на дешёвых хостингах: каждый чанк —
 * небольшой отдельный запрос, сервер дописывает их в один файл и собирает.
 */
final class ChunkedUploadController
{
    private const MAX_ASSEMBLED_BYTES = 200 * 1024 * 1024; // 200 МБ
    private const STALE_AFTER_SECONDS = 24 * 60 * 60;

    public function chunk(): void
    {
        Auth::requireLogin();

        header('Content-Type: application/json; charset=UTF-8');

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'CSRF token mismatch'], 419);
        }

        // Ограничение частоты чанков: до 600 чанков / 5 минут на пользователя
        // (хватает на несколько параллельных 200-МБ загрузок, но отсекает
        // злоупотребление записью на диск).
        if (!RateLimiter::throttle('chunk', 'user:' . (int) Auth::id(), 600, 5)) {
            $this->json(['ok' => false, 'error' => 'Слишком много запросов, повторите позже.'], 429);
        }

        $uploadId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['upload_id'] ?? '')));
        $index = (int) ($_POST['index'] ?? -1);
        $total = (int) ($_POST['total'] ?? 0);
        $name = (string) ($_POST['name'] ?? '');
        $accessType = ($_POST['access_type'] ?? 'public') === 'protected' ? 'protected' : 'public';
        if ($accessType === 'protected' && !RbacGuard::can('manage_protected_files')) {
            $this->json(['ok' => false, 'error' => 'Недостаточно прав для защищённого файла'], 403);
        }

        if (strlen($uploadId) !== 32 || $index < 0 || $total < 1 || $index >= $total || $name === '') {
            $this->json(['ok' => false, 'error' => 'Некорректные параметры чанка'], 400);
        }

        if (empty($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            $this->json(['ok' => false, 'error' => 'Чанк не получен'], 400);
        }

        // Изолируем временные файлы по пользователю: клиентский upload_id не
        // должен позволять одной сессии пересечься с загрузкой другой.
        $rootDir = APP_ROOT . '/storage/cache/chunks';
        $this->cleanupStaleUploads($rootDir);
        $dir = $rootDir . '/user-' . (int) Auth::id();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->json(['ok' => false, 'error' => 'Нет каталога для сборки'], 500);
        }
        $partPath = $dir . '/' . $uploadId . '.part';
        $metaPath = $dir . '/' . $uploadId . '.json';
        $lockPath = $dir . '/' . $uploadId . '.lock';

        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            $this->json(['ok' => false, 'error' => 'Не удалось заблокировать загрузку'], 503);
        }

        // Сервер хранит ожидаемый индекс и не допускает пропуски/повторы.
        $meta = is_file($metaPath)
            ? json_decode((string) file_get_contents($metaPath), true)
            : null;
        if ($index === 0) {
            @unlink($partPath);
            $meta = ['next' => 0, 'total' => $total, 'name' => $name, 'access' => $accessType];
        }
        if (!is_array($meta)
            || (int) ($meta['next'] ?? -1) !== $index
            || (int) ($meta['total'] ?? 0) !== $total
            || (string) ($meta['name'] ?? '') !== $name
            || (string) ($meta['access'] ?? '') !== $accessType) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->json(['ok' => false, 'error' => 'Нарушен порядок или состав чанков'], 409);
        }

        // Дописываем чанк в общий файл.
        $in = fopen($_FILES['chunk']['tmp_name'], 'rb');
        $out = fopen($partPath, 'ab');
        if ($in === false || $out === false) {
            if (is_resource($in)) { fclose($in); }
            if (is_resource($out)) { fclose($out); }
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->json(['ok' => false, 'error' => 'Ошибка записи чанка'], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $meta['next'] = $index + 1;
        file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);

        // Ограничение суммарного размера на лету (защита от переполнения).
        if (filesize($partPath) > self::MAX_ASSEMBLED_BYTES) {
            @unlink($partPath);
            @unlink($metaPath);
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->json(['ok' => false, 'error' => 'Файл превышает максимальный размер 200 МБ'], 413);
        }

        // Не последний чанк — подтверждаем приём и ждём следующий.
        if ($index < $total - 1) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->json(['ok' => true, 'received' => $index]);
        }

        // Последний чанк — финализируем.
        try {
            $file = Uploader::storeFromPath(
                $partPath,
                $name,
                (int) filesize($partPath),
                $accessType,
                Auth::id(),
                false,
                self::MAX_ASSEMBLED_BYTES
            );
        } catch (\Throwable $e) {
            @unlink($partPath);
            @unlink($metaPath);
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        // storeFromPath переименовывает part-файл в итоговый; подчистим, если остался.
        if (is_file($partPath)) {
            @unlink($partPath);
        }
        @unlink($metaPath);
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($lockPath);

        $this->json([
            'ok' => true,
            'done' => true,
            'file_id' => (int) $file['id'],
            'name' => $file['original_name'],
            'url' => $accessType === 'public' ? FileEntry::publicUrl($file) : null,
            'mime_type' => (string) ($file['mime_type'] ?? ''),
        ]);
    }

    /** Удаляет брошенные загрузки старше суток, не трогая активные lock-файлы. */
    private function cleanupStaleUploads(string $rootDir): void
    {
        if (!is_dir($rootDir)) {
            return;
        }

        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach (glob($rootDir . '/user-*/*.lock') ?: [] as $lockPath) {
            if ((int) @filemtime($lockPath) >= $cutoff) {
                continue;
            }
            $lock = @fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) { fclose($lock); }
                continue;
            }

            $base = substr($lockPath, 0, -5);
            foreach ([$base . '.part', $base . '.json'] as $stalePath) {
                if (is_file($stalePath) && (int) @filemtime($stalePath) < $cutoff) {
                    @unlink($stalePath);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    private function json(array $payload, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
