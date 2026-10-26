<?php

declare(strict_types=1);

use App\Core\LogReader;

test('Журналы: выгрузка берёт активный файл и архивы ротации', function (): void {
    $file = LogReader::path('security');
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $backup = [];
    foreach ([$file, $file . '.1', $file . '.2'] as $path) {
        $backup[$path] = is_file($path) ? file_get_contents($path) : null;
    }
    try {
        file_put_contents($file, "[2026-10-26 10:00:00] SECURITY: свежая\n");
        file_put_contents($file . '.1', "[2026-10-21 10:00:00] SECURITY: из ротации\n");
        @unlink($file . '.2');

        assert_same([$file, $file . '.1'], LogReader::files('security'));
        assert_same([], LogReader::files('../../config/config'));

        $zip = LogReader::archive('security');
        assert_true($zip !== null, 'архив собран');
        $archive = new ZipArchive();
        assert_true($archive->open((string) $zip) === true);
        assert_same("[2026-10-21 10:00:00] SECURITY: из ротации\n", $archive->getFromName('security.log.1'));
        assert_true($archive->getFromName('security.log') !== false);
        $archive->close();
        @unlink((string) $zip);

        $all = LogReader::archive('all');
        assert_true($all !== null, 'общий архив собран');
        @unlink((string) $all);
        assert_same(null, LogReader::archive('нет-такого'));
    } finally {
        foreach ($backup as $path => $content) {
            $content === null ? @unlink($path) : file_put_contents($path, $content);
        }
    }
});
