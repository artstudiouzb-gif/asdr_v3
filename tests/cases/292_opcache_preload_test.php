<?php

declare(strict_types=1);

/**
 * Тест скрипта предзагрузки OPcache (preload.php).
 */

test('preload.php: существует, корректно загружает классы ядра, моделей и контроллеров', function (): void {
    $preloadFile = APP_ROOT . '/preload.php';
    assert_true(is_file($preloadFile), 'preload.php должен существовать в корне проекта');

    // Проверяем, что файл возвращает непустой список загруженных файлов
    $files = require $preloadFile;

    assert_true(is_array($files), 'preload.php должен возвращать массив прелоаднутых файлов');
    assert_true(count($files) >= 50, 'preload.php должен компилировать ключевые файлы');

    // Проверяем, что ключевые классы загружены
    assert_true(class_exists(\App\Core\Router::class), 'App\Core\Router должен быть загружен');
    assert_true(class_exists(\App\Core\Database::class), 'App\Core\Database должен быть загружен');
    assert_true(class_exists(\App\Core\SchemaOrg::class), 'App\Core\SchemaOrg должен быть загружен');
    assert_true(class_exists(\App\Models\News::class), 'App\Models\News должен быть загружен');
    assert_true(class_exists(\App\Models\Page::class), 'App\Models\Page должен быть загружен');
});
