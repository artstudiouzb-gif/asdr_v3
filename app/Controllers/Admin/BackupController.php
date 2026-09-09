<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Backup;
use App\Core\BackupRestore;
use App\Core\Csrf;
use App\Core\Flash;

final class BackupController
{
    public function create(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        if ((string) ($_POST['backup_action'] ?? '') === 'restore') {
            $this->restore();
            return;
        }

        try {
            $path = Backup::create();
        } catch (\Throwable $e) {
            Flash::error('Не удалось создать бэкап: ' . $e->getMessage());
            header('Location: /admin/settings');
            exit;
        }

        // Отдаём архив на скачивание и удаляем его с диска после отправки,
        // чтобы бэкапы не копились в storage без контроля.
        $filename = basename($path);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        @unlink($path);
        @unlink(Backup::checksumPath($path));
        exit;
    }

    private function restore(): void
    {
        if (($_POST['restore_ack'] ?? '') !== '1') {
            Flash::error('Подтвердите, что понимаете последствия восстановления.');
            header('Location: /admin/settings#backup-section');
            exit;
        }

        $upload = $_FILES['backup_file'] ?? null;
        if (!is_array($upload)) {
            Flash::error('Выберите ZIP-файл резервной копии.');
            header('Location: /admin/settings#backup-section');
            exit;
        }

        try {
            $result = BackupRestore::restoreUploaded(
                $upload,
                (string) ($_POST['restore_confirm_code'] ?? '')
            );
            Flash::success(sprintf(
                'Сайт восстановлен. Таблиц: %d, файлов: %d. Страховочная копия текущего состояния сохранена на сервере: %s',
                (int) $result['restored_tables'],
                (int) $result['restored_files'],
                (string) $result['safety_backup']
            ));
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        header('Location: /admin/settings#backup-section');
        exit;
    }
}
