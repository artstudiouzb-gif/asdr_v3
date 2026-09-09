<?php

declare(strict_types=1);

namespace App\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Приводит права публичных загрузок к тем, при которых их отдаёт веб-сервер.
 *
 * Файл, загруженный через форму, приходит upload-файлом и получает права по
 * umask. Файл, который PHP собрал сам — перенос со старого сайта, кадр-превью
 * с YouTube, сборка чанковой загрузки — наследует права временного файла, а
 * `tempnam()` создаёт его с 0600. Статику отдаёт веб-сервер, и такой файл он
 * прочитать не может: картинки нет, хотя запись в медиатеке есть и файл на
 * диске лежит.
 *
 * Самозалечивание в `Media::servable()` чинит то, что попалось на глаза при
 * отрисовке, — но страницы кэшируются, и до битой обложки очередь может не
 * дойти никогда. Поэтому проход по каталогу нужен отдельно.
 */
final class MediaPermissions
{
    public const MAX_ENTRIES = 60000;
    private const FILE_MODE = 0644;
    private const DIR_MODE = 0755;

    /**
     * Отсортированный список всего, что лежит в каталоге. Сортировка — условие
     * работы пакетами: порядок обхода задаёт файловая система, и без неё номер,
     * на котором остановился пакет, указывал бы в следующем запросе на другую
     * запись.
     *
     * @return list<string>
     */
    public static function entries(string $directory): array
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '' || !is_dir($directory)) {
            throw new \InvalidArgumentException('Каталог публичных загрузок не найден: ' . $directory);
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }
            $paths[] = $item->getPathname();
            if (count($paths) >= self::MAX_ENTRIES) {
                break;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return array{scanned: int, fixed: int, planned: int, empty: int, failed: int, cursor: int, total: int}
     */
    public static function run(
        string $directory,
        bool $dryRun = false,
        int $offset = 0,
        float $timeBudget = 0.0
    ): array {
        $paths = self::entries($directory);
        $total = count($paths);
        $index = max(0, min($offset, $total));

        $result = [
            'scanned' => 0,
            'fixed' => 0,
            'planned' => 0,
            'empty' => 0,
            'failed' => 0,
            'cursor' => $index,
            'total' => $total,
        ];

        // При старте проверяем сам корневой каталог загрузок (0755)
        if ($offset === 0 && is_dir($directory)) {
            $rootPerms = @fileperms($directory);
            if ($rootPerms !== false && ($rootPerms & 0777) !== self::DIR_MODE) {
                if ($dryRun) {
                    $result['planned']++;
                } elseif (@chmod($directory, self::DIR_MODE)) {
                    $result['fixed']++;
                } else {
                    $result['failed']++;
                }
            }
        }

        $startedAt = microtime(true);
        for (; $index < $total; $index++) {
            // Бюджет — после первой записи: иначе пакет вернулся бы, не сдвинув
            // курсор, и проход стоял бы на месте, показывая движение.
            if ($timeBudget > 0.0 && $result['scanned'] > 0 && (microtime(true) - $startedAt) >= $timeBudget) {
                break;
            }

            $path = $paths[$index];
            $perms = @fileperms($path);
            if ($perms === false) {
                $result['failed']++;
                continue;
            }
            $mode = $perms & 0777;
            $isDir = is_dir($path);
            $want = $isDir ? self::DIR_MODE : self::FILE_MODE;

            if (!$isDir) {
                $result['scanned']++;
                // Пустой файл правами не чинится: не удалась сама загрузка.
                // Считаем отдельно, чтобы отчёт не выдавал их за исправленные.
                if (@filesize($path) === 0 && !str_starts_with(basename($path), '.')) {
                    $result['empty']++;
                }
            }

            if ($mode === $want) {
                continue;
            }
            if ($dryRun) {
                $result['planned']++;
                continue;
            }
            if (@chmod($path, $want)) {
                $result['fixed']++;
            } else {
                $result['failed']++;
            }
        }
        $result['cursor'] = $index;

        return $result;
    }
}
