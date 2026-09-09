<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Uploader;
use RuntimeException;

/**
 * Файл защищённого репозитория. Хранится в storage/protected_uploads/repo/
 * (вне webroot) под случайным именем; отдаётся стримом только после проверки
 * сессии портала (RepoAuth). Загружает только администратор.
 */
final class RepoFile
{
    private const MAX_SIZE_BYTES = 100 * 1024 * 1024; // 100 МБ — документы/архивы

    // Разрешённые расширения репозитория (документы, таблицы, презентации,
    // архивы, изображения). Ключ — расширение, значение — ожидаемый MIME.
    private const ALLOWED = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'rtf' => 'application/rtf',
        'zip' => 'application/zip',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public static function basePath(): string
    {
        return rtrim((string) Config::get('paths.protected_uploads'), '/') . '/repo';
    }

    /**
     * Список файлов; в каждой строке дополнительно computed-поле `category` —
     * полное имя категории («Родитель / Дочка») для отображения.
     *
     * @param string $query поиск по заголовку/описанию/имени файла/категории
     * @param int $categoryId фильтр по категории (0 — все); для корневой
     *                        категории включаются и её подкатегории
     * @param string $status 'approved' (по умолчанию), 'pending' или '' — любые
     * @param string $ext фильтр по расширению файла (pdf, docx, xlsx, zip, etc.)
     * @param string $sort сортировка (newest, popular, name, size)
     */
    public static function all(string $query = '', int $categoryId = 0, string $status = 'approved', string $ext = '', string $sort = 'newest'): array
    {
        $sql = "SELECT f.*, CONCAT_WS(' / ', p.name, c.name) AS category
                FROM repo_files f
                LEFT JOIN repo_categories c ON c.id = f.category_id
                LEFT JOIN repo_categories p ON p.id = c.parent_id
                WHERE 1 = 1";
        $params = [];

        if ($status !== '') {
            $sql .= ' AND f.status = :status';
            $params[':status'] = $status;
        }

        if ($ext !== '') {
            $sql .= ' AND f.original_name LIKE :ext';
            $params[':ext'] = '%.' . mb_strtolower($ext);
        }

        if ($query !== '') {
            $sql .= ' AND (f.title LIKE :q1 OR f.description LIKE :q2 OR f.original_name LIKE :q3 OR c.name LIKE :q4 OR p.name LIKE :q5)';
            $like = '%' . $query . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
        }
        if ($categoryId > 0) {
            $sql .= ' AND (f.category_id = :cat OR c.parent_id = :cat2)';
            $params[':cat'] = $categoryId;
            $params[':cat2'] = $categoryId;
        }

        switch ($sort) {
            case 'popular':
                $sql .= ' ORDER BY f.download_count DESC, f.created_at DESC';
                break;
            case 'name':
                $sql .= ' ORDER BY f.title ASC, f.created_at DESC';
                break;
            case 'size':
                $sql .= ' ORDER BY f.size DESC, f.created_at DESC';
                break;
            case 'newest':
            default:
                $sql .= ' ORDER BY f.created_at DESC, f.id DESC';
                break;
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM repo_files')->fetchColumn();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM repo_files WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<int> $ids */
    public static function findManyByIds(array $ids): array
    {
        $cleanIds = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if ($cleanIds === []) {
            return [];
        }
        $in = implode(',', $cleanIds);
        return Database::pdo()->query("SELECT * FROM repo_files WHERE id IN ($in) AND status = 'approved'")->fetchAll();
    }

    /** Файлы, ждущие одобрения, с логином загрузившего пользователя портала. */
    public static function pending(): array
    {
        return Database::pdo()->query(
            "SELECT f.*, CONCAT_WS(' / ', p.name, c.name) AS category, u.username AS repo_username
             FROM repo_files f
             LEFT JOIN repo_categories c ON c.id = f.category_id
             LEFT JOIN repo_categories p ON p.id = c.parent_id
             LEFT JOIN repo_users u ON u.id = f.uploaded_by_repo_user
             WHERE f.status = 'pending'
             ORDER BY f.created_at ASC, f.id ASC"
        )->fetchAll();
    }

    public static function pendingCount(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM repo_files WHERE status = 'pending'"
        )->fetchColumn();
    }

    public static function approve(int $id): void
    {
        $stmt = Database::pdo()->prepare("UPDATE repo_files SET status = 'approved' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public static function incrementDownload(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE repo_files SET download_count = download_count + 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Валидирует и сохраняет загруженный администратором файл, затем создаёт
     * запись. Возвращает id новой записи.
     *
     * @param array $fileInput элемент $_FILES
     */
    public static function store(
        array $fileInput,
        string $title,
        string $description,
        ?int $categoryId,
        ?int $uploadedBy,
        ?int $repoUserId = null,
        string $status = 'approved'
    ): int
    {
        if (($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ошибка загрузки файла.');
        }
        if (!is_uploaded_file($fileInput['tmp_name'])) {
            throw new RuntimeException('Некорректный файл.');
        }
        $size = (int) $fileInput['size'];
        if ($size > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Файл превышает максимальный размер 100 МБ.');
        }

        $originalName = (string) $fileInput['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw new RuntimeException('Недопустимый тип файла: .' . $extension);
        }

        // Disk Space Guard (переиспользуем проверку из Uploader по protected-пути).
        Uploader::assertDiskSpace('protected');

        $base = self::basePath();
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Не удалось создать директорию хранилища.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $base . '/' . $storedName;

        if (!move_uploaded_file((string) $fileInput['tmp_name'], $destination)) {
            throw new RuntimeException('Не удалось сохранить файл на диске.');
        }

        // Определяем реальный MIME содержимого (изображения санитизируем через
        // общий SVG-путь не требуется — SVG в репозиторий не принимаем).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($destination);
        $expectedMime = self::ALLOWED[$extension];
        $compatible = $mime === $expectedMime
            || ($extension === 'csv' && in_array($mime, ['text/plain', 'application/csv'], true))
            || ($extension === 'rtf' && $mime === 'text/rtf')
            || (in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)
                && $mime === 'application/zip');
        if (!$compatible) {
            @unlink($destination);
            throw new RuntimeException('Содержимое файла не соответствует расширению.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO repo_files (title, description, category_id, stored_name, original_name, mime_type, size, status, uploaded_by, uploaded_by_repo_user, created_at)
             VALUES (:title, :descr, :cat, :stored, :orig, :mime, :size, :status, :by, :repo_user, NOW())'
        );
        $stmt->execute([
            ':title' => $title,
            ':descr' => $description !== '' ? $description : null,
            ':cat' => $categoryId,
            ':stored' => $storedName,
            ':orig' => $originalName,
            ':mime' => $mime,
            ':size' => $size,
            ':status' => $status === 'pending' ? 'pending' : 'approved',
            ':by' => $uploadedBy,
            ':repo_user' => $repoUserId,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /** Обновляет название/описание/категорию без перезагрузки самого файла. */
    public static function updateMeta(int $id, string $title, string $description, ?int $categoryId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE repo_files SET title = :title, description = :descr, category_id = :cat WHERE id = :id'
        );
        $stmt->execute([
            ':title' => $title,
            ':descr' => $description !== '' ? $description : null,
            ':cat' => $categoryId,
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $file = self::findById($id);
        if ($file === null) {
            return;
        }

        // Удаляем файл с диска строго внутри базовой директории (защита от
        // path traversal, аналогично download.php).
        $expectedBase = realpath(self::basePath());
        if ($expectedBase !== false) {
            $full = realpath($expectedBase . '/' . $file['stored_name']);
            if ($full !== false && str_starts_with($full, $expectedBase) && is_file($full)) {
                @unlink($full);
            }
        }

        $stmt = Database::pdo()->prepare('DELETE FROM repo_files WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
