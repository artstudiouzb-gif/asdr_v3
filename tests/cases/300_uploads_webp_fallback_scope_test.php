<?php

declare(strict_types=1);

/*
 * Откат «нет jpg — отдаём одноимённый webp» собирает путь из запроса и уходит
 * с ним в readfile(). Значит границы каталога загрузок обязаны проверяться:
 * иначе адрес вида /uploads/public/../../config/config.jpg выводит путь за
 * пределы каталога (замерено — строка действительно выходила наружу).
 *
 * Читать так можно было только *.webp, а Apache обычно нормализует `..` раньше
 * PHP — но путь из запроса без границ не собирают в любом случае.
 */

test('Откат на webp не выпускает путь за каталог загрузок', function () {
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');

    // Набор символов не пропускает `..` вовсе — первая граница.
    assert_contains("preg_match('#^/uploads/public/([A-Za-z0-9._/-]+)\\.(jpe?g|png)\$#i'", $index);
    assert_contains("!str_contains(\$m[1], '..')", $index);

    // Вторая граница: разрешённый путь сверяется с корнем загрузок. Одного
    // набора символов мало — символические ссылки его обходят.
    assert_contains("realpath(__DIR__ . '/uploads/public')", $index);
    assert_contains('str_starts_with($webpDisk, $uploadsRoot . DIRECTORY_SEPARATOR)', $index);

    // Прежняя жадная маска пропускала что угодно, включая `..`.
    assert_true(
        !str_contains($index, "preg_match('#^/uploads/public/(.+)\\.(jpe?g|png)\$#i'"),
        'жадная маска пути снята'
    );

    // Отдаём чужой по происхождению файл — браузер не должен угадывать тип.
    assert_contains("header('X-Content-Type-Options: nosniff')", $index);
});

test('Откат на webp пропускает обычный адрес и отбивает выход из каталога', function () {
    $root = sys_get_temp_dir() . '/webpfallback-' . bin2hex(random_bytes(6));
    mkdir($root . '/uploads/public/2026/08', 0755, true);
    file_put_contents($root . '/uploads/public/2026/08/photo.webp', 'ok');
    file_put_contents($root . '/secret.webp', 'секрет');

    // Условие повторяет боевое дословно, только корень подставной.
    $resolve = static function (string $requestPath) use ($root): ?string {
        if (!str_starts_with($requestPath, '/uploads/public/')
            || !preg_match('#^/uploads/public/([A-Za-z0-9._/-]+)\.(jpe?g|png)$#i', $requestPath, $m)
            || str_contains($m[1], '..')) {
            return null;
        }
        $uploadsRoot = realpath($root . '/uploads/public');
        $webpDisk = realpath($root . '/uploads/public/' . $m[1] . '.webp');

        return $uploadsRoot !== false && $webpDisk !== false
            && str_starts_with($webpDisk, $uploadsRoot . DIRECTORY_SEPARATOR)
            && is_file($webpDisk)
                ? $webpDisk
                : null;
    };

    try {
        assert_same(
            realpath($root . '/uploads/public/2026/08/photo.webp'),
            $resolve('/uploads/public/2026/08/photo.jpg'),
            'обычная картинка по-прежнему отдаётся'
        );
        foreach ([
            '/uploads/public/../../secret.jpg',
            '/uploads/public/2026/08/../../../secret.png',
            '/uploads/public/..%2f..%2fsecret.jpg',
        ] as $attack) {
            assert_true($resolve($attack) === null, 'выход за каталог отбит: ' . $attack);
        }
    } finally {
        @unlink($root . '/uploads/public/2026/08/photo.webp');
        @unlink($root . '/secret.webp');
        @rmdir($root . '/uploads/public/2026/08');
        @rmdir($root . '/uploads/public/2026');
        @rmdir($root . '/uploads/public');
        @rmdir($root . '/uploads');
        @rmdir($root);
    }
});
