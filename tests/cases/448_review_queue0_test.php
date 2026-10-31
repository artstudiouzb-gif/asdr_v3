<?php

declare(strict_types=1);

use App\Core\HtmlSanitizer;

/*
 * Очередь 0 плана улучшений (docs/IMPROVEMENT_PLAN_2026-10.md): дефекты,
 * найденные ревизией октября 2026. Каждый сценарий держит закрытую границу.
 */

test('Тело новости: чужой iframe, style, meta и script вырезаются', function (): void {
    $out = HtmlSanitizer::sanitizeArticle(
        '<p>Текст</p>'
        . '<iframe src="https://evil.example/phish"></iframe>'
        . '<style>body{display:none}</style>'
        . '<meta http-equiv="refresh" content="0;url=https://evil.example">'
        . '<script src="https://www.youtube.com/x.js"></script>'
        . '<p onclick="alert(1)">клик</p>'
    );

    assert_contains('<p>Текст</p>', $out);
    assert_not_contains('evil.example', $out);
    assert_not_contains('<style', $out);
    assert_not_contains('<meta', $out);
    assert_not_contains('<script', $out);
    assert_not_contains('onclick', $out);
});

test('Тело новости: врезка YouTube остаётся с каноническим адресом', function (): void {
    $out = HtmlSanitizer::sanitizeArticle(
        '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen onload="x()"></iframe>'
    );

    assert_contains('<iframe', $out);
    assert_contains('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $out);
    assert_not_contains('onload', $out);
});

test('Тело новости: выравнивание текста сохраняется, вёрстка в style — нет', function (): void {
    $out = HtmlSanitizer::sanitizeArticle('<p style="text-align: center; position: fixed">Центр</p>');

    assert_contains('text-align: center', $out);
    assert_not_contains('position', $out);
});

test('Адрес записи в корзине занят: новая получает суффикс, а не 500', function (): void {
    ensure_test_db();

    $slug = 'trash-slug-' . bin2hex(random_bytes(2));
    $id = \App\Models\Page::create([
        'title' => 'В корзину',
        'slug' => $slug,
        'status' => 'published',
        'is_home' => 0,
        'layout_type' => 'no_sidebar',
        'lang' => 'ru',
    ]);
    \App\Models\Page::delete($id);

    // Уникальный индекс uq_pages_type_slug_lang видит и удалённые строки.
    assert_true(\App\Models\Page::slugExists($slug, null, 'ru'));
    assert_true(\App\Models\Project::slugExists($slug, null, 'ru') === false, 'проект — свой тип адреса');
    assert_equals(
        $slug . '-2',
        \App\Core\Slug::unique($slug, static fn (string $s, ?int $ex) => \App\Models\Page::slugExists($s, $ex, 'ru'))
    );
});

test('Открытые данные: CSV не пустой, поля каталога развёрнуты в колонки', function (): void {
    $rows = \App\Controllers\Site\OpenDataController::csvRows([
        ['id' => 1, 'title' => 'Вакансия', 'fields' => ['dept' => 'ИТ', 'tags' => ['a', 'b']]],
        ['id' => 2, 'title' => '=HYPERLINK("x")', 'fields' => ['salary' => '100']],
    ]);

    assert_same(['id', 'title', 'dept', 'tags', 'salary'], $rows[0]);
    assert_same(['1', 'Вакансия', 'ИТ', 'a; b', ''], $rows[1]);
    assert_same("'=HYPERLINK(\"x\")", $rows[2][1], 'формула табличного редактора уходит текстом');
    assert_same([], \App\Controllers\Site\OpenDataController::csvRows([]));
});

test('Бюджеты: одноимённые файлы в разных каталогах считаются оба', function (): void {
    $dir = sys_get_temp_dir() . '/budget-' . bin2hex(random_bytes(3));
    mkdir($dir . '/Admin', 0777, true);
    mkdir($dir . '/Site', 0777, true);
    file_put_contents($dir . '/Admin/NewsController.php', '<?php exit; exit;');
    file_put_contents($dir . '/Site/NewsController.php', '<?php exit;');
    try {
        $count = quality_regex_in_files([$dir . '/Admin/NewsController.php', $dir . '/Site/NewsController.php'], '/\bexit\b/');
        assert_same(3, $count['value'], 'по имени файла поздний затирал ранний');
    } finally {
        @unlink($dir . '/Admin/NewsController.php');
        @unlink($dir . '/Site/NewsController.php');
        @rmdir($dir . '/Admin');
        @rmdir($dir . '/Site');
        @rmdir($dir);
    }
});

test('Бэкапы: скачать можно только backup_*.zip из каталога копий', function (): void {
    foreach (['../config/config.php', 'backup_../../x.zip', '/etc/passwd', 'backup_x.zip.php', ''] as $name) {
        assert_same(null, \App\Core\Backup::archivePath($name), 'отклонено: ' . $name);
    }

    $dir = \App\Core\Backup::backupDir();
    $created = !is_dir($dir) && mkdir($dir, 0750, true);
    $name = 'backup_test-' . bin2hex(random_bytes(3)) . '.zip';
    file_put_contents($dir . '/' . $name, 'zip');
    try {
        assert_same($dir . '/' . $name, \App\Core\Backup::archivePath($name));
        assert_true(in_array($name, array_column(\App\Core\Backup::listArchives(), 'name'), true));
    } finally {
        @unlink($dir . '/' . $name);
        if ($created) {
            @rmdir($dir);
        }
    }
});

test('CSP: nonce получают только помеченные доверенные скрипты', function (): void {
    $SH = \App\Core\SecurityHeaders::class;
    // Скрипт, попавший в HTML блока ошибкой экранирования, nonce не получает —
    // в том числе с подделанной меткой без секретного значения.
    $leak = '<p><script>alert(1)</script><script data-trusted-script="x">alert(2)</script></p>';
    assert_not_contains('nonce=', $SH::injectTrustedScriptNonce($leak, 'n1'));

    $trusted = $SH::markTrustedScripts('<script src="/counter.js"></script>');
    $out = $SH::injectTrustedScriptNonce('<div>' . $trusted . '</div>', 'n1');
    assert_contains('<script nonce="n1" src="/counter.js">', $out);
    assert_not_contains('data-trusted-script', $out, 'секрет метки в разметку не уходит');
});

test('Удаление пользователя отзывает его сессии', function (): void {
    ensure_test_db();

    $suffix = bin2hex(random_bytes(3));
    $id = \App\Models\User::create('gone_' . $suffix, 'gone_' . $suffix . '@example.test', 'Secret-pass-1', 'editor');
    \App\Models\SessionRegistry::register($id, 'sid-' . $suffix, '127.0.0.1', 'test');
    assert_true(\App\Models\SessionRegistry::exists($id, 'sid-' . $suffix));

    \App\Models\User::delete($id);

    // Auth::check() сверяется с реестром: строки нет — входа нет.
    assert_false(\App\Models\SessionRegistry::exists($id, 'sid-' . $suffix), 'FK ON DELETE CASCADE снимает сессии');
});

test('Удаление страницы по id не уносит проект в корзину', function (): void {
    ensure_test_db();

    $slug = 'not-a-page-' . bin2hex(random_bytes(2));
    $projectId = \App\Models\Project::create([
        'title' => 'Проект',
        'slug' => $slug,
        'description' => '',
        'cover_image' => '',
        'status' => 'published',
        'lang' => 'ru',
    ]);
    \App\Models\Page::delete($projectId);

    $deleted = \App\Core\Database::pdo()->prepare('SELECT deleted_at FROM pages WHERE id = ?');
    $deleted->execute([$projectId]);
    assert_same(null, $deleted->fetchColumn(), 'проект остался на месте');
});
