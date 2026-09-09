<?php

declare(strict_types=1);

namespace App\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Пакетно достраивает WebP-варианты для старых публичных загрузок. */
final class ImageBatchOptimizer
{
    /** Предел обхода — тот же, что у проверки медиатеки. */
    public const MAX_FILES = 40000;

    /**
     * Список кандидатов на обработку — отсортированный, и это условие работы
     * пакетами. Порядок обхода каталога задаёт файловая система, то есть между
     * двумя запросами он может отличаться; тогда номер, на котором остановился
     * прошлый пакет, указывал бы в следующем на другой файл. Сортировка делает
     * номер осмысленным.
     *
     * @return list<string>
     */
    public static function candidates(string $directory): array
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '' || !is_dir($directory)) {
            throw new \InvalidArgumentException('Каталог публичных загрузок не найден: ' . $directory);
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $files[] = $file->getPathname();
            if (count($files) >= self::MAX_FILES) {
                break;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array{scanned: int, optimized: int, planned: int, skipped: int, failed: int, cursor: int, total: int}
     */
    public static function run(
        string $directory,
        bool $dryRun = false,
        bool $force = false,
        int $limit = 0,
        int $offset = 0,
        float $timeBudget = 0.0
    ): array {
        $files = self::candidates($directory);
        $total = count($files);
        $index = max(0, min($offset, $total));

        $result = [
            'scanned' => 0,
            'optimized' => 0,
            'planned' => 0,
            'skipped' => 0,
            'failed' => 0,
            'cursor' => $index,
            'total' => $total,
        ];

        $startedAt = microtime(true);
        for (; $index < $total; $index++) {
            // Бюджет проверяется до файла, но только когда хотя бы один уже
            // просмотрен: иначе пакет мог бы вернуться, не сдвинув курсор, и
            // обработка встала бы на месте.
            if ($timeBudget > 0.0 && $result['scanned'] > 0 && (microtime(true) - $startedAt) >= $timeBudget) {
                break;
            }

            $result['scanned']++;
            $path = $files[$index];
            $info = @getimagesize($path);
            if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
                $result['failed']++;
                continue;
            }

            if (!$force && self::variantsAreFresh($path, (int) $info[0])) {
                $result['skipped']++;
                continue;
            }
            if ($limit > 0 && $result['planned'] + $result['optimized'] >= $limit) {
                break;
            }

            if ($dryRun) {
                $result['planned']++;
                continue;
            }

            // Старые оригиналы не перезаписываем: пакетная миграция должна быть
            // обратимой, а экономию трафика обеспечивают новые WebP-варианты.
            Uploader::optimizeImage($path, false);
            if (self::variantsAreFresh($path, (int) $info[0])) {
                $result['optimized']++;
            } else {
                $result['failed']++;
            }
        }
        $result['cursor'] = $index;

        return $result;
    }

    private static function variantsAreFresh(string $path, int $width): bool
    {
        $base = preg_replace('/\.[^.]+$/', '', $path) ?? $path;
        // Ждём ровно те варианты, которые создаёт загрузчик: список общий
        // (Media::VARIANT_WIDTHS). Свой список здесь означал бы, что после
        // добавления размера пакетная обработка считает старые файлы готовыми
        // и молча их пропускает.
        $expected = [$base . '.webp'];
        foreach (Media::VARIANT_WIDTHS as $variantWidth) {
            if ($width > $variantWidth) {
                $expected[] = $base . '-' . $variantWidth . '.webp';
            }
        }

        $sourceMtime = (int) @filemtime($path);
        foreach ($expected as $variant) {
            if (!is_file($variant) || (int) @filemtime($variant) < $sourceMtime) {
                return false;
            }
        }

        return true;
    }
}
