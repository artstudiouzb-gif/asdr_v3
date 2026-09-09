<?php

declare(strict_types=1);

use App\Core\DemoSeeder;

test('DemoSeeder RESET полностью заменяет контент эталонными данными (БД)', function (): void {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    // 1. Создаём созданный руками мусорный элемент
    $pdo->exec("INSERT INTO news (title, slug, excerpt, content, status, published_at, created_at) VALUES ('Junk News', 'junk-news-slug-test', 'junk', 'junk', 'published', NOW(), NOW())");
    $hasJunkBefore = (bool) $pdo->query("SELECT 1 FROM news WHERE slug = 'junk-news-slug-test'")->fetchColumn();
    assert_true($hasJunkBefore, 'Мусорная новость создана в БД');

    // 2. Запускаем resetAndRun
    $c = DemoSeeder::resetAndRun($pdo);

    // 3. Проверяем, что мусорная новость удалилась, а демо-новости созданы
    $hasJunkAfter = (bool) $pdo->query("SELECT 1 FROM news WHERE slug = 'junk-news-slug-test'")->fetchColumn();
    assert_false($hasJunkAfter, 'Мусорная новость удалена после RESET + DEMO');

    $hasDemoNews = (bool) $pdo->query("SELECT 1 FROM news WHERE slug = 'zasedanie-strategiya-2030'")->fetchColumn();
    assert_true($hasDemoNews, 'Флагманская демо-новость пересоздана');

    assert_true($c['news'] > 0, 'Счётчик новостей > 0');
    assert_true($c['pages'] > 0, 'Счётчик страниц > 0');
    assert_true($c['menu'] >= 40, 'Создано полное двухъязычное меню');
    assert_true($c['meropriyatiya'] >= 3, 'Созданы мероприятия');
    assert_same([], DemoSeeder::verify($pdo), 'Встроенная проверка не обнаружила конфликтов');

    $directorParent = $pdo->query(
        "SELECT parent.slug
         FROM pages child
         INNER JOIN pages parent ON parent.id = child.parent_id
         WHERE child.slug = 'direktor' AND child.lang = 'ru'
         LIMIT 1"
    )->fetchColumn();
    assert_same('rukovodstvo', (string) $directorParent, 'Демо-страницы имеют актуальную иерархию');

    $secondRun = DemoSeeder::run($pdo);
    assert_same(0, array_sum($secondRun), 'Повторный запуск полностью идемпотентен');

    $version = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'demo_data_version' LIMIT 1")->fetchColumn();
    // Версию сверяем с самим сидером: она поднимается при каждой правке
    // демо-контента, и держать её копию в тесте — лишний ручной шаг.
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    preg_match("/DEMO_VERSION = '([^']+)'/", $seeder, $m);
    assert_same($m[1] ?? '', (string) $version, 'Версия демо-комплекта сохранена');
});

test('DemoSeeder RESET с дополнительным активным языком (en) проходит валидацию и создаёт страницы и меню', function (): void {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    // Включаем третий активный язык (en)
    $pdo->exec(
        "INSERT INTO languages (code, name, short_name, is_default, is_active, sort_order)
         VALUES ('en', 'English', 'Eng', 0, 1, 2)
         ON DUPLICATE KEY UPDATE is_active = 1"
    );

    $c = DemoSeeder::resetAndRun($pdo);
    assert_same([], DemoSeeder::verify($pdo), 'Верификация проходит при активном английском языке без битых ссылок');

    $enMenuItems = (int) $pdo->query("SELECT COUNT(*) FROM menu_items WHERE lang = 'en'")->fetchColumn();
    assert_true($enMenuItems >= 20, 'Созданы пункты меню на английском языке');

    // Проверяем, что все пункты меню с url_type = page на английском имеют страницу
    $brokenEn = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM menu_items mi
         LEFT JOIN pages p
           ON mi.url_type = 'page'
          AND p.slug = IF(mi.url_value LIKE 'projects/%', SUBSTRING(mi.url_value, 10), mi.url_value)
          AND p.entity_type = IF(mi.url_value LIKE 'projects/%', 'project', 'page')
          AND p.lang = mi.lang
          AND p.status = 'published'
          AND p.deleted_at IS NULL
         WHERE mi.url_type = 'page' AND mi.lang = 'en' AND p.id IS NULL"
    )->fetchColumn();
    assert_same(0, $brokenEn, 'Все пункты меню на английском ссылаются на опубликованные страницы');

    // Восстанавливаем состояние языков
    $pdo->exec("UPDATE languages SET is_active = 0 WHERE code = 'en'");
});

test('DemoSeeder RESET с выборочными модулями верифицирует только выбранные разделы', function (): void {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    $c = DemoSeeder::resetAndRun($pdo, ['news', 'home']);
    assert_same([], DemoSeeder::verify($pdo, ['news', 'home']), 'Выборочный RESET не падает из-за отсутствия невыбранных разделов');

    // Возвращаем полный комплект
    DemoSeeder::resetAndRun($pdo);
});

