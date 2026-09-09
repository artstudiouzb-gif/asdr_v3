<?php

declare(strict_types=1);

/*
 * Чинит права у файлов медиатеки.
 *
 *   php scripts/fix_upload_permissions.php --dry-run   # показать, что изменится
 *   php scripts/fix_upload_permissions.php             # применить
 *
 * Зачем это нужно. Файл, загруженный через форму, приходит в PHP как
 * upload-файл и получает права по umask (обычно 0644). Файл, который PHP
 * собрал сам — импорт кадров-превью с YouTube, перенос со старого
 * сайта, сборка чанковой загрузки — наследует права временного файла, а `tempnam()` создаёт
 * его с 0600. Статику отдаёт веб-сервер, и такой файл он прочитать не может:
 * картинка выходит битой и в админке, и на сайте, хотя запись в медиатеке есть
 * и файл на диске лежит.
 *
 * Сам код это больше не допускает (`Uploader::storeFromPath` выставляет права
 * явно), а этот скрипт приводит в порядок то, что уже успело загрузиться.
 * Заодно показывает пустые и нечитаемые файлы — по ним видно, что дело не в
 * правах, а в самой загрузке.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;

$dryRun = in_array('--dry-run', $argv, true);

// Только публичные загрузки: их отдаёт веб-сервер, и именно там права
// решают, покажется картинка или нет. Защищённые файлы выдаёт download.php от
// имени самого PHP — трогать их права незачем.
$root = (string) Config::get('paths.public_uploads', '');
if ($root === '') {
    fwrite(STDERR, 'В конфигурации не задан путь к публичным загрузкам.' . PHP_EOL);
    exit(1);
}
if (!is_dir($root)) {
    fwrite(STDERR, 'Каталога загрузок нет: ' . $root . PHP_EOL);
    exit(1);
}

$checked = 0;
$fixedFiles = 0;
$fixedDirs = 0;
$empty = [];
$failed = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $path = $item->getPathname();
    $mode = fileperms($path);
    if ($mode === false) {
        $failed[] = $path;
        continue;
    }
    $mode &= 0777;

    if ($item->isDir()) {
        if ($mode !== 0755) {
            if ($dryRun || @chmod($path, 0755)) {
                $fixedDirs++;
            } else {
                $failed[] = $path;
            }
        }
        continue;
    }

    // Служебные точечные файлы (.gitkeep, .htaccess) не показываем как
    // «пустые»: пустыми они и должны быть.
    $checked++;
    if ($item->getSize() === 0 && !str_starts_with($item->getFilename(), '.')) {
        $empty[] = $path;
    }
    if ($mode !== 0644) {
        if ($dryRun || @chmod($path, 0644)) {
            $fixedFiles++;
        } else {
            $failed[] = $path;
        }
    }
}

$prefix = $dryRun ? 'Будет исправлено' : 'Исправлено';
fwrite(STDOUT, sprintf(
    "Проверено файлов: %d. %s файлов: %d, каталогов: %d.%s",
    $checked,
    $prefix,
    $fixedFiles,
    $fixedDirs,
    PHP_EOL
));

if ($empty !== []) {
    fwrite(STDOUT, 'Пустые файлы (права им не помогут — загрузка не удалась): ' . count($empty) . PHP_EOL);
    foreach (array_slice($empty, 0, 20) as $path) {
        fwrite(STDOUT, '  ' . $path . PHP_EOL);
    }
    if (count($empty) > 20) {
        fwrite(STDOUT, '  … и ещё ' . (count($empty) - 20) . PHP_EOL);
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Не удалось изменить права: ' . count($failed) . PHP_EOL);
    foreach (array_slice($failed, 0, 20) as $path) {
        fwrite(STDERR, '  ' . $path . PHP_EOL);
    }
    exit(1);
}

exit(0);
