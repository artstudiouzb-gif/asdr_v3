<?php

declare(strict_types=1);

/*
 * WebP-варианты снимка обязаны быть читаемыми веб-сервером.
 *
 * Оригиналу права ставит Uploader::storeFromPath() (0644), а варианты пишет
 * imagewebp() — файл получает права по umask, и на части хостингов это 0600.
 * Для <picture> это не мелочь: источник выбирается по типу, а НЕ по тому,
 * загрузился ли он, и на <img> браузер уже не откатывается. Замерено в
 * Chromium: недоступный источник даёт naturalWidth = 0 — вместо фотографии
 * остаётся alt-текст, хотя сам снимок на диске цел и читается.
 */

test('WebP-вариант открывается на чтение сразу после создания', function () {
    $uploader = (string) file_get_contents(APP_ROOT . '/app/Core/Uploader.php');

    // Голого imagewebp() быть не должно: файл, созданный PHP, надо явно
    // открыть на чтение — то же правило, что у storeFromPath().
    assert_true(
        !(bool) preg_match('/@?imagewebp\(\s*\$(src|resized)/', $uploader),
        'варианты пишутся через helper, а не голым imagewebp()'
    );
    assert_contains('private static function writeWebp(', $uploader);
    assert_true(
        (bool) preg_match('/function writeWebp\([^)]*\)[^{]*\{.*?chmod\(\$file, 0644\)/s', $uploader),
        'writeWebp() ставит 0644'
    );
});

test('Недоступный WebP-вариант не уничтожает картинку целиком', function () {
    $media = (string) file_get_contents(APP_ROOT . '/app/Core/Media.php');

    // Одного is_file() мало: PHP файл видит, а веб-сервер отдать не может.
    // Не отданный вариант обязан просто не попасть в srcset — тогда посетитель
    // увидит исходный снимок вместо пустого места.
    assert_contains('private static function servable(string $path): bool', $media);
    assert_contains('($mode & 0004) !== 0', $media, 'проверяется бит чтения «для всех»');
    assert_contains('self::servable($diskBase . $rel)', $media, 'варианты отбираются через servable()');
    assert_true(
        !str_contains($media, 'if (is_file($diskBase . $rel))'),
        'прежняя проверка одного лишь существования снята'
    );
});

test('Скрипт починки прав доходит до вложенных файлов загрузок', function () {
    $script = (string) file_get_contents(APP_ROOT . '/scripts/fix_upload_permissions.php');

    // Варианты лежат рядом с оригиналом в подпапках по датам, поэтому обход
    // обязан быть рекурсивным, а не по одной директории.
    assert_contains('RecursiveIteratorIterator', $script);
    assert_contains('chmod($path, 0644)', $script);
    assert_contains('--dry-run', $script, 'есть пробный прогон: скрипт трогает боевые файлы');
});
