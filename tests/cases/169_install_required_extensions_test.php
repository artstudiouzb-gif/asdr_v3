<?php

declare(strict_types=1);

// Веб-установщик обязан проверить тот же набор расширений, который нужен
// работающей CMS и проверяется scripts/release_check.php.

test('Установщик проверяет все обязательные PHP-расширения', function (): void {
    $environmentCheck = (string) file_get_contents(APP_ROOT . '/app/Core/EnvironmentCheck.php');
    $releaseCheck = (string) file_get_contents(APP_ROOT . '/scripts/release_check.php');

    foreach (['pdo_mysql', 'mbstring', 'gd', 'curl', 'dom', 'fileinfo', 'openssl', 'zip'] as $extension) {
        assert_contains("'" . $extension . "'", $environmentCheck, "установщик проверяет {$extension}");
        assert_contains("'" . $extension . "'", $releaseCheck, "релизная проверка проверяет {$extension}");
    }
});
