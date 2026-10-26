<?php

declare(strict_types=1);

use App\Core\PhpAdvisory;

test('PhpAdvisory: PHP2Shell — уязвимые и исправленные сборки по веткам', function (): void {
    foreach (PhpAdvisory::PHP2SHELL_FIXED as $branch => $fixed) {
        assert_true(PhpAdvisory::check($fixed)['vulnerable'] === false, $fixed . ' исправлена');
        assert_true(PhpAdvisory::check($branch . '.0')['vulnerable'], $branch . '.0 уязвима');
    }
    $old = PhpAdvisory::check('8.4.26');
    assert_same('8.4.27', $old['fixed']);
    assert_same(PhpAdvisory::PHP2SHELL_CVES, $old['cves']);
    // Ветки без исправления уязвимы всегда, ветка новее исправления — нет.
    assert_true(PhpAdvisory::check('8.1.33')['vulnerable']);
    assert_true(PhpAdvisory::check('7.4.33')['vulnerable']);
    assert_true(PhpAdvisory::check('8.6.0')['vulnerable'] === false);
    // Суффикс сборки дистрибутива не делает исправленную версию «младше».
    assert_true(PhpAdvisory::check('8.5.12-1+ubuntu24.04')['vulnerable'] === false);
    assert_true(PhpAdvisory::check('8.5.11-1+ubuntu24.04')['vulnerable']);
});

test('PhpAdvisory: «Состояние системы» показывает версию PHP и путь исправления', function (): void {
    $found = null;
    foreach (\App\Core\SystemHealth::groups() as $group) {
        foreach ($group['checks'] as $check) {
            if ($check['id'] === 'php_cve') {
                $found = $check;
            }
        }
    }
    assert_true($found !== null, 'строка php_cve есть');
    $expected = PhpAdvisory::check()['vulnerable'] ? 'fail' : 'ok';
    assert_same($expected, $found['state']);
    assert_same('Обновить PHP', \App\Core\SystemHealth::solution('php_cve')['label']);
});
