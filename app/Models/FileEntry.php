<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\MediaMetadataSchema;

final class FileEntry
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM files ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    /**
     * Условия выборки по видам файлов. Один список на выдачу и на счётчики
     * боковой колонки: раздельные разъезжались бы молча — в списке одно число,
     * на экране другое.
     *
     * @var array<string, string>
     */
    private const LIBRARY_TYPES = [
        'all_files' => '1=1',
        'all' => "(mime_type LIKE 'image/%' OR mime_type LIKE 'video/%' OR mime_type LIKE 'audio/%')",
        'image' => "mime_type LIKE 'image/%'",
        'raster' => "mime_type LIKE 'image/%' AND mime_type <> 'image/svg+xml'",
        'svg' => "mime_type = 'image/svg+xml'",
        'video' => "mime_type LIKE 'video/%'",
        'audio' => "mime_type LIKE 'audio/%'",
        'document' => "mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'audio/%'",
    ];

    /** @var array<string, string> */
    private const LIBRARY_SORTS = [
        'date_desc' => 'created_at DESC',
        'date_asc' => 'created_at ASC',
        'name_asc' => 'original_name ASC',
        'size_desc' => 'size DESC',
    ];

    /** @return list<string> */
    public static function libraryTypes(): array
    {
        return array_keys(self::LIBRARY_TYPES);
    }

    /** @return list<string> */
    public static function librarySorts(): array
    {
        return array_keys(self::LIBRARY_SORTS);
    }

    /**
     * Постраничная выборка файлов для модальной медиабиблиотеки с фильтром по типу и поиску.
     */
    public static function libraryFiltered(string $type = 'image', int $limit = 300, int $offset = 0, string $query = '', string $sort = 'date_desc'): array
    {
        $sql = "SELECT * FROM files WHERE access_type = 'public'";
        $bind = [];

        if ($query !== '') {
            $sql .= ' AND original_name LIKE :q';
            $bind[':q'] = '%' . $query . '%';
        }

        $sql .= ' AND (' . (self::LIBRARY_TYPES[$type] ?? self::LIBRARY_TYPES['image']) . ')';
        $sql .= ' ORDER BY ' . (self::LIBRARY_SORTS[$sort] ?? self::LIBRARY_SORTS['date_desc']);
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Число файлов в каждом виде — для боковой колонки окна выбора. Считается
     * одним запросом с группировкой по mime, а не восемью отдельными.
     *
     * @return array<string, int>
     */
    public static function libraryCounts(string $query = ''): array
    {
        $sql = "SELECT mime_type, COUNT(*) AS c FROM files WHERE access_type = 'public'";
        $bind = [];
        if ($query !== '') {
            $sql .= ' AND original_name LIKE :q';
            $bind[':q'] = '%' . $query . '%';
        }
        $sql .= ' GROUP BY mime_type';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        $counts = array_fill_keys(array_keys(self::LIBRARY_TYPES), 0);
        foreach ($stmt->fetchAll() as $row) {
            $mime = (string) ($row['mime_type'] ?? '');
            $n = (int) ($row['c'] ?? 0);
            $isImage = str_starts_with($mime, 'image/');
            $isVideo = str_starts_with($mime, 'video/');
            $isAudio = str_starts_with($mime, 'audio/');

            $counts['all_files'] += $n;
            if ($isImage || $isVideo || $isAudio) {
                $counts['all'] += $n;
            }
            if ($isImage) {
                $counts['image'] += $n;
                if ($mime === 'image/svg+xml') {
                    $counts['svg'] += $n;
                } else {
                    $counts['raster'] += $n;
                }
            } elseif ($isVideo) {
                $counts['video'] += $n;
            } elseif ($isAudio) {
                $counts['audio'] += $n;
            } else {
                $counts['document'] += $n;
            }
        }

        return $counts;
    }

    public static function filtered(array $params, bool $includeProtected = true): array
    {
        $q = trim((string) ($params['q'] ?? ''));
        $type = trim((string) ($params['type'] ?? ''));
        $sort = trim((string) ($params['sort'] ?? 'date_desc'));
        $date = trim((string) ($params['date'] ?? ''));

        $sql = 'SELECT * FROM files WHERE 1=1';
        $bind = [];

        if (!$includeProtected) {
            $sql .= " AND access_type = 'public'";
        }

        if ($q !== '') {
            $sql .= ' AND original_name LIKE :q';
            $bind[':q'] = '%' . $q . '%';
        }

        if ($type === 'image') {
            $sql .= " AND mime_type LIKE 'image/%'";
        } elseif ($type === 'video') {
            $sql .= " AND mime_type LIKE 'video/%'";
        } elseif ($type === 'document') {
            $sql .= " AND mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'video/%'";
        }

        if ($date !== '' && preg_match('/^\d{4}-\d{2}$/', $date)) {
            $sql .= " AND DATE_FORMAT(created_at, '%Y-%m') = :date";
            $bind[':date'] = $date;
        }

        $orderBy = match ($sort) {
            'date_asc' => 'created_at ASC',
            'size_desc' => 'size DESC',
            'size_asc' => 'size ASC',
            'name_asc' => 'original_name ASC',
            'name_desc' => 'original_name DESC',
            default => 'created_at DESC',
        };

        $sql .= ' ORDER BY ' . $orderBy;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($bind);

        return $stmt->fetchAll();
    }

    public static function availableDates(): array
    {
        $stmt = Database::pdo()->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS date_val FROM files ORDER BY date_val DESC");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Находит публичный файл по каноническому URL медиабиблиотеки.
     * Внешние URL и произвольные пути намеренно не сопоставляются.
     */
    public static function findPublicByUrl(string $url): ?array
    {
        $baseUrl = rtrim((string) Config::get('paths.public_uploads_url'), '/');
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');
        if ($baseUrl === '' || !str_starts_with($path, $baseUrl . '/')) {
            return null;
        }

        $relative = ltrim(substr($path, strlen($baseUrl)), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '/')) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT * FROM files WHERE stored_name = :stored AND access_type = 'public' LIMIT 1"
        );
        $stmt->execute([':stored' => $relative]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO files (original_name, stored_name, mime_type, size, access_type, access_token, uploaded_by, created_at)
             VALUES (:original_name, :stored_name, :mime_type, :size, :access_type, :access_token, :uploaded_by, NOW())'
        );
        $stmt->execute([
            ':original_name' => $data['original_name'],
            ':stored_name' => $data['stored_name'],
            ':mime_type' => $data['mime_type'],
            ':size' => $data['size'],
            ':access_type' => $data['access_type'],
            ':access_token' => $data['access_token'],
            ':uploaded_by' => $data['uploaded_by'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Обновляет редакционные метаданные уже загруженного файла.
     *
     * @param array{alt_text?:?string,caption?:?string,description?:?string,credit?:?string,focal_x?:?int,focal_y?:?int} $metadata
     */
    public static function updateMetadata(int $id, array $metadata): ?array
    {
        MediaMetadataSchema::ensure();

        $file = self::findById($id);
        if ($file === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE files
             SET alt_text = :alt_text,
                 caption = :caption,
                 description = :description,
                 credit = :credit,
                 focal_x = :focal_x,
                 focal_y = :focal_y,
                 metadata_updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':alt_text' => $metadata['alt_text'] ?? null,
            ':caption' => $metadata['caption'] ?? null,
            ':description' => $metadata['description'] ?? null,
            ':credit' => $metadata['credit'] ?? null,
            ':focal_x' => $metadata['focal_x'] ?? null,
            ':focal_y' => $metadata['focal_y'] ?? null,
            ':id' => $id,
        ]);

        return self::findById($id);
    }

    public static function regenerateToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare('UPDATE files SET access_token = :token WHERE id = :id');
        $stmt->execute([':token' => $token, ':id' => $id]);

        return $token;
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function publicUrl(array $file): string
    {
        return rtrim((string) Config::get('paths.public_uploads_url'), '/') . '/' . $file['stored_name'];
    }

    public static function protectedUrl(array $file): string
    {
        if (($file['access_type'] ?? '') !== 'protected' || empty($file['access_token'])) {
            throw new \InvalidArgumentException('Для файла не создан защищённый URL.');
        }

        return '/download.php?file_id=' . (int) $file['id']
            . '&token=' . rawurlencode((string) $file['access_token']);
    }
}
