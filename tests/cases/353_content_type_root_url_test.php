<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\ContentType;
use App\Models\MenuItem;

/*
 * Адрес раздела каталога без префикса `/catalog`.
 *
 * Каталоги живут на `/catalog/<type>`, и это честная структура: по адресу
 * видно, что открыт раздел, а слаг типа не спорит с адресами страниц. Но для
 * раздела вроде «Документы» префикс лишний — его печатают на бланках.
 *
 * Настройка перевешивает адрес целиком, поэтому у неё три обязательства:
 * адрес считается в одном месте, прежний адрес продолжает работать, а занять
 * чужой корневой адрес нельзя — маршрут `/{slug}` обслуживает страницы, и тип
 * с занятым слагом просто никогда бы не открылся.
 */

test('Адрес раздела считается одним методом', function (): void {
    assert_same('catalog/documenty', ContentType::path(['slug' => 'documenty']));
    assert_same('documenty', ContentType::path(['slug' => 'documenty', 'root_url' => 1]));
    assert_same(
        'catalog/documenty/prikaz-1',
        ContentType::entryPath(['slug' => 'documenty'], 'prikaz-1')
    );
    assert_same(
        'documenty/prikaz-1',
        ContentType::entryPath(['slug' => 'documenty', 'root_url' => 1], 'prikaz-1')
    );

    // Второй склейки `'catalog/' . $slug` быть не должно: она молча разойдётся
    // с настройкой у одного из десятка мест, где адрес раздела печатается.
    $files = array_merge(
        glob(APP_ROOT . '/app/Views/site/*.php') ?: [],
        [APP_ROOT . '/app/Core/Search.php', APP_ROOT . '/app/Controllers/Site/OpenDataController.php'],
    );
    $strays = [];
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        if (str_contains($code, "'catalog/' . \$type['slug']") || str_contains($code, "'/catalog/' . \$slug")) {
            $strays[] = basename($file);
        }
    }
    assert_same([], $strays, 'адрес раздела собирается ContentType::path(): ' . implode(', ', $strays));
});

test('Занятый корневой адрес не отдаётся каталогу (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $suffix = bin2hex(random_bytes(4));
    $slug = 'catalog-root-' . $suffix;
    $pageId = 0;

    try {
        // Служебные разделы и языки заняты маршрутами, объявленными до
        // `/{slug}`: тип с таким слагом не открылся бы никогда.
        assert_true(ContentType::rootUrlConflict('news') !== '', 'раздел новостей занят');
        assert_true(ContentType::rootUrlConflict('admin') !== '', 'админка занята');
        assert_true(ContentType::rootUrlConflict('catalog') !== '', 'сам префикс занят');
        assert_true(ContentType::rootUrlConflict('uz') !== '', 'языковая версия занята');
        assert_same('', ContentType::rootUrlConflict($slug), 'свободный адрес разрешён');

        $pageId = \App\Models\Page::create([
            'title' => 'Страница',
            'slug' => $slug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'ru',
        ]);
        assert_true(
            ContentType::rootUrlConflict($slug) !== '',
            'страница занимает адрес первой — маршрут /{slug} ищет её'
        );
    } finally {
        if ($pageId > 0) {
            $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute([':id' => $pageId]);
        }
    }
});

test('Тип в корне находится только с включённой настройкой (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'catroot-' . bin2hex(random_bytes(4));
    $id = 0;

    try {
        $id = ContentType::create($slug, 'Документы', false, '', true, '', false);
        assert_same(null, ContentType::findRootBySlug($slug), 'пока префикс на месте, корень не наш');

        ContentType::update($id, 'Документы', false, '', true, '', true);
        $found = ContentType::findRootBySlug($slug);
        assert_true($found !== null, 'со снятым префиксом раздел отвечает в корне');
        assert_same($slug, ContentType::path((array) $found), 'адрес раздела — сам слаг, без префикса');

        // Скрытый раздел в корень не выходит: он не публичен вовсе.
        ContentType::update($id, 'Документы', false, '', false, '', true);
        assert_same(null, ContentType::findRootBySlug($slug), 'непубличный тип в корне не отвечает');
    } finally {
        if ($id > 0) {
            ContentType::delete($id);
        }
    }
});

test('Прежний адрес раздела остаётся рабочим — постоянным редиректом', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/ContentController.php');

    assert_contains('redirectToCanonical', $controller);
    assert_contains('301', $controller, 'старый адрес отвечает постоянным редиректом, а не 404');
    // Редирект нужен и списку, и записи: иначе ссылка на документ обрывалась бы.
    assert_same(
        2,
        substr_count($controller, '$this->redirectToCanonical('),
        'редирект обязан быть и у списка раздела, и у его записи'
    );
});

test('Маршрут записи в корне объявлен последним', function (): void {
    // `/{type}/{slug}` — шаблон из двух произвольных сегментов. Объявленный
    // выше, он перехватил бы `/news/...`, `/projects/...` и `/albums/...`.
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $rootShow = strpos($routes, "\$router->get('/{type}/{slug}'");
    assert_true($rootShow !== false, 'маршрут записи в корне объявлен');

    foreach (["'/news/{slug}'", "'/projects/{slug}'", "'/albums/{slug}'", "'/catalog/{type}'", "'/{slug}'"] as $earlier) {
        $at = strpos($routes, '$router->get(' . $earlier);
        assert_true($at !== false && $at < $rootShow, "маршрут {$earlier} обязан идти раньше");
    }
});

test('Переезд раздела уводит за собой пункты меню (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'catmenu-' . bin2hex(random_bytes(4));
    $ids = [];

    try {
        $ids[] = MenuItem::create([
            'title' => 'Документы',
            'url_type' => 'custom',
            'url_value' => '/catalog/' . $slug,
            'lang' => 'ru',
            'is_active' => 1,
        ]);
        // Чужой пункт не трогаем: сравнение точное, а не «начинается с».
        $ids[] = MenuItem::create([
            'title' => 'Другой раздел',
            'url_type' => 'custom',
            'url_value' => '/catalog/' . $slug . '-arxiv',
            'lang' => 'ru',
            'is_active' => 1,
        ]);

        $moved = MenuItem::retargetCustomUrl('catalog/' . $slug, $slug);
        assert_same(1, $moved, 'переписан ровно тот пункт, что вёл на раздел');

        $stmt = $pdo->prepare('SELECT url_value FROM menu_items WHERE id = :id');
        $stmt->execute([':id' => $ids[0]]);
        assert_same('/' . $slug, (string) $stmt->fetchColumn());
        $stmt->execute([':id' => $ids[1]]);
        assert_same('/catalog/' . $slug . '-arxiv', (string) $stmt->fetchColumn(), 'соседний раздел не тронут');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $id]);
        }
    }
});

test('Настройка доступна редактору и сохраняется', function (): void {
    $create = (string) file_get_contents(APP_ROOT . '/app/Views/admin/content_types/index.php');
    $edit = (string) file_get_contents(APP_ROOT . '/app/Views/admin/content_types/fields.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ContentTypeController.php');

    assert_contains('name="root_url"', $create, 'настройка есть при создании типа');
    assert_contains('name="root_url"', $edit, 'и при правке');
    assert_contains("\$_POST['root_url']", $controller, 'значение читается при сохранении');
    assert_contains('rootUrlConflict', $controller, 'занятый адрес отклоняется с объяснением');
});
