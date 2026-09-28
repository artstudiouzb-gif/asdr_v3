<?php

declare(strict_types=1);

/**
 * Импорт раздела документов (Download Manager старого сайта) из консоли.
 *
 * То же, что «Настройки сайта» → «Импорт документов», для тех, у кого есть
 * SSH. XML-экспорт и папку download-manager-files положите в
 * storage/imports/documents/ (или укажите свою папку --dir).
 *
 *   php scripts/wpdm_import.php --dry-run    проверить, все ли файлы нашлись
 *   php scripts/wpdm_import.php              перенести
 */

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\LegacyDocumentImporter;

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$dir = LegacyDocumentImporter::inbox();
$at = array_search('--dir', $args, true);
if ($at !== false && isset($args[$at + 1])) {
    $dir = rtrim((string) $args[$at + 1], '/');
}

try {
    $offset = 0;
    $sum = ['planned' => 0, 'imported' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0, 'pages' => 0, 'blocks' => 0, 'redirects' => 0];
    do {
        $r = LegacyDocumentImporter::run($dir, $dry, $offset, 60.0, null);
        foreach ($sum as $key => $value) {
            $sum[$key] = $value + (int) $r[$key];
        }
        foreach (array_merge($r['missing_files'], $r['errors'], $r['notes']) as $line) {
            echo '  ', $line, PHP_EOL;
        }
        $offset = $r['cursor'];
        echo sprintf('%d / %d', $offset, $r['total']), PHP_EOL;
    } while (!$r['done']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($sum as $key => $value) {
    echo str_pad($key, 10), $value, PHP_EOL;
}
exit($sum['failed'] > 0 ? 1 : 0);
