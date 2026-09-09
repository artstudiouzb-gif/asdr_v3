<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\TranslationGroupHelper;
use App\Models\MenuItem;
use App\Models\Page;

test('Меню языка использует только свои пункты и запись страницы своего языка', function (): void {
    ensure_test_db();
    TranslationGroupHelper::ensureSchema();

    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM menu_items');
    $suffix = bin2hex(random_bytes(4));
    $ruId = 0;
    $uzId = 0;

    try {
        $ruSlug = 'menu-contact-ru-' . $suffix;
        $uzSlug = 'menu-contact-uz-' . $suffix;
        $ruId = Page::create([
            'title' => 'Контакты',
            'slug' => $ruSlug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'ru',
        ]);
        $uzId = TranslationGroupHelper::createTranslation('pages', $ruId, 'uz');
        Page::update($uzId, [
            'title' => 'Aloqa',
            'slug' => $uzSlug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'uz',
        ]);

        $ruMenuId = MenuItem::create([
            'title' => 'Контакты',
            'url_type' => 'page',
            'url_value' => $ruSlug,
            'lang' => 'ru',
            'is_active' => 1,
        ]);
        $pdo->prepare(
            "INSERT INTO menu_items (lang, title, url_type, url_value, sort_order, is_active)
             VALUES ('', 'Старый общий пункт', 'custom', '/legacy-shared', 99, 1)"
        )->execute();
        $legacyMenuId = (int) $pdo->lastInsertId();

        assert_same([], MenuItem::activeForLang('uz'), 'пустое UZ-меню не откатывается к RU и общим пунктам');

        $uzMenuId = MenuItem::create([
            'title' => 'Aloqa',
            'url_type' => 'page',
            // Старое/скопированное значение автоматически сопоставится с UZ-записью.
            'url_value' => $ruSlug,
            'lang' => 'uz',
            'is_active' => 1,
        ]);
        $uzMenu = MenuItem::activeForLang('uz');
        assert_same(1, count($uzMenu), 'UZ-меню не подмешивает RU и старые общие пункты');
        assert_same($uzMenuId, (int) $uzMenu[0]['id']);
        assert_same($uzSlug, (string) $uzMenu[0]['url_value'], 'ссылка нормализована на slug UZ-записи');
        assert_same('/uz/' . $uzSlug, MenuItem::resolveUrl($uzMenu[0], 'uz'));

        $ruMenu = MenuItem::activeForLang('ru');
        assert_same(1, count($ruMenu), 'RU-меню содержит только RU-пункт');
        assert_same($ruMenuId, (int) $ruMenu[0]['id']);
        assert_same('/' . $ruSlug, MenuItem::resolveUrl($ruMenu[0], 'ru'));

        $migration = (string) file_get_contents(
            APP_ROOT . '/database/migrations/2026_07_26_menu_language_isolation.sql'
        );
        $pdo->exec($migration);
        assert_same(
            'ru',
            (string) MenuItem::findById($legacyMenuId)['lang'],
            'миграция закрепляет старый общий пункт за основным языком'
        );

        assert_same($uzId, (int) Page::findBySlug($uzSlug, 'uz')['id']);
    } finally {
        $pdo->exec('DELETE FROM menu_items');
        if ($uzId > 0) {
            Page::forceDelete($uzId);
        }
        if ($ruId > 0) {
            Page::forceDelete($ruId);
        }
    }
});

test('Синхронизация меню сохраняет переведённые названия и связывает страницы целевого языка', function (): void {
    ensure_test_db();
    TranslationGroupHelper::ensureSchema();

    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM menu_items');
    $suffix = bin2hex(random_bytes(4));
    $ruId = 0;
    $uzId = 0;
    $missingRuId = 0;

    try {
        $ruSlug = 'sync-page-ru-' . $suffix;
        $uzSlug = 'sync-page-uz-' . $suffix;
        $ruId = Page::create([
            'title' => 'Контакты',
            'slug' => $ruSlug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'ru',
        ]);
        $uzId = TranslationGroupHelper::createTranslation('pages', $ruId, 'uz');
        Page::update($uzId, [
            'title' => 'Aloqa',
            'slug' => $uzSlug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'uz',
        ]);
        $missingRuId = Page::create([
            'title' => 'Только по-русски',
            'slug' => 'sync-missing-' . $suffix,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'ru',
        ]);

        $catalogId = MenuItem::create([
            'title' => 'Каталог',
            'lang' => 'ru',
            'url_type' => 'custom',
            'url_value' => '/catalog',
            'icon_svg' => 'home',
            'hide_title' => 1,
            'badge_text' => 'TOP',
            'badge_pos' => 'left',
            'is_active' => 1,
        ]);
        MenuItem::create([
            'title' => 'Контакты',
            'lang' => 'ru',
            'url_type' => 'page',
            'url_value' => $ruSlug,
            'parent_id' => $catalogId,
            'is_active' => 1,
        ]);
        MenuItem::create([
            'title' => 'Только RU',
            'lang' => 'ru',
            'url_type' => 'page',
            'url_value' => 'sync-missing-' . $suffix,
            'is_active' => 1,
        ]);
        MenuItem::create([
            'title' => 'Новости',
            'lang' => 'ru',
            'url_type' => 'news_index',
            'url_value' => null,
            'is_active' => 1,
        ]);

        // Совпадающее название целевого меню должно пережить замену структуры.
        MenuItem::create([
            'title' => 'Katalog',
            'lang' => 'uz',
            'url_type' => 'custom',
            'url_value' => '/uz/catalog',
            'is_active' => 1,
        ]);
        MenuItem::create([
            'title' => 'Устаревший пункт',
            'lang' => 'uz',
            'url_type' => 'custom',
            'url_value' => '/obsolete',
            'is_active' => 1,
        ]);

        $result = MenuItem::synchronizeLanguage('ru', 'uz');
        assert_same(3, $result['created'], 'созданы раздел, переведённая страница и новости');
        assert_same(1, $result['skipped'], 'пункт без UZ-страницы пропущен');
        assert_same(2, $result['replaced'], 'старое UZ-меню заменено');

        $uzTree = MenuItem::activeForLang('uz');
        assert_same(2, count($uzTree), 'в UZ-меню два пункта верхнего уровня');
        assert_same('Katalog', (string) $uzTree[0]['title'], 'существующий UZ-заголовок сохранён');
        assert_same('/uz/catalog', (string) $uzTree[0]['url_value'], 'внутренний раздел сохранён в UZ-локали');
        assert_same('home', (string) $uzTree[0]['icon_svg'], 'встроенная иконка синхронизирована');
        assert_same(1, (int) $uzTree[0]['hide_title'], 'режим «только иконка» синхронизирован');
        assert_same('left', (string) $uzTree[0]['badge_pos'], 'позиция бейджа синхронизирована');
        assert_same('/uz/catalog', MenuItem::resolveUrl($uzTree[0], 'uz'));
        assert_same(1, count($uzTree[0]['children']), 'вложенность синхронизирована');
        assert_same('Aloqa', (string) $uzTree[0]['children'][0]['title'], 'взято название UZ-страницы');
        assert_same($uzSlug, (string) $uzTree[0]['children'][0]['url_value']);
        assert_same('/uz/' . $uzSlug, MenuItem::resolveUrl($uzTree[0]['children'][0], 'uz'));
        assert_same('Yangiliklar', (string) $uzTree[1]['title'], 'известный раздел локализован');
        assert_same('/uz/news', MenuItem::resolveUrl($uzTree[1], 'uz'));

        $ruCount = (int) $pdo->query("SELECT COUNT(*) FROM menu_items WHERE lang = 'ru'")->fetchColumn();
        assert_same(4, $ruCount, 'исходное RU-меню не изменилось');
    } finally {
        $pdo->exec('DELETE FROM menu_items');
        if ($uzId > 0) {
            Page::forceDelete($uzId);
        }
        if ($missingRuId > 0) {
            Page::forceDelete($missingRuId);
        }
        if ($ruId > 0) {
            Page::forceDelete($ruId);
        }
    }
});
