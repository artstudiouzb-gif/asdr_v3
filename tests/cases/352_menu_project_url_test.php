<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Project;

/*
 * Пункт меню, ведущий на проект.
 *
 * Публичный адрес проекта — `/projects/<slug>`, у страницы — `/<slug>`.
 * Пункт меню хранит адрес целиком (`Page::menuTargetValue`), и форма админки
 * так его и записывала, а вот ссылку в шапке собирал `MenuItem::pageUrl` —
 * из одного слага, минуя этот метод. Получался `/<slug>`, то есть 404 на
 * каждом пункте-проекте. Второе место с той же ошибкой — синхронизация меню
 * между языками: она записывала цели голый слаг, и у неосновного языка пункт
 * не разрешался вовсе и молча пропадал из шапки.
 *
 * Отсюда правило: адрес цели собирает `Page::menuTargetValue()`. Второй
 * склейки `'/' . $slug` для пунктов меню быть не должно.
 */

test('Ссылка пункта меню на проект ведёт на /projects/<slug> (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $suffix = bin2hex(random_bytes(4));
    $projectSlug = 'menu-project-' . $suffix;
    $pageSlug = 'menu-page-' . $suffix;
    $projectId = 0;
    $pageId = 0;
    $menuIds = [];

    try {
        $projectId = Project::create([
            'title' => 'Проект',
            'slug' => $projectSlug,
            'description' => '',
            'cover_image' => null,
            'status' => 'published',
            'lang' => 'ru',
        ]);
        $pageId = Page::create([
            'title' => 'Страница',
            'slug' => $pageSlug,
            'meta_title' => null,
            'meta_description' => null,
            'status' => 'published',
            'is_home' => 0,
            'lang' => 'ru',
        ]);
        Page::forgetMenuTargets();

        $menuIds[] = MenuItem::create([
            'title' => 'Проект',
            'url_type' => 'page',
            'url_value' => 'projects/' . $projectSlug,
            'lang' => 'ru',
            'is_active' => 1,
        ]);
        $menuIds[] = MenuItem::create([
            'title' => 'Страница',
            'url_type' => 'page',
            'url_value' => $pageSlug,
            'lang' => 'ru',
            'is_active' => 1,
        ]);

        $projectUrl = MenuItem::resolveUrl(
            ['url_type' => 'page', 'url_value' => 'projects/' . $projectSlug],
            'ru'
        );
        assert_same('/projects/' . $projectSlug, $projectUrl, 'проект адресуется с префиксом');

        // Страница осталась прежней: префикс добавляется по типу записи, а не
        // всем подряд.
        $pageUrl = MenuItem::resolveUrl(['url_type' => 'page', 'url_value' => $pageSlug], 'ru');
        assert_same('/' . $pageSlug, $pageUrl);

        // Пункт с проектом попадает в дерево меню, а не отбрасывается как
        // неразрешимая цель.
        $tree = MenuItem::activeForLang('ru');
        $values = [];
        $collect = static function (array $items) use (&$collect, &$values): void {
            foreach ($items as $item) {
                $values[] = (string) ($item['url_value'] ?? '');
                $collect($item['children'] ?? []);
            }
        };
        $collect($tree);
        assert_true(
            in_array('projects/' . $projectSlug, $values, true),
            'пункт-проект остаётся в меню с полным адресом'
        );
    } finally {
        foreach ($menuIds as $menuId) {
            $pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $menuId]);
        }
        foreach ([$projectId, $pageId] as $id) {
            if ($id > 0) {
                $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute([':id' => $id]);
            }
        }
        Page::forgetMenuTargets();
    }
});

test('Адрес цели пункта меню собирается одним методом', function (): void {
    // Вторая склейка «слэш плюс слаг» — это и есть та копия, которая молча
    // разошлась: знание о префиксе проекта живёт в menuTargetValue().
    $menu = (string) file_get_contents(APP_ROOT . '/app/Models/MenuItem.php');

    assert_not_contains(
        "'/' . (string) \$page['slug']",
        $menu,
        'адрес цели собирает Page::menuTargetValue(), а не голый слаг'
    );
    assert_not_contains(
        "\$source['url_value'] = (string) \$page['slug'];",
        $menu,
        'синхронизация языков тоже сохраняет полный адрес цели'
    );
    assert_contains('Page::menuTargetValue($page)', $menu);
});

test('Миграция чинит пункты меню, потерявшие префикс проекта', function (): void {
    $sql = (string) file_get_contents(
        APP_ROOT . '/database/migrations/2026_09_08_menu_project_prefix.sql'
    );

    assert_contains('-- @post-schema', $sql);
    assert_contains("CONCAT('projects/', mi.url_value)", $sql);
    // Пункт, ведущий на настоящую страницу, трогать нельзя: у неё тот же слаг
    // может существовать законно.
    assert_contains("p.entity_type = 'page'", $sql);
    assert_contains("mi.url_value NOT LIKE 'projects/%'", $sql);
});
