<?php

declare(strict_types=1);

/**
 * Достраивает WebP-варианты для ранее загруженных JPEG/PNG.
 *
 *   php scripts/optimize_images.php --dry-run
 *   php scripts/optimize_images.php
 *   php scripts/optimize_images.php --force --limit=100
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;
use App\Core\ImageBatchOptimizer;

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$limit = 0;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match)) {
        $limit = (int) $match[1];
    }
}

$directory = (string) Config::get('paths.public_uploads', APP_ROOT . '/public/uploads/public');
try {
    $result = ImageBatchOptimizer::run($directory, $dryRun, $force, $limit);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

printf(
    "%s Проверено: %d; создано: %d; запланировано: %d; пропущено: %d; ошибок: %d.%s",
    $dryRun ? '[DRY-RUN]' : '[OK]',
    $result['scanned'],
    $result['optimized'],
    $result['planned'],
    $result['skipped'],
    $result['failed'],
    PHP_EOL
);

exit($result['failed'] === 0 ? 0 : 1);
