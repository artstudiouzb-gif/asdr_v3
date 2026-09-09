<?php

declare(strict_types=1);

/*
 * Включить появление секций при прокрутке на уже существующих страницах.
 *
 *   php scripts/enable_block_reveal.php --dry-run        — показать план
 *   php scripts/enable_block_reveal.php                  — выполнить
 *   php scripts/enable_block_reveal.php --type=slide-up  — другой тип анимации
 *   php scripts/enable_block_reveal.php --page=12        — только одна страница
 *
 * Новые блоки получают появление сами (BlockController::store), но у страниц,
 * собранных раньше, настройка выключена. Скрипт проставляет её задним числом.
 *
 * Что пропускается и почему:
 *   — первый блок страницы на каждом языке: это первый экран, и прятать его
 *     до появления значит задержать то, ради чего страницу открыли;
 *   — обложка (hero): у неё свои переходы между слайдами;
 *   — вложенные блоки колонок и вкладок: появляется контейнер целиком,
 *     иначе анимация играет дважды;
 *   — блоки, где редактор уже выбрал анимацию: его выбор важнее нашего.
 *
 * Скрипт идемпотентен: повторный запуск ничего не меняет.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Cache;
use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);

$type = 'fade';
$onlyPage = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
    }
    if (str_starts_with($arg, '--page=')) {
        $onlyPage = (int) substr($arg, 7);
    }
}

$allowed = ['fade', 'slide-up', 'slide-left', 'slide-right', 'zoom-in', 'stagger'];
if (!in_array($type, $allowed, true)) {
    fwrite(STDERR, 'Неизвестный тип анимации: ' . $type . '. Допустимо: ' . implode(', ', $allowed) . PHP_EOL);
    exit(1);
}

$pdo = Database::pdo();
$sql = 'SELECT id, page_id, lang, type, title, data, sort_order
        FROM blocks
        WHERE parent_block_id IS NULL';
$params = [];
if ($onlyPage > 0) {
    $sql .= ' AND page_id = :page';
    $params[':page'] = $onlyPage;
}
$sql .= ' ORDER BY page_id ASC, lang ASC, sort_order ASC, id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$update = $pdo->prepare('UPDATE blocks SET data = :data WHERE id = :id');

/** @var array<string,bool> $seen страница+язык, у которых первый блок уже пройден */
$seen = [];
$changed = 0;
$skipped = 0;
$pages = [];

foreach ($rows as $row) {
    $key = $row['page_id'] . ':' . $row['lang'];
    $isFirst = !isset($seen[$key]);
    $seen[$key] = true;

    $reason = '';
    if ($isFirst) {
        $reason = 'первый блок страницы';
    } elseif ($row['type'] === 'hero') {
        $reason = 'обложка';
    }

    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }
    if ($reason === '') {
        $current = $data['_reveal'] ?? null;
        $enabled = is_array($current) ? !empty($current['enabled']) : !empty($current);
        if ($enabled) {
            $reason = 'анимация уже выбрана';
        }
    }

    if ($reason !== '') {
        $skipped++;
        continue;
    }

    $data['_reveal'] = ['enabled' => true, 'type' => $type];
    $changed++;
    $pages[(int) $row['page_id']] = true;

    printf(
        "%s страница %d [%s] блок %d «%s» (%s)%s",
        $dryRun ? 'план:' : 'вкл: ',
        (int) $row['page_id'],
        (string) $row['lang'],
        (int) $row['id'],
        (string) ($row['title'] ?? ''),
        (string) $row['type'],
        PHP_EOL
    );

    if (!$dryRun) {
        $update->execute([
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':id' => (int) $row['id'],
        ]);
    }
}

if (!$dryRun) {
    foreach (array_keys($pages) as $pageId) {
        Cache::clearPageCache($pageId);
    }
}

printf(
    '%s: блоков %d, пропущено %d, страниц затронуто %d.%s',
    $dryRun ? 'План' : 'Готово',
    $changed,
    $skipped,
    count($pages),
    PHP_EOL
);

exit(0);
