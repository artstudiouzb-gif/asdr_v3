<?php

declare(strict_types=1);

use App\Core\MediaHealth;

/*
 * Проверка медиатеки в админке: отдаст ли веб-сервер то, на что ссылается сайт.
 *
 * Нужна затем, что иначе ответ добывается только по SSH, а владелец сайта до
 * консоли обычно не доходит. Причин, по которым на месте фотографии пусто,
 * с диска видно две — файл не открыт на чтение и файл нулевого размера, — и
 * обе смертельны одинаково: запись в медиатеке есть, файл на диске лежит,
 * а картинки нет.
 */

test('Проверка медиатеки находит нечитаемые и пустые файлы', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        skip_test('POSIX permissions are not supported on Windows');
    }
    $dir = sys_get_temp_dir() . '/mediahealth-' . bin2hex(random_bytes(6));
    mkdir($dir . '/2026/08', 0755, true);

    $ok = $dir . '/2026/08/ok.jpg';
    $unreadable = $dir . '/2026/08/wp-photo.jpg';
    $blank = $dir . '/2026/08/truncated.webp';
    file_put_contents($ok, 'x');
    file_put_contents($unreadable, 'x');
    file_put_contents($blank, '');
    chmod($ok, 0644);
    chmod($blank, 0644);
    // 0600 — ровно то, что оставляет tempnam() у файла, собранного самим PHP.
    chmod($unreadable, 0600);

    try {
        $report = MediaHealth::scan($dir);

        assert_true((bool) $report['exists'], 'каталог найден');
        assert_same(3, $report['checked'], 'обойдены все файлы, включая вложенные');
        assert_same(3, $report['images'], 'изображения посчитаны отдельно');
        assert_same(1, $report['unreadable'], 'файл без бита чтения «для всех» найден');
        assert_same(1, $report['empty'], 'пустой файл найден');
        assert_true(!MediaHealth::healthy($report), 'отчёт с находками не считается здоровым');

        // В отчёте — относительные пути: полный путь на диске сервера
        // администратору ничего не говорит и лишний раз светит структуру.
        assert_same(['2026/08/wp-photo.jpg'], $report['samples']['unreadable']);
        assert_same(['2026/08/truncated.webp'], $report['samples']['empty']);

        // Пустой файл засчитывается один раз: до прав дело не доходит,
        // отдавать нечего в любом случае.
        assert_true(
            !in_array('2026/08/truncated.webp', $report['samples']['unreadable'], true),
            'пустой файл не попадает ещё и в нечитаемые'
        );

        // Починили права — находок нет.
        chmod($unreadable, 0644);
        unlink($blank);
        $after = MediaHealth::scan($dir);
        assert_same(0, $after['unreadable']);
        assert_same(0, $after['empty']);
        assert_true(MediaHealth::healthy($after), 'после починки отчёт чистый');
    } finally {
        @chmod($unreadable, 0644);
        foreach ([$ok, $unreadable, $blank] as $file) {
            @unlink($file);
        }
        @rmdir($dir . '/2026/08');
        @rmdir($dir . '/2026');
        @rmdir($dir);
    }
});

test('Проверка медиатеки находит записи без файла на диске', function () {
    $dir = sys_get_temp_dir() . '/mediahealth-miss-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/есть.jpg', 'x');

    try {
        // Обход каталога такую находку поймать не может в принципе: он видит
        // то, что лежит, а не то, на что ссылается сайт. Между тем это самый
        // тихий отказ — запись в медиатеке выглядит целой.
        $report = \App\Core\MediaHealth::missingFrom(['есть.jpg', 'wp-photo-пропал.jpg', ''], $dir);

        assert_same(2, $report['checked'], 'пустое имя не считается записью');
        assert_same(1, $report['missing']);
        assert_same(['wp-photo-пропал.jpg'], $report['samples']);

        $scan = \App\Core\MediaHealth::scan($dir);
        assert_true(\App\Core\MediaHealth::healthy($scan), 'сам каталог здоров');
        assert_true(
            !\App\Core\MediaHealth::healthy($scan, $report),
            'но с учётом пропавшей записи отчёт здоровым не считается'
        );
    } finally {
        @unlink($dir . '/есть.jpg');
        @rmdir($dir);
    }
});

test('Проверка медиатеки не падает на отсутствующем каталоге', function () {
    $report = MediaHealth::scan(sys_get_temp_dir() . '/mediahealth-нет-такого-' . bin2hex(random_bytes(4)));

    assert_true(!$report['exists'], 'отсутствие каталога — это ответ, а не исключение');
    assert_same(0, $report['checked']);
});

test('Проверка медиатеки доступна из админки и ничего не меняет', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php');

    // Только супер-админ и только по явному запросу: обход каталога при каждом
    // открытии раздела — это плата за диагностику всегда.
    assert_contains('Auth::requireSuperAdmin()', $controller);
    assert_contains("(\$_GET['media_check'] ?? '') === '1'", $controller);
    assert_contains('MediaHealth::scan()', $controller);
    assert_contains('MediaHealth::missing()', $controller, 'сверка записей с диском тоже включена');
    assert_contains('media_check=1#perf-images', $view, 'ссылка ведёт на свой раздел');

    // Обход ограничен: медиатека растёт, а раздел админки не имеет права зависнуть.
    $health = (string) file_get_contents(APP_ROOT . '/app/Core/MediaHealth.php');
    assert_contains('MAX_FILES', $health);
    assert_contains('TIME_BUDGET', $health);
    assert_true(
        (bool) preg_match('/truncated.*=\s*true/', $health),
        'упёрлись в предел — отчёт говорит, что показан не весь каталог'
    );

    // Проверка читающая: ни одной записи на диск.
    foreach (['chmod(', 'unlink(', 'file_put_contents(', 'rename('] as $write) {
        assert_true(!str_contains($health, $write), 'проверка ничего не пишет: ' . $write);
    }
});
