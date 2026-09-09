<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Locale;
use App\Models\News;
use App\Models\Page;

// Roadmap 1.2: переключатель языков и hreflang показывают только языки,
// на которых сущность реально наполнена.

test('Locale::contentLangs: null по умолчанию, set/get, дедупликация', function () {
    Locale::setContentLangs(null);
    assert_true(Locale::contentLangs() === null, 'общий маршрут — без ограничений');
    Locale::setContentLangs(['ru', 'uz', 'ru']);
    assert_same(['ru', 'uz'], Locale::contentLangs());
    Locale::setContentLangs(null);
});

test('Page::availableLangs: дефолтный язык + перевод + свой стек блоков', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status) VALUES ('Тест-языки', 'test-avail-langs', 'published')");
    $pageId = (int) $pdo->lastInsertId();

    // Без переводов — только язык по умолчанию.
    $langs = Page::availableLangs($pageId);
    assert_same([\App\Models\Language::defaultCode()], $langs);

    // Перевод с пустым title не считается наполненным.
    $pdo->prepare('INSERT INTO page_translations (page_id, lang, title) VALUES (?, ?, ?)')
        ->execute([$pageId, 'uz', '  ']);
    assert_true(!in_array('uz', Page::availableLangs($pageId), true), 'пустой перевод не в счёт');

    // Заголовок появился — язык доступен.
    $pdo->prepare('UPDATE page_translations SET title = ? WHERE page_id = ? AND lang = ?')
        ->execute(['Sahifa', $pageId, 'uz']);
    assert_true(in_array('uz', Page::availableLangs($pageId), true));

    // Свой стек блоков тоже делает язык доступным (без строки перевода).
    $pdo->prepare("INSERT INTO blocks (page_id, lang, type, data, sort_order) VALUES (?, 'en', 'text', '{}', 1)")
        ->execute([$pageId]);
    assert_true(in_array('en', Page::availableLangs($pageId), true), 'язык со стеком блоков доступен');

    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});

test('Page::availableLangsForIds: батч без N+1 — перевод и стек блоков', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = \App\Models\Language::defaultCode();

    $pdo->exec("INSERT INTO pages (title, slug, status) VALUES ('Батч-1', 'batch-langs-1', 'published')");
    $p1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pages (title, slug, status) VALUES ('Батч-2', 'batch-langs-2', 'published')");
    $p2 = (int) $pdo->lastInsertId();

    // p1: узбекский перевод; p2: свой стек блоков на en; пустой перевод не в счёт.
    $pdo->prepare('INSERT INTO page_translations (page_id, lang, title) VALUES (?, ?, ?)')
        ->execute([$p1, 'uz', 'Sahifa']);
    $pdo->prepare('INSERT INTO page_translations (page_id, lang, title) VALUES (?, ?, ?)')
        ->execute([$p2, 'uz', '  ']);
    $pdo->prepare("INSERT INTO blocks (page_id, lang, type, data, sort_order) VALUES (?, 'en', 'text', '{}', 1)")
        ->execute([$p2]);

    $map = Page::availableLangsForIds([$p1, $p2]);
    assert_true(in_array($default, $map[$p1], true) && in_array('uz', $map[$p1], true), 'p1: дефолт + узбекский');
    assert_true(!in_array('en', $map[$p1], true), 'p1: чужих языков нет');
    assert_true(in_array('en', $map[$p2], true), 'p2: язык со стеком блоков');
    assert_true(!in_array('uz', $map[$p2], true), 'p2: пустой перевод не в счёт');

    // Неизвестный id получает хотя бы дефолтный язык, без падения.
    $missing = Page::availableLangsForIds([999999]);
    assert_same([$default], $missing[999999]);

    $pdo->exec("DELETE FROM pages WHERE id IN ({$p1}, {$p2})");
});

test('Вью списка страниц: колонка «Языки» и батч-запрос языков', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/index.php');
    assert_contains('<th>Языки</th>', $view);
    assert_contains('availableLangsForIds', $view);
    assert_contains('admin/layout/lang_badges', $view);
});

test('Проекты: языки записи считаются по группе переводов (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = \App\Models\Language::defaultCode();

    $pdo->exec("INSERT INTO pages (title, slug, entity_type, `lead`, status, lang) VALUES ('Проект RU', 'proj-langs-1', 'project', 'Описание RU', 'published', '{$default}')");
    $p1 = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE pages SET translation_group_id = {$p1} WHERE id = {$p1}");
    $pdo->exec("INSERT INTO pages (title, slug, entity_type, `lead`, status, lang) VALUES ('Без перевода', 'proj-langs-2', 'project', 'Только RU', 'published', '{$default}')");
    $p2 = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE pages SET translation_group_id = {$p2} WHERE id = {$p2}");

    // Узбекская версия — отдельная запись той же группы с тем же адресом.
    $pdo->exec("INSERT INTO pages (title, slug, entity_type, `lead`, status, lang, translation_group_id) VALUES ('Loyiha UZ', 'proj-langs-1', 'project', 'UZ anons', 'published', 'uz', {$p1})");
    $uz = (int) $pdo->lastInsertId();

    $map = \App\Models\Project::availableLangsForIds([$p1, $p2]);
    assert_true(in_array('uz', $map[$p1], true), 'p1: узбекский доступен');
    assert_same([$default], $map[$p2], 'p2: только основной');

    $row = \App\Models\Project::findPublishedBySlug('proj-langs-1', 'uz');
    assert_same('Loyiha UZ', $row['title']);
    assert_same('UZ anons', $row['description'], 'анонс берётся у записи своего языка');

    $base = \App\Models\Project::findPublishedBySlug('proj-langs-1', $default);
    assert_same('Проект RU', $base['title']);

    $pdo->exec("DELETE FROM pages WHERE id IN ({$p1}, {$p2}, {$uz})");
});

test('Вью списка проектов: колонка «Языки» и батч-запрос языков', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/projects/index.php');
    assert_contains('<th>Языки</th>', $view);
    assert_contains('availableLangsForIds', $view);
    assert_contains('admin/layout/lang_badges', $view);
});

test('TeamMember::availableLangsForIds + localize: перевод имени/должности с фолбэком (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = \App\Models\Language::defaultCode();

    $pdo->exec("INSERT INTO team_members (name, position, status) VALUES ('Элёр Ганиев', 'Директор', 'published')");
    $m1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO team_members (name, position, status) VALUES ('Без перевода', 'Сотрудник', 'published')");
    $m2 = (int) $pdo->lastInsertId();

    // m1: узбекский перевод только имени (должность пустая → фолбэк).
    \App\Models\TeamMemberTranslation::upsert($m1, 'uz', ['name' => 'Elyor Ganiev', 'position' => '']);

    $map = \App\Models\TeamMember::availableLangsForIds([$m1, $m2]);
    assert_true(in_array($default, $map[$m1], true), 'm1: основной язык не теряется при наличии перевода');
    assert_true(in_array('uz', $map[$m1], true), 'm1: узбекский доступен');
    assert_same([$default], $map[$m2], 'm2: только основной');

    // Локализация списка: имя из перевода, должность — фолбэк к основному.
    $rows = \App\Models\TeamMember::published('uz');
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    assert_same('Elyor Ganiev', $byId[$m1]['name']);
    assert_same('Директор', $byId[$m1]['position'], 'пустой перевод должности откатывается к основному');
    assert_true(!isset($byId[$m2]), 'm2 без перевода не попадает в список другого языка');

    $pdo->exec("DELETE FROM team_members WHERE id IN ({$m1}, {$m2})");
});

test('Вью списка команды: колонка «Языки» и батч-запрос языков', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/team/index.php');
    assert_contains('<th>Языки</th>', $view);
    assert_contains('availableLangsForIds', $view);
    assert_contains('admin/layout/lang_badges', $view);
});

test('PhotoAlbum::availableLangsForIds + localize: перевод с фолбэком (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = \App\Models\Language::defaultCode();

    $pdo->exec("INSERT INTO photo_albums (title, slug, description, is_published) VALUES ('Альбом RU', 'alb-langs-1', 'Описание RU', 1)");
    $a1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO photo_albums (title, slug, description, is_published) VALUES ('Без перевода', 'alb-langs-2', 'Только RU', 1)");
    $a2 = (int) $pdo->lastInsertId();

    \App\Models\PhotoAlbumTranslation::upsert($a1, 'uz', ['title' => 'Albom UZ', 'description' => '']);

    $map = \App\Models\PhotoAlbum::availableLangsForIds([$a1, $a2]);
    assert_true(in_array($default, $map[$a1], true), 'a1: основной язык не теряется при наличии перевода');
    assert_true(in_array('uz', $map[$a1], true), 'a1: узбекский доступен');
    assert_same([$default], $map[$a2], 'a2: только основной');

    $row = \App\Models\PhotoAlbum::findPublishedBySlug('alb-langs-1', 'uz');
    assert_same('Albom UZ', $row['title']);
    assert_same('Описание RU', $row['description'], 'пустой перевод описания откатывается к основному');

    $pdo->exec("DELETE FROM photo_albums WHERE id IN ({$a1}, {$a2})");
});

test('Video::availableLangsForIds + localize: перевод с фолбэком (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = \App\Models\Language::defaultCode();

    $pdo->exec("INSERT INTO videos (title, slug, description, is_published) VALUES ('Видео RU', 'vid-langs-1', 'Описание RU', 1)");
    $v1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO videos (title, slug, description, is_published) VALUES ('Без перевода', 'vid-langs-2', 'Только RU', 1)");
    $v2 = (int) $pdo->lastInsertId();

    \App\Models\VideoTranslation::upsert($v1, 'uz', ['title' => 'Video UZ', 'description' => '']);

    $map = \App\Models\Video::availableLangsForIds([$v1, $v2]);
    assert_true(in_array($default, $map[$v1], true), 'v1: основной язык не теряется при наличии перевода');
    assert_true(in_array('uz', $map[$v1], true), 'v1: узбекский доступен');
    assert_same([$default], $map[$v2], 'v2: только основной');

    // Локализация списка: заголовок из перевода, описание — фолбэк.
    $rows = \App\Models\Video::all(true, 'uz');
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    assert_same('Video UZ', $byId[$v1]['title']);
    assert_same('Описание RU', $byId[$v1]['description'], 'пустой перевод описания откатывается к основному');

    $pdo->exec("DELETE FROM videos WHERE id IN ({$v1}, {$v2})");
});

test('Вью списков альбомов и видео: колонка «Языки» и батч-запрос языков', function () {
    foreach (['albums', 'videos'] as $section) {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . "/app/Views/admin/{$section}/index.php");
        assert_contains('<th>Языки</th>', $view);
        assert_contains('availableLangsForIds', $view);
        assert_contains('admin/layout/lang_badges', $view);
        assert_contains("'translationEditUrl'", $view);

        $form = (string) file_get_contents(dirname(__DIR__, 2) . "/app/Views/admin/{$section}/form.php");
        assert_contains('id="translations"', $form);
        assert_contains('translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][title]', $form);
        assert_contains('translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][description]', $form);
    }
});

test('News::availableLangs: перевод заголовка или текста', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO news (title, slug, status, published_at) VALUES ('Тест', 'test-avail-news', 'published', NOW())");
    $newsId = (int) $pdo->lastInsertId();

    assert_same([\App\Models\Language::defaultCode()], News::availableLangs($newsId));

    $pdo->prepare('INSERT INTO news_translations (news_id, lang, title, content) VALUES (?, ?, ?, ?)')
        ->execute([$newsId, 'en', '', 'Translated body']);
    assert_true(in_array('en', News::availableLangs($newsId), true), 'перевод текста достаточен');

    $pdo->exec("DELETE FROM news WHERE id = {$newsId}");
});
