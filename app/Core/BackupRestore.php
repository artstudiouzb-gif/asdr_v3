<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Безопасное восстановление рабочего сайта из ZIP, созданного Backup::create().
 *
 * Перед любыми изменениями создаётся страховочный бэкап текущего состояния.
 * При ошибке после начала восстановления выполняется best-effort откат БД из
 * этой страховочной копии, а каталоги uploads возвращаются на прежние места.
 */
final class BackupRestore
{
    private const CONFIRM_CODE = 'RESTORE';
    private const MAX_ARCHIVE_BYTES = 2_147_483_648; // 2 GiB hard safety limit.

    /**
     * @param array<string,mixed> $upload Элемент $_FILES['backup_file'].
     * @return array{ok:bool,safety_backup:string,restored_tables:int,restored_files:int,messages:string[]}
     */
    public static function restoreUploaded(array $upload, string $confirmCode): array
    {
        if (strtoupper(trim($confirmCode)) !== self::CONFIRM_CODE) {
            throw new \RuntimeException('Неверный код подтверждения. Введите RESTORE.');
        }

        self::assertUpload($upload);
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('Расширение PHP zip не установлено.');
        }

        $incoming = self::storeIncomingArchive((string) $upload['tmp_name'], (string) $upload['name']);
        $stageRoot = dirname(__DIR__, 2) . '/storage/restore/' . bin2hex(random_bytes(8));
        $rollbackStage = dirname(__DIR__, 2) . '/storage/restore/rollback-' . bin2hex(random_bytes(8));
        $safetyPath = '';
        $guardCreated = false;
        $previousMaintenance = null;
        $swaps = [];
        $restoreStarted = false;
        $rollbackMessage = '';

        try {
            // Проверяем ZIP до создания страховочной копии: явно чужой/битый
            // файл не должен запускать дорогой дамп рабочего сайта.
            self::assertCompatibleArchive($incoming);

            // Страховочный бэкап создаётся обычным согласованным способом.
            // maintenance=false важен: тогда в дамп попадёт фактическое текущее
            // значение maintenance_mode, а не временная единица.
            $safetyPath = Backup::create(false);

            self::acquireRestoreGuard();
            $guardCreated = true;

            $previousMaintenance = Setting::get('maintenance_mode', '0');
            Setting::set('maintenance_mode', '1');

            $restoreStarted = true;
            $report = Backup::restore($incoming, self::databaseConfig(), $stageRoot);

            // Backup::restore разворачивает файлы в stage/uploads/*, поэтому до
            // финального переключения рабочие uploads остаются нетронутыми.
            $stagePublic = $stageRoot . '/uploads/public';
            $stageProtected = $stageRoot . '/uploads/protected';
            if (!is_dir($stagePublic) && !mkdir($stagePublic, 0750, true) && !is_dir($stagePublic)) {
                throw new \RuntimeException('Не удалось подготовить публичные файлы из резервной копии.');
            }
            if (!is_dir($stageProtected) && !mkdir($stageProtected, 0750, true) && !is_dir($stageProtected)) {
                throw new \RuntimeException('Не удалось подготовить защищённые файлы из резервной копии.');
            }

            $token = date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $swaps[] = self::swapDirectory($stagePublic, (string) Config::get('paths.public_uploads'), $token);
            $swaps[] = self::swapDirectory($stageProtected, (string) Config::get('paths.protected_uploads'), $token);

            // После обеих успешных замен старые каталоги больше не нужны.
            foreach ($swaps as $swap) {
                if (!empty($swap['old']) && is_dir((string) $swap['old'])) {
                    self::removeTree((string) $swap['old']);
                }
            }

            Logger::security('Рабочий сайт восстановлен из резервной копии', [
                'archive' => basename($incoming),
                'safety_backup' => basename($safetyPath),
                'tables' => (int) ($report['tables'] ?? 0),
                'files' => (int) ($report['files'] ?? 0),
                'admin_id' => Auth::id(),
            ]);

            return [
                'ok' => true,
                'safety_backup' => basename($safetyPath),
                'restored_tables' => (int) ($report['tables'] ?? 0),
                'restored_files' => (int) ($report['files'] ?? 0),
                'messages' => array_values(array_map('strval', (array) ($report['messages'] ?? []))),
            ];
        } catch (\Throwable $error) {
            // Если каталог уже успели переключить — возвращаем предыдущую
            // версию до попытки отката БД.
            foreach (array_reverse($swaps) as $swap) {
                try {
                    self::rollbackSwap($swap);
                } catch (\Throwable $swapError) {
                    Logger::error(
                        'Не удалось откатить каталог uploads после ошибки restore',
                        'error',
                        ['message' => $swapError->getMessage()]
                    );
                }
            }

            if ($restoreStarted && $safetyPath !== '' && is_file($safetyPath)) {
                try {
                    Backup::restore($safetyPath, self::databaseConfig(), $rollbackStage);
                    $rollbackMessage = ' База данных автоматически возвращена к состоянию до восстановления.';
                } catch (\Throwable $rollbackError) {
                    $rollbackMessage = ' Автоматический откат БД не удался; страховочная копия сохранена на сервере: '
                        . basename($safetyPath) . '.';
                    Logger::critical('Автоматический откат после неудачного восстановления не выполнен', [
                        'restore_error' => $error->getMessage(),
                        'rollback_error' => $rollbackError->getMessage(),
                        'safety_backup' => basename($safetyPath),
                    ]);
                }
            } elseif ($previousMaintenance !== null) {
                try {
                    Setting::set('maintenance_mode', (string) $previousMaintenance);
                } catch (\Throwable) {
                    // Если БД недоступна, исходная ошибка важнее.
                }
            }

            throw new \RuntimeException('Восстановление прервано: ' . $error->getMessage() . $rollbackMessage, 0, $error);
        } finally {
            if ($guardCreated) {
                self::releaseRestoreGuard();
            }
            self::removeTree($stageRoot);
            self::removeTree($rollbackStage);
            @unlink($incoming);
            @unlink(Backup::checksumPath($incoming));
        }
    }

    /** @param array<string,mixed> $upload */
    private static function assertUpload(array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ZIP превышает разрешённый размер загрузки на сервере.',
                UPLOAD_ERR_PARTIAL => 'ZIP был загружен не полностью.',
                UPLOAD_ERR_NO_FILE => 'Выберите ZIP-файл резервной копии.',
                default => 'PHP вернул ошибку загрузки файла (код ' . $error . ').',
            };
            throw new \RuntimeException($message);
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        $name = (string) ($upload['name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Загруженный файл не прошёл проверку PHP upload.');
        }
        if ($size <= 0 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new \RuntimeException('Недопустимый размер ZIP-файла.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \RuntimeException('Для восстановления нужен ZIP-файл резервной копии.');
        }
    }

    private static function storeIncomingArchive(string $tmpName, string $originalName): string
    {
        $dir = Backup::backupDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось открыть каталог резервных копий.');
        }
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName)) ?: 'backup.zip';
        $path = $dir . '/restore-incoming-' . bin2hex(random_bytes(6)) . '-' . $safeBase;
        if (!move_uploaded_file($tmpName, $path)) {
            throw new \RuntimeException('Не удалось переместить ZIP в защищённый каталог восстановления.');
        }
        @chmod($path, 0640);

        return $path;
    }

    private static function assertCompatibleArchive(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Не удалось открыть ZIP. Архив повреждён или имеет неподдерживаемый формат.');
        }
        try {
            $databaseIndex = $zip->locateName('database.sql', \ZipArchive::FL_NOCASE);
            $manifestIndex = $zip->locateName('manifest.txt', \ZipArchive::FL_NOCASE);
            if ($databaseIndex === false || $manifestIndex === false) {
                throw new \RuntimeException('Это не резервная копия ASDR CMS: отсутствует database.sql или manifest.txt.');
            }
            $manifest = (string) $zip->getFromIndex((int) $manifestIndex, 4096);
            if (!str_starts_with($manifest, 'ASDR CMS backup')) {
                throw new \RuntimeException('Манифест ZIP не соответствует формату резервной копии ASDR CMS.');
            }

            // Поверхностная защита до более строгой проверки внутри Backup::restore.
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
                $normalized = str_replace('\\', '/', $name);
                if ($normalized === ''
                    || str_contains($normalized, "\0")
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                    || in_array('..', explode('/', $normalized), true)) {
                    throw new \RuntimeException('ZIP содержит небезопасный путь: ' . $name);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array{host:string,port:string,database:string,username:string,password:string} */
    private static function databaseConfig(): array
    {
        return [
            'host' => (string) Config::get('db.host', '127.0.0.1'),
            'port' => (string) Config::get('db.port', '3306'),
            'database' => (string) Config::get('db.database', ''),
            'username' => (string) Config::get('db.username', ''),
            'password' => (string) Config::get('db.password', ''),
        ];
    }

    private static function restoreGuardPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/backup_write_guard.lock';
    }

    private static function acquireRestoreGuard(): void
    {
        if (Backup::isWriteGuardActive()) {
            throw new \RuntimeException('Сейчас выполняется другая операция резервного копирования.');
        }
        $path = self::restoreGuardPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать системную блокировку восстановления.');
        }
        $payload = date('c') . ' restore pid=' . (string) getmypid() . PHP_EOL;
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось установить блокировку восстановления.');
        }
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new \RuntimeException('Не удалось записать блокировку восстановления.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error;
        }
        fclose($handle);
    }

    private static function releaseRestoreGuard(): void
    {
        @unlink(self::restoreGuardPath());
    }

    /**
     * @return array{source:string,target:string,old:?string}
     */
    private static function swapDirectory(string $source, string $target, string $token): array
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new \RuntimeException('Недоступен родительский каталог: ' . $parent);
        }
        if (!is_dir($source)) {
            throw new \RuntimeException('Не найден подготовленный каталог восстановления: ' . $source);
        }

        $old = null;
        if (file_exists($target)) {
            $old = $target . '.restore-old-' . $token;
            if (file_exists($old)) {
                self::removeTree($old);
            }
            if (!@rename($target, $old)) {
                throw new \RuntimeException('Не удалось временно отложить текущий каталог: ' . $target);
            }
        }

        try {
            if (!@rename($source, $target)) {
                // На редких конфигурациях public и storage могут быть на разных
                // файловых системах: тогда используем проверяемое копирование.
                self::copyTree($source, $target);
                self::removeTree($source);
            }
        } catch (\Throwable $error) {
            self::removeTree($target);
            if ($old !== null && is_dir($old)) {
                @rename($old, $target);
            }
            throw $error;
        }

        return ['source' => $source, 'target' => $target, 'old' => $old];
    }

    /** @param array{source:string,target:string,old:?string} $swap */
    private static function rollbackSwap(array $swap): void
    {
        self::removeTree($swap['target']);
        if ($swap['old'] !== null && is_dir($swap['old'])) {
            if (!@rename($swap['old'], $swap['target'])) {
                self::copyTree($swap['old'], $swap['target']);
                self::removeTree($swap['old']);
            }
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
            throw new \RuntimeException('Не удалось создать каталог: ' . $target);
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
                    throw new \RuntimeException('Не удалось создать каталог: ' . $destination);
                }
                continue;
            }
            if (!@copy($item->getPathname(), $destination)) {
                throw new \RuntimeException('Не удалось восстановить файл: ' . $relative);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
