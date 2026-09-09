<?php

declare(strict_types=1);

/**
 * Импорт новостей из старой CMS (REST API или WXR) с фотографиями.
 *
 * Запуск:
 *   php scripts/wp_import.php https://asdr.gov.uz [опции]
 *   php scripts/wp_import.php export.xml [опции]
 *
 * Опции:
 *   --limit N        импортировать не более N базовых новостей (0 = все)
 *   --status STATE   draft (по умолчанию) | published — статус НОВОЙ новости
 *   --author ID      id пользователя-автора (по умолчанию первый админ)
 *   --lang OLD:ART   код языка источника → код языка ArtStudio (повторяемо).
 *                    Первый язык считается основным. Например:
 *                    --lang uz:uz --lang ru:ru --lang en:en
 *   --uploads DIR    локальная папка старого wp-content/uploads; если файла
 *                    там нет, импортёр попробует скачать его со старого сайта
 *   --dry-run        построить полный план импорта без записи данных
 *
 * Для WXR импортируются только исходные записи status=publish. Исходные
 * черновики CMS и комментарии намеренно не переносятся.
 */

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\LegacyCmsImporter;
use App\Core\LegacyWxrImporter;

$args = array_slice($argv, 1);
$source = '';
$opts = ['status' => 'draft', 'limit' => 0, 'dryRun' => false, 'authorId' => null, 'langs' => [], 'uploadsDir' => null];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--limit' && isset($args[$i + 1])) {
        $opts['limit'] = (int) $args[++$i];
    } elseif ($a === '--status' && isset($args[$i + 1])) {
        $opts['status'] = $args[++$i];
    } elseif ($a === '--author' && isset($args[$i + 1])) {
        $opts['authorId'] = (int) $args[++$i];
    } elseif ($a === '--lang' && isset($args[$i + 1])) {
        [$wp, $art] = array_pad(explode(':', $args[++$i], 2), 2, '');
        if ($wp !== '' && $art !== '') {
            $opts['langs'][$wp] = $art;
        }
    } elseif ($a === '--uploads' && isset($args[$i + 1])) {
        $opts['uploadsDir'] = $args[++$i];
    } elseif ($a === '--dry-run') {
        $opts['dryRun'] = true;
    } elseif (str_starts_with($a, 'http') || is_file($a) || str_ends_with(strtolower($a), '.xml')) {
        $source = $a;
    }
}

if ($source === '') {
    fwrite(STDERR, "Укажите адрес сайта ИЛИ файл экспорта .xml, напр.:\n"
        . "  php scripts/wp_import.php https://asdr.gov.uz --lang uz:uz --lang ru:ru --limit 20\n"
        . "  php scripts/wp_import.php export.xml --lang uz:uz --lang ru:ru --lang en:en --dry-run\n"
        . "  php scripts/wp_import.php export.xml --lang uz:uz --lang ru:ru --lang en:en --uploads /path/wp-content/uploads\n");
    exit(2);
}

Database::init((array) Config::get('db'));

if ($opts['authorId'] === null) {
    try {
        $opts['authorId'] = (int) (Database::pdo()->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0) ?: null;
    } catch (\Throwable) {
    }
}

$isFile = !str_starts_with($source, 'http');
echo 'Импорт из ' . ($isFile ? "файла {$source}" : rtrim($source, '/'))
    . " (новый статус: {$opts['status']}" . ($opts['dryRun'] ? ', dry-run' : '') . ")…\n";
$r = $isFile
    ? LegacyWxrImporter::importFile($source, $opts)
    : LegacyCmsImporter::importAll(rtrim($source, '/'), $opts);

echo "\n────────────────────────────────────────\n";
if ($isFile && array_key_exists('source_published', $r)) {
    echo "Исходных опубликованных: {$r['source_published']}\n";
    echo "Исходных черновиков:      {$r['source_drafts']} (не импортируются)\n";
    echo "Комментариев в WXR:       {$r['source_comments']} (не импортируются)\n";
    echo "Базовых новостей в плане: {$r['planned_news']}\n";
    echo "Неопределённых переводов: {$r['unresolved']}\n";
    echo "────────────────────────────────────────\n";
}
echo "Импортировано новостей: {$r['imported']}\n";
echo "Пропущено (уже есть):   {$r['skipped']}\n";
echo "Переводов добавлено:    {$r['translations']}\n";
echo "Перенесено картинок:    {$r['images']}\n";
echo "Создано редиректов:     {$r['redirects']}\n";
if (!empty($r['errors'])) {
    echo "Ошибки (" . count($r['errors']) . "):\n";
    foreach (array_slice($r['errors'], 0, 20) as $e) {
        echo "  • {$e}\n";
    }
}

echo $opts['dryRun']
    ? "\nDry-run завершён: база данных и медиа не изменены.\n"
    : "\nГотово. Проверьте импорт в админке → Новости и сбросьте кэш.\n";

exit(empty($r['errors']) ? 0 : 1);
