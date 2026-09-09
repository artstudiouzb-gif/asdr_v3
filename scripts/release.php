<?php

declare(strict_types=1);

/**
 * Безопасная последовательность выпуска ПОСЛЕ загрузки новой версии кода.
 * Код/ветку скрипт не скачивает и не меняет.
 *
 *   php scripts/release.php https://artstudio.uz
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

$root = dirname(__DIR__);
$baseUrl = $argv[1] ?? '';
$scheme = is_string($baseUrl) ? strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) : '';
$host = is_string($baseUrl) ? (string) parse_url($baseUrl, PHP_URL_HOST) : '';
if (!is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
    || !in_array($scheme, ['http', 'https'], true) || $host === '') {
    fwrite(STDERR, "Укажите URL: php scripts/release.php https://example.com\n");
    exit(2);
}
$baseUrl = rtrim($baseUrl, '/');

/** @param string[] $arguments */
function releaseRun(string $label, string $script, array $arguments = []): void
{
    fwrite(STDOUT, "\n== {$label} ==\n");
    $parts = array_merge([PHP_BINARY, $script], $arguments);
    $command = implode(' ', array_map('escapeshellarg', $parts));
    passthru($command, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Выпуск остановлен: шаг «{$label}» завершился с кодом {$code}.\n");
        exit($code);
    }
}

releaseRun('Проверка окружения', $root . '/scripts/release_check.php', ['--allow-pending']);
$backupStartedAt = time();
releaseRun('Резервная копия', $root . '/app/Console/backup_worker.php');
$backups = glob($root . '/storage/backups/backup_*.zip') ?: [];
usort($backups, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
$newestBackup = $backups[0] ?? '';
$checksumFile = $newestBackup !== '' ? $newestBackup . '.sha256' : '';
$checksumLine = $checksumFile !== '' && is_file($checksumFile) ? trim((string) file_get_contents($checksumFile)) : '';
$storedChecksum = preg_match('/^([0-9a-f]{64})\s+/i', $checksumLine, $matches) === 1 ? strtolower($matches[1]) : '';
if ($newestBackup === '' || (int) filemtime($newestBackup) < $backupStartedAt - 1
    || $storedChecksum === '' || !hash_equals($storedChecksum, (string) hash_file('sha256', $newestBackup))) {
    fwrite(STDERR, "Выпуск остановлен: свежая резервная копия не найдена или не прошла проверку SHA-256.\n");
    exit(1);
}
releaseRun('Миграции базы данных', $root . '/database/migrate.php');
releaseRun('Шифрование секретов БД', $root . '/database/encrypt_secrets.php');

$releaseId = trim((string) (getenv('APP_RELEASE') ?: ''));
if ($releaseId === '') {
    $gitCommand = 'git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>&1';
    $releaseId = trim((string) shell_exec($gitCommand));
}
if (!preg_match('/^[0-9a-zA-Z._-]{7,100}$/', $releaseId)) {
    $releaseId = substr(hash('sha256',
        (string) @file_get_contents($root . '/app/Core/bootstrap.php')
        . (string) @file_get_contents($root . '/public/assets/asset-manifest.json')
    ), 0, 16);
}
$releaseFile = $root . '/storage/release.json';
$releasePayload = json_encode([
    'release' => $releaseId,
    'deployed_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($releasePayload)
    || file_put_contents($releaseFile, $releasePayload . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Выпуск остановлен: не удалось записать идентификатор релиза.\n");
    exit(1);
}

fwrite(STDOUT, "\n== Очистка файлового кеша ==\n");
$cacheDir = $root . '/storage/cache';
if (is_dir($cacheDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            if (!@rmdir($path) && is_dir($path)) {
                fwrite(STDERR, 'Не удалось удалить каталог кеша: ' . $path . PHP_EOL);
                exit(1);
            }
        } elseif ($item->getFilename() !== '.gitkeep' && !@unlink($path)) {
            fwrite(STDERR, 'Не удалось удалить кеш: ' . $path . PHP_EOL);
            exit(1);
        }
    }
}
fwrite(STDOUT, "Кеш очищен.\n");

// Эталон целостности снимается после каждого выпуска: иначе плановое
// обновление кода почасовой контроль целостности примет за подмену файлов.
releaseRun('Эталон целостности файлов', $root . '/app/Console/integrity_check.php', ['--baseline']);

releaseRun('Проверка после миграций', $root . '/scripts/release_check.php');
releaseRun('Smoke-обход сайта', $root . '/scripts/smoke.php', [$baseUrl, '--expect-release', $releaseId]);

fwrite(STDOUT, "\nВыпуск завершён успешно.\n");
