<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\MediaUsage;

/*
 * «Где используется файл». Ручной список таблиц прежде пропускал слайды
 * обложки, альбомы, видео, цели, виджеты, записи каталога и конструкторы
 * шапки (логотип внутри JSON), и такой файл удалялся — страница ломалась
 * молча. Теперь обход идёт по всем текстовым колонкам, кроме перечисленных
 * журналов, а вывод обязан вести к записи.
 */

test('каждая таблица схемы разобрана: владелец известен или таблица служебная', function () {
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS `?([a-z0-9_]+)`?/', $schema, $m);
    $tables = (new ReflectionClassConstant(MediaUsage::class, 'TABLES'))->getValue();
    // Таблицы без картинок и ссылок — ни владельца, ни исключения им не нужно,
    // но решение о каждой обязано быть принято явно, а не по забывчивости.
    $plain = ['users', 'interface_translations', 'content_types', 'content_type_fields'];
    $unsorted = [];
    foreach (array_unique($m[1]) as $table) {
        if (!isset($tables[$table]) && !in_array($table, MediaUsage::IGNORED_TABLES, true) && !in_array($table, $plain, true)) {
            $unsorted[] = $table;
        }
    }
    assert_same([], $unsorted, 'новой таблице нужна строка в MediaUsage::TABLES или IGNORED_TABLES');
});

test('упоминание узнаётся по основе имени, включая уменьшенные копии и JSON', function () {
    assert_same('photo-a1b2c3', MediaUsage::stemOf('/uploads/public/photo-a1b2c3.jpg'));
    assert_same('photo-a1b2c3', MediaUsage::stemOf('photo-a1b2c3-800.webp'));
    assert_same('photo-a1b2c3', MediaUsage::stemOf('photo-a1b2c3.webp'));

    $json = json_encode(['logo' => '/uploads/public/logo-ffee00.svg', 'doc' => '/download.php?file_id=42&token=x']);
    $refs = MediaUsage::referencesIn((string) $json);
    assert_true(in_array('logo-ffee00', $refs, true), 'путь с экранированным слэшем');
    assert_true(in_array('#42', $refs, true), 'защищённый файл по номеру');
    assert_false(in_array('#4', $refs, true), 'номер не обрезается');
});

test('файл в слайде обложки и в конструкторе шапки удерживается и ведёт к записи', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $stem = 'hero-' . bin2hex(random_bytes(3));
    $url = '/uploads/public/' . $stem . '.jpg';
    $file = ['id' => 0, 'stored_name' => $stem . '.jpg', 'access_type' => 'public'];

    assert_same([], MediaUsage::find($file));

    $pdo->prepare("INSERT INTO heroes (name, status) VALUES (?, 'draft')")->execute(['Главная ' . $stem]);
    $heroId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO hero_slides (hero_id, title, sort_order, is_active, data) VALUES (?, ?, 0, 1, ?)')
        ->execute([$heroId, 'Слайд', json_encode(['bg_image' => $url])]);
    $slideId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute(['header_config_test_' . $stem, json_encode(['logo' => str_replace('.jpg', '-400.webp', $url)])]);

    $usage = MediaUsage::find($file);
    $urls = array_column($usage, 'url');
    assert_true(in_array('/admin/heroes/' . $heroId . '/slides/' . $slideId . '/edit', $urls, true), 'ссылка на слайд: ' . implode(', ', $urls));
    assert_true(in_array('/admin/header', $urls, true), 'уменьшенная копия в JSON шапки считается тем же файлом');

    // Фильтр «Не используется» и запрет удаления обязаны давать один ответ.
    $keys = MediaUsage::referencedKeys();
    assert_true(isset($keys[$stem]), 'проход по всей базе видит тот же файл');
    assert_true(\App\Core\MediaCleaner::referenceCount($url) >= 2, 'очистка сирот не удалит файл обложки');

    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['header_config_test_' . $stem]);
    $pdo->prepare('DELETE FROM heroes WHERE id = ?')->execute([$heroId]);
});

test('защищённый файл узнаётся по ссылке download.php, а не по имени', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->prepare("INSERT INTO files (original_name, stored_name, mime_type, size, access_type, access_token) VALUES ('Приказ.pdf', ?, 'application/pdf', 10, 'protected', 'tok')")
        ->execute(['prikaz-' . bin2hex(random_bytes(3)) . '.pdf']);
    $id = (int) $pdo->lastInsertId();
    $file = ['id' => $id, 'stored_name' => 'x.pdf', 'access_type' => 'protected'];
    assert_same([], MediaUsage::find($file));

    $pdo->prepare("INSERT INTO news (title, slug, status, content) VALUES ('Н', ?, 'draft', ?)")
        ->execute(['mu-' . $id, '<a href="/download.php?file_id=' . $id . '&amp;token=tok">Приказ</a>']);
    $newsId = (int) $pdo->lastInsertId();

    $usage = MediaUsage::find($file);
    assert_same(1, count($usage));
    assert_same('/admin/news/' . $newsId . '/edit', $usage[0]['url']);

    // Номер 1 и 10 — разные файлы.
    assert_same([], MediaUsage::find(['id' => (int) ($id . '0'), 'access_type' => 'protected']));

    $pdo->prepare('DELETE FROM news WHERE id = ?')->execute([$newsId]);
    $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
});
