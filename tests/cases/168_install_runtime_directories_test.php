<?php

declare(strict_types=1);

test('installer preserves and can restore required runtime directories', function (): void {
    $root = dirname(__DIR__, 2);
    $required = [
        '/storage/logs/.gitkeep',
        '/storage/cache/.gitkeep',
        '/storage/sessions/.gitkeep',
        '/storage/backups/.gitkeep',
        '/storage/protected_uploads/.gitkeep',
        '/public/uploads/public/.gitkeep',
    ];

    foreach ($required as $file) {
        assert_true(is_file($root . $file), 'в поставке отсутствует ' . $file);
    }

    foreach ([
        '/storage/logs/README.md',
        '/storage/cache/README.md',
        '/storage/sessions/README.md',
        '/storage/backups/README.md',
        '/storage/protected_uploads/README.md',
        '/public/uploads/public/index.html',
    ] as $file) {
        assert_true(is_file($root . $file), 'нет видимого файла для сохранения каталога в ZIP: ' . $file);
    }

    $check = (string) file_get_contents($root . '/app/Core/EnvironmentCheck.php');
    assert_contains("@mkdir(\$directory, 0775, true)", $check);
    assert_contains("APP_ROOT . '/storage/logs'", $check);
    assert_contains("APP_ROOT . '/storage/protected_uploads'", $check);
    assert_contains("APP_ROOT . '/public/uploads/public'", $check);
});
