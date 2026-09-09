<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\RbacGuard;

$fileId = filter_input(INPUT_GET, 'file_id', FILTER_VALIDATE_INT);
$token = $_GET['token'] ?? null;

if (!$fileId) {
    http_response_code(400);
    exit('Некорректный запрос.');
}

// Анти-перебор токенов доступа: неавторизованные попытки скачать защищённый
// файл ограничены (30 запросов / 5 минут с одного IP). Авторизованные сессией
// пользователи и публичные файлы под лимит не попадают (проверка ниже).
$downloadIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Auth::check()
    && !RateLimiter::throttle('download', $downloadIp, 30, 5)) {
    http_response_code(429);
    header('Retry-After: 300');
    exit('Слишком много запросов. Повторите позже.');
}

$stmt = Database::pdo()->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Файл не найден.');
}

$authorized = false;

if ($file['access_type'] === 'public') {
    $authorized = true;
} elseif (Auth::check() && RbacGuard::can('manage_protected_files')) {
    // Защищённые файлы без bearer-токена доступны только администратору.
    $authorized = true;
} elseif (is_string($token) && $token !== '' && !empty($file['access_token']) && hash_equals((string) $file['access_token'], $token)) {
    $authorized = true;
}

if (!$authorized) {
    // Не подтверждаем существование защищённого файла по последовательному id.
    http_response_code(404);
    exit('Файл не найден.');
}

$basePath = $file['access_type'] === 'protected'
    ? Config::get('paths.protected_uploads')
    : Config::get('paths.public_uploads');

$expectedBase = realpath($basePath);
$fullPath = $expectedBase !== false ? realpath($expectedBase . '/' . $file['stored_name']) : false;

if ($fullPath === false || $expectedBase === false || !str_starts_with($fullPath, $expectedBase)) {
    http_response_code(404);
    exit('Файл не найден.');
}

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Файл не найден.');
}

$update = Database::pdo()->prepare('UPDATE files SET download_count = download_count + 1 WHERE id = :id');
$update->execute([':id' => $fileId]);

$mimeType = $file['mime_type'] !== '' ? $file['mime_type'] : 'application/octet-stream';

header('Content-Type: ' . $mimeType);
// SVG исполняется браузером как документ — отдаём его как вложение и с жёстким
// CSP, запрещающим любой активный контент (defense-in-depth к санитизации).
if ($mimeType === 'image/svg+xml') {
    header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
} else {
    header('Content-Disposition: inline; filename="' . basename($file['original_name']) . '"');
}
header('Content-Length: ' . (string) filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($fullPath);
exit;
