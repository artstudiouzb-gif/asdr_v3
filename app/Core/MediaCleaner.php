<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Каскадная очистка файлов-сирот. При окончательном (физическом) удалении
 * страницы или новости собирает привязанные медиафайлы и удаляет их с диска —
 * но только если файл больше нигде не используется.
 */
final class MediaCleaner
{
    /** @return array<int, string> публичные URL файлов */
    public static function collectForPage(int $pageId): array
    {
        $refs = [];
        $stmt = Database::pdo()->prepare('SELECT data FROM blocks WHERE page_id = :id');
        $stmt->execute([':id' => $pageId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            foreach (self::extractPaths((string) $json) as $path) {
                $refs[$path] = true;
            }
        }

        return array_keys($refs);
    }

    /** @return array<int, string> */
    public static function collectForNews(array $news): array
    {
        $paths = [];
        if (!empty($news['image'])) {
            foreach (self::extractPaths((string) $news['image']) as $p) {
                $paths[$p] = true;
            }
        }

        if (!empty($news['id'])) {
            foreach (\App\Models\NewsImage::forNews((int) $news['id']) as $img) {
                foreach (self::extractPaths((string) $img['path']) as $p) {
                    $paths[$p] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /** @param array<int, string> $candidatePaths */
    public static function purgeUnreferenced(array $candidatePaths): void
    {
        foreach ($candidatePaths as $publicUrl) {
            if (self::isReferenced($publicUrl)) {
                continue;
            }
            self::deletePhysical($publicUrl);
        }
    }

    public static function isReferenced(string $publicUrl): bool
    {
        return self::referenceCount($publicUrl) > 0;
    }

    /**
     * Число упоминаний файла во всех таблицах системы.
     *
     * Запись в files считается владением медиабиблиотеки. Поэтому удаление
     * связи с новостью не удаляет физический файл: он остаётся доступным для
     * повторного использования, пока редактор явно не удалит его из библиотеки.
     */
    public static function referenceCount(string $publicUrl): int
    {
        if ($publicUrl === '') {
            return 0;
        }
        $pdo = Database::pdo();
        $basename = basename((string) (parse_url($publicUrl, PHP_URL_PATH) ?? $publicUrl));
        $like = '%' . $basename . '%';
        $total = 0;

        $likeQueries = [
            'SELECT COUNT(*) FROM blocks WHERE data LIKE :v',
            'SELECT COUNT(*) FROM news WHERE content LIKE :v',
            'SELECT COUNT(*) FROM news_translations WHERE content LIKE :v',
        ];
        $exactQueries = [
            'SELECT COUNT(*) FROM news WHERE image = :exact',
            'SELECT COUNT(*) FROM news_images WHERE path = :exact',
            "SELECT COUNT(*) FROM pages WHERE entity_type = 'project' AND cover_image = :exact",
            'SELECT COUNT(*) FROM team_members WHERE photo = :exact',
            'SELECT COUNT(*) FROM settings WHERE `value` = :exact',
        ];

        foreach ($likeQueries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':v', $like);
                $stmt->execute();
                $total += (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                Logger::error('referenceCount (like) failed: ' . $e->getMessage());
            }
        }
        foreach ($exactQueries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':exact', $publicUrl);
                $stmt->execute();
                $total += (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                Logger::error('referenceCount (exact) failed: ' . $e->getMessage());
            }
        }

        if ($basename !== '') {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE stored_name = :stored');
                $stmt->execute([':stored' => $basename]);
                $total += (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                Logger::error('referenceCount (library) failed: ' . $e->getMessage());
            }
        }

        return $total;
    }

    private static function deletePhysical(string $publicUrl): void
    {
        $baseUrl = rtrim((string) Config::get('paths.public_uploads_url'), '/');
        $baseDir = (string) Config::get('paths.public_uploads');

        if (!str_starts_with($publicUrl, $baseUrl . '/')) {
            return;
        }
        $relative = ltrim(substr($publicUrl, strlen($baseUrl)), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return;
        }

        $expectedBase = realpath($baseDir);
        $fullPath = $expectedBase !== false ? realpath($baseDir . '/' . $relative) : false;
        if ($fullPath === false || $expectedBase === false || !str_starts_with($fullPath, $expectedBase)) {
            return;
        }

        @unlink($fullPath);

        $base = preg_replace('/\.[^.]+$/', '', $fullPath) ?? $fullPath;
        foreach (\App\Core\Media::variantSuffixes() as $suffix) {
            $variant = $base . $suffix;
            if (is_file($variant)) {
                @unlink($variant);
            }
        }
    }

    /** @return array<int, string> */
    private static function extractPaths(string $haystack): array
    {
        $haystack = str_replace('\\/', '/', $haystack);
        $baseUrl = preg_quote(rtrim((string) Config::get('paths.public_uploads_url'), '/'), '#');
        if (preg_match_all('#' . $baseUrl . '/[A-Za-z0-9_./-]+#', $haystack, $m)) {
            return array_values(array_unique($m[0]));
        }

        return [];
    }
}
