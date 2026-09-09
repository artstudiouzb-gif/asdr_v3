<?php

declare(strict_types=1);

use App\Core\Backup;

test('Backup: контрольная сумма пишется и проверяется, битый архив отвергается (задача 1.2)', function () {
    $tmp = sys_get_temp_dir() . '/artstudio_bktest_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);
    $zip = $tmp . '/backup_test.zip';
    file_put_contents($zip, 'FAKE-ARCHIVE-CONTENT');

    // Нет .sha256 рядом — verify() обязан вернуть false (целостность не подтверждена).
    assert_false(Backup::verify($zip), 'без sidecar не должно быть подтверждения');
    assert_same(null, Backup::storedChecksum($zip));

    // Пишем корректный sidecar в формате sha256sum.
    $hash = hash_file('sha256', $zip);
    file_put_contents(Backup::checksumPath($zip), $hash . '  ' . basename($zip) . "\n");

    assert_same($hash, Backup::storedChecksum($zip));
    assert_true(Backup::verify($zip), 'интактный архив должен проходить проверку');

    // Портим архив — сумма больше не совпадает.
    file_put_contents($zip, 'X', FILE_APPEND);
    assert_false(Backup::verify($zip), 'битый архив должен отвергаться');

    @unlink($zip);
    @unlink(Backup::checksumPath($zip));
    @rmdir($tmp);
});

test('Backup: checksumPath и ротация с days<=0 безопасны (задача 1.2)', function () {
    assert_same('/x/backup_1.zip.sha256', Backup::checksumPath('/x/backup_1.zip'));
    // days <= 0 → ротация отключена, ничего не удаляет.
    assert_same(0, Backup::rotate(0));
    assert_same(0, Backup::rotate(-5));
});
