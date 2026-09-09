<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Репетиция восстановления: разворачивает свежий бэкап в отдельную базу и
 * проверяет, что развернулось именно то, что нужно.
 *
 * Копия, которую ни разу не восстанавливали, бэкапом не является. Механизм
 * восстановления в проекте был и раньше (`database/restore.php`), но требовал
 * трёх аргументов руками — на cron такое не повесить, и `release_check`
 * годами повторял «восстановление ни разу не проверялось».
 *
 * Боевую базу и боевые загрузки репетиция не трогает **никогда**: она работает
 * в отдельной базе, имя которой обязано отличаться от рабочей, и в своём
 * временном каталоге. Проверка этого — первое, что делает `run()`.
 */
final class RestoreDrill
{
    /** Таблицы, без которых восстановленная копия бесполезна. */
    private const CORE_TABLES = ['users', 'pages', 'news', 'settings', 'migrations'];

    /**
     * @return array{ok: bool, archive: string, database: string, tables: int, files: int, messages: list<string>}
     */
    public static function run(string $targetDb = '', string $archive = ''): array
    {
        $result = ['ok' => false, 'archive' => '', 'database' => $targetDb, 'tables' => 0, 'files' => 0, 'messages' => []];

        $liveDb = (string) Config::get('db.database', '');
        if ($targetDb === '') {
            $targetDb = $liveDb . '_restore_check';
            $result['database'] = $targetDb;
        }
        // Сначала разбор присланного, потом безопасность: имя уходит в
        // CREATE DATABASE и DROP DATABASE, где параметр не подставить, поэтому
        // набор символов ограничен жёстко.
        if (preg_match('/^[A-Za-z0-9_]+$/', $targetDb) !== 1) {
            $result['messages'][] = 'Недопустимое имя базы для репетиции: ' . $targetDb;

            return $result;
        }
        // Совпадение имён означало бы, что репетиция затрёт боевые данные
        // дампом. Это не «предупреждение», а отказ работать. Неизвестное имя
        // рабочей базы — тот же отказ: доказать, что мы не на ней, нечем.
        if ($liveDb === '') {
            $result['messages'][] = 'Имя рабочей базы неизвестно — репетиция не может доказать, что не тронет её.';

            return $result;
        }
        if ($targetDb === $liveDb) {
            $result['messages'][] = 'База для репетиции обязана отличаться от рабочей.';

            return $result;
        }

        $archive = $archive !== '' ? $archive : self::latestArchive();
        if ($archive === '') {
            $result['messages'][] = 'В storage/backups нет ни одного архива — репетировать нечего.';

            return $result;
        }
        $result['archive'] = basename($archive);

        // Контрольная сумма проверяется до всего остального: битый архив это
        // самый частый и самый тихий отказ бэкапа.
        if (Backup::storedChecksum($archive) !== null && !Backup::verify($archive)) {
            $result['messages'][] = 'Контрольная сумма архива не совпала — архив повреждён.';

            return $result;
        }

        $db = [
            'host' => (string) Config::get('db.host', '127.0.0.1'),
            'port' => (string) Config::get('db.port', '3306'),
            'database' => $targetDb,
            'username' => (string) Config::get('db.username', 'root'),
            'password' => (string) Config::get('db.password', ''),
        ];

        $created = self::ensureDatabase($db, $result);
        if ($created === null) {
            return $result;
        }

        $filesDir = sys_get_temp_dir() . '/artstudio_restore_drill_' . bin2hex(random_bytes(6));

        try {
            $report = Backup::restore($archive, $db, $filesDir);
            $result['tables'] = (int) $report['tables'];
            $result['files'] = (int) $report['files'];
            foreach ($report['messages'] as $message) {
                $result['messages'][] = (string) $message;
            }
            if (empty($report['ok'])) {
                return $result;
            }

            $result['ok'] = self::verifyContent($db, $result);
        } catch (\Throwable $e) {
            $result['messages'][] = 'Восстановление прервано: ' . $e->getMessage();
        } finally {
            self::cleanup($db, $created, $filesDir);
        }

        return $result;
    }

    /** Самый свежий архив в каталоге бэкапов. */
    private static function latestArchive(): string
    {
        $files = glob(Backup::backupDir() . '/*.zip') ?: [];
        if ($files === []) {
            return '';
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * @param array<string, string> $db
     * @param array{messages: list<string>, ...} $result
     * @return bool|null true — базу создали мы, false — она уже была, null — нельзя
     */
    private static function ensureDatabase(array $db, array &$result): ?bool
    {
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4';
        try {
            $pdo = new \PDO($dsn, $db['username'], $db['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            $result['messages'][] = 'Нет соединения с сервером БД: ' . $e->getMessage();

            return null;
        }

        $exists = (bool) $pdo
            ->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($db['database']))
            ->fetchColumn();
        if ($exists) {
            $result['messages'][] = 'База ' . $db['database'] . ' уже существует — используем её.';

            return false;
        }

        // На shared-хостинге у пользователя часто нет права CREATE DATABASE.
        // Это не поломка репетиции, а причина, по которой её надо настроить
        // руками, — и сказать об этом надо прямо.
        try {
            $pdo->exec('CREATE DATABASE `' . $db['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Throwable $e) {
            $result['messages'][] = 'Не удалось создать базу ' . $db['database']
                . ' — создайте её вручную и повторите: ' . $e->getMessage();

            return null;
        }
        $result['messages'][] = 'База ' . $db['database'] . ' создана для репетиции.';

        return true;
    }

    /**
     * Развернулось ли то, что нужно. Без этой проверки «успехом» считался бы и
     * пустой дамп: таблицы созданы, строк нет.
     *
     * @param array<string, string> $db
     * @param array{messages: list<string>, ...} $result
     */
    private static function verifyContent(array $db, array &$result): bool
    {
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['database'] . ';charset=utf8mb4';
        $pdo = new \PDO($dsn, $db['username'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $missing = [];
        foreach (self::CORE_TABLES as $table) {
            $found = (bool) $pdo
                ->query('SHOW TABLES LIKE ' . $pdo->quote($table))
                ->fetchColumn();
            if (!$found) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            $result['messages'][] = 'В восстановленной копии нет таблиц: ' . implode(', ', $missing);

            return false;
        }

        // Учётных записей ноль — значит в копию не попали данные, и войти в
        // такую «восстановленную» систему будет нечем.
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($users === 0) {
            $result['messages'][] = 'В восстановленной копии нет ни одной учётной записи.';

            return false;
        }
        $result['messages'][] = 'Проверено: таблицы на месте, учётных записей — ' . $users . '.';

        return true;
    }

    /** @param array<string, string> $db */
    private static function cleanup(array $db, bool $created, string $filesDir): void
    {
        if ($created) {
            try {
                $pdo = new \PDO(
                    'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4',
                    $db['username'],
                    $db['password'],
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                $pdo->exec('DROP DATABASE `' . $db['database'] . '`');
            } catch (\Throwable) {
                // Не удалось убрать за собой — репетиция от этого не портится,
                // а база с суффиксом _restore_check видна в списке и так.
            }
        }

        self::removeDir($filesDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** Отметка о репетиции — по ней release_check.php считает свежесть. */
    public static function markerPath(): string
    {
        return Backup::backupDir() . '/.last_restore_check';
    }

    /** @param array{ok: bool, archive: string, tables: int, files: int, ...} $result */
    public static function writeMarker(array $result): void
    {
        @file_put_contents(self::markerPath(), json_encode([
            'checked_at' => date('c'),
            'archive' => $result['archive'],
            'tables' => $result['tables'],
            'files' => $result['files'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
    }
}
