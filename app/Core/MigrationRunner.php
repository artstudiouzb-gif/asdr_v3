<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Единый исполнитель SQL-миграций для CLI, веб-установщика и тестов.
 *
 * schema.sql создаёт базовую структуру и таблицу migrations. После импорта
 * свежей схемы здесь применяются все файлы, которые ещё не отмечены в
 * migrations (в нормальном fresh-install это post-schema миграции).
 */
final class MigrationRunner
{
    /** @return list<string> */
    public static function pending(PDO $pdo, string $migrationsDir): array
    {
        self::ensureMigrationsTable($pdo);

        $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = array_flip(array_map('strval', $applied));

        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !isset($applied[basename($file)])
        ));
    }

    /**
     * Сколько миграций ещё не применено — для бейджа в шапке панели.
     *
     * Отличается от pending() тем, что **ничего не создаёт**: шапка рисуется на
     * каждой странице админки, и через pending() туда попадал
     * `CREATE TABLE IF NOT EXISTS migrations`, то есть DDL при отрисовке
     * страницы. На хостинге, где у пользователя БД нет права CREATE, панель
     * падала бы на каждой странице из-за счётчика в углу.
     *
     * Нет таблицы — значит не применено ничего: возвращаем число файлов.
     */
    public static function pendingCount(PDO $pdo, string $migrationsDir): int
    {
        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        if ($files === []) {
            return 0;
        }

        try {
            $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            // Таблицы ещё нет (свежая установка) — создавать её на чтении
            // нельзя, а честный ответ здесь «не применено ничего».
            Logger::swallowed('MigrationRunner: таблица migrations недоступна для чтения', $e);

            return count($files);
        }

        $applied = array_flip(array_map('strval', $applied));

        return count(array_filter(
            $files,
            static fn (string $file): bool => !isset($applied[basename($file)])
        ));
    }

    /**
     * @return array{
     *     total: int,
     *     applied_count: int,
     *     pending_count: int,
     *     pending: list<array{name: string, path: string, size: int}>,
     *     applied: list<array{name: string, applied_at: string}>,
     *     all: list<array{name: string, path: string, is_applied: bool, applied_at: ?string, size: int}>
     * }
     */
    public static function status(PDO $pdo, string $migrationsDir): array
    {
        self::ensureMigrationsTable($pdo);

        $appliedRows = [];
        try {
            $appliedRows = $pdo->query('SELECT filename, applied_at FROM migrations ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $appliedRows = [];
        }

        $appliedMap = [];
        foreach ($appliedRows as $row) {
            $appliedMap[(string) $row['filename']] = (string) ($row['applied_at'] ?? '');
        }

        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $all = [];
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            $isApplied = isset($appliedMap[$name]);
            $size = (int) @filesize($file);
            $item = [
                'name' => $name,
                'path' => $file,
                'is_applied' => $isApplied,
                'applied_at' => $appliedMap[$name] ?? null,
                'size' => $size,
            ];
            $all[] = $item;
            if (!$isApplied) {
                $pending[] = [
                    'name' => $name,
                    'path' => $file,
                    'size' => $size,
                ];
            }
        }

        $appliedList = [];
        foreach ($appliedRows as $row) {
            $appliedList[] = [
                'name' => (string) $row['filename'],
                'applied_at' => (string) ($row['applied_at'] ?? ''),
            ];
        }

        return [
            'total' => count($files),
            'applied_count' => count($appliedMap),
            'pending_count' => count($pending),
            'pending' => $pending,
            'applied' => $appliedList,
            'all' => $all,
        ];
    }

    /**
     * @param null|callable(string,string):void $reporter (event, filename)
     * @return list<string> имена применённых миграций
     */
    public static function applyPending(PDO $pdo, string $migrationsDir, ?callable $reporter = null): array
    {
        self::ensureMigrationsTable($pdo);

        $dbStmt = $pdo->query('SELECT DATABASE()');
        $database = (string) ($dbStmt ? $dbStmt->fetchColumn() : '');
        $dbStmt?->closeCursor();

        $lockName = 'asdr_cms_migrations_' . substr(hash('sha256', $database), 0, 24);
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $lockStmt->execute([':name' => $lockName]);
        $lockAcquired = (int) $lockStmt->fetchColumn() === 1;
        $lockStmt->closeCursor();

        if (!$lockAcquired) {
            throw new RuntimeException('Не удалось получить блокировку миграций. Другой процесс ещё работает.');
        }

        try {
            // Pending вычисляем уже после получения блокировки: второй процесс
            // мог успеть применить миграции, пока мы ждали GET_LOCK().
            $pending = self::pending($pdo, $migrationsDir);
            $appliedNow = [];

            $record = $pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');

            foreach ($pending as $file) {
                $name = basename($file);
                $sql = (string) file_get_contents($file);
                if (trim($sql) === '') {
                    continue;
                }

                if ($reporter !== null) {
                    $reporter('start', $name);
                }

                // SQL-файлы миграций являются доверенной частью релиза.
                // DDL MySQL выполняет неявный COMMIT, поэтому имя фиксируем
                // только после успешного выполнения всего файла.
                $pdo->exec($sql);
                $record->execute([':filename' => $name]);
                $record->closeCursor();
                $appliedNow[] = $name;

                if ($reporter !== null) {
                    $reporter('done', $name);
                }
            }

            return $appliedNow;
        } finally {
            $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $releaseStmt->execute([':name' => $lockName]);
            $releaseStmt->closeCursor();
        }
    }

    private static function ensureMigrationsTable(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename VARCHAR(255) NOT NULL UNIQUE,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
