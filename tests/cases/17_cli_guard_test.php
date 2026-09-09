<?php

declare(strict_types=1);

use App\Core\Cli;

test('Cli::isCli распознаёт CLI/phpdbg и отвергает веб-SAPI (задача 1.1)', function () {
    // Реальный процесс тестов запущен из CLI.
    assert_true(Cli::isCli(), 'тест-раннер должен определяться как CLI');

    // Явно переданные SAPI: cli/phpdbg — да, остальные — нет.
    assert_true(Cli::isCli('cli'));
    assert_true(Cli::isCli('phpdbg'));
    assert_false(Cli::isCli('apache2handler'), 'apache — не CLI');
    assert_false(Cli::isCli('fpm-fcgi'), 'php-fpm — не CLI');
    assert_false(Cli::isCli('cgi-fcgi'), 'cgi — не CLI');
    assert_false(Cli::isCli('litespeed'), 'litespeed — не CLI');
});

test('CLI-only скрипты вызывают assertCli первой строкой (задача 1.1)', function () {
    // Гарантируем, что защита реально подключена в служебных скриптах —
    // иначе .php был бы исполним через веб в fallback-сценарии docroot=корень.
    $scripts = [
        'database/create_admin.php',
        'database/backup.php',
        'database/migrate.php',
        'database/doctor.php',
        'app/Console/mail_worker.php',
        'app/Console/digest_worker.php',
        'app/Console/social_worker.php',
        'app/Console/webhook_worker.php',
        'app/Console/backup_worker.php',
        'app/Console/gdpr_cleanup.php',
        'database/restore.php',
    ];
    foreach ($scripts as $rel) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $rel);
        assert_contains('Cli::assertCli()', $src, "{$rel} не вызывает Cli::assertCli()");
        // Проверка должна стоять до подключения bootstrap (до любой бизнес-логики).
        $posAssert = strpos($src, 'Cli::assertCli()');
        $posBootstrap = strpos($src, "bootstrap.php");
        if ($posBootstrap !== false) {
            assert_true($posAssert < $posBootstrap, "{$rel}: assertCli должен идти до bootstrap");
        }
    }
});
