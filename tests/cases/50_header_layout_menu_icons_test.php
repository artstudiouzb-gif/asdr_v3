<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HeaderConfig;
use App\Core\SiteThemeCss;
use App\Models\MenuItem;

test('Разделители дизайна применяются между пунктами главного меню', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    assert_true(is_string($css));
    assert_contains('.site-menu > .site-menu__link:not(:first-child)::before', $css);
    assert_contains('var(--menu-divider-width, 1px)', $css);
    assert_contains('var(--menu-divider-height, 18px)', $css);
    assert_contains('var(--menu-divider-color, color-mix(in srgb, currentColor 35%, transparent))', $css);
});

test('HeaderConfig: выравнивание меню валидируется без устаревшего макета шапки', function () {
    $cfg = HeaderConfig::normalize(['layout' => 'drawer']);
    assert_false(array_key_exists('layout', $cfg), 'устаревший layout удаляется из конфигурации');
    foreach (['left', 'center', 'right'] as $alignment) {
        assert_same($alignment, HeaderConfig::normalize(['menu_position' => $alignment])['menu_position']);
    }
    assert_same('center', HeaderConfig::normalize(['menu_position' => 'unknown'])['menu_position']);
});

test('HeaderConfig: конструктор зон — мусор отброшен, дубли уникальны, разделитель повторяем', function () {
    $cfg = HeaderConfig::normalize(['elements' => [
        'left' => ['search', 'нечто', 'divider'],
        'center' => ['search', 'divider'],          // повторный search выкидывается, divider остаётся
        'right' => ['language', 'theme', 'a11y', 'language'],
    ]]);

    assert_same(['search', 'divider'], $cfg['elements']['left'], 'мусор убран, search+divider на месте');
    assert_same(['divider'], $cfg['elements']['center'], 'повторный search убран, divider повторяем');
    assert_same(['language', 'theme', 'a11y'], $cfg['elements']['right'], 'повторный language убран');

    // Пустой конфиг → дефолтная раскладка.
    $def = HeaderConfig::normalize([]);
    assert_same(['search', 'language', 'theme', 'a11y'], $def['elements']['right']);
});

test('HeaderConfig: мобильная раскладка независима от десктопной', function () {
    $cfg = HeaderConfig::normalize([
        'elements' => ['left' => ['logo'], 'center' => ['menu'], 'right' => ['search', 'language', 'theme', 'a11y']],
        'elements_mobile' => ['left' => ['logo'], 'center' => [], 'right' => ['search']],
    ]);
    assert_same(['logo'], $cfg['elements']['left'], 'десктоп-логотип сохранён');
    assert_same(['menu'], $cfg['elements']['center'], 'десктоп-меню сохранено');
    assert_same(['search', 'language', 'theme', 'a11y'], $cfg['elements']['right'], 'десктоп-набор сохранён');
    assert_same(['search'], $cfg['elements_mobile']['right'], 'мобильный набор отдельный');

    // Дефолт мобильного — компактнее (с logo, search, language).
    $def = HeaderConfig::normalize([]);
    assert_same(['logo'], $def['elements_mobile']['left']);
    assert_same(['search', 'language'], $def['elements_mobile']['right']);
});

test('HeaderConfig: логотип и меню можно свободно позиционировать в любых зонах', function () {
    $cfg = HeaderConfig::normalize([
        'topbar' => [
            'enabled' => true,
            'zones' => ['left' => ['logo'], 'center' => ['menu'], 'right' => ['phone', 'email']],
        ],
    ]);
    assert_same(['logo'], $cfg['topbar']['zones']['left'], 'логотип в topbar.left');
    assert_same(['menu'], $cfg['topbar']['zones']['center'], 'меню в topbar.center');
});

test('HeaderConfig: нормализует show_border и расширенные стили меню', function () {
    $cfg = HeaderConfig::normalize([
        'container_mode' => 'floating',
        'topbar' => ['enabled' => true, 'show_border' => true],
        'styles' => [
            'nav_font_size' => 'compact',
            'nav_transform' => 'capitalize',
            'nav_letter_spacing' => 'wide',
            'nav_style_type' => 'dot',
            'nav_padding' => 'compact',
            'nav_icon_pos' => 'top',
            'nav_gap' => 30,
            'nav_overflow' => 'wrap',
            'nav_item_dividers' => true,
            'nav_divider_color' => '#CBD5E1',
            'nav_divider_color_transparent' => '#FFFFFF',
            'nav_divider_width' => 2,
            'nav_divider_height' => 24,
            'nav_pill_bg' => '#2563EB',
        ],
    ]);
    assert_same('floating', $cfg['container_mode'], 'container_mode = floating');
    assert_true($cfg['topbar']['show_border'], 'topbar.show_border включён');
    assert_same('compact', $cfg['styles']['nav_font_size']);
    assert_same('capitalize', $cfg['styles']['nav_transform']);
    assert_same('wide', $cfg['styles']['nav_letter_spacing']);
    assert_same('dot', $cfg['styles']['nav_style_type']);
    assert_same('compact', $cfg['styles']['nav_padding']);
    assert_same('top', $cfg['styles']['nav_icon_pos']);
    assert_same(30, $cfg['styles']['nav_gap']);
    assert_same('wrap', $cfg['styles']['nav_overflow']);
    assert_true($cfg['styles']['nav_item_dividers']);
    assert_same('#cbd5e1', $cfg['styles']['nav_divider_color']);
    assert_same('#ffffff', $cfg['styles']['nav_divider_color_transparent']);
    assert_same(2, $cfg['styles']['nav_divider_width']);
    assert_same(24, $cfg['styles']['nav_divider_height']);
    assert_same('#2563eb', $cfg['styles']['nav_pill_bg']);
});

test('Дизайн шапки показывает настройки gap, divider и переполнения меню', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/header/index.php');
    assert_true(is_string($view));
    foreach ([
        'menu_position',
        'styles_nav_gap',
        'styles_nav_overflow',
        'styles_nav_item_dividers',
        'styles_nav_divider_width',
        'styles_nav_divider_height',
        'styles_nav_divider_color',
        'styles_nav_divider_color_transparent',
    ] as $field) {
        assert_contains($field, $view);
    }
});

test('Выравнивание меню применяется к меню в любой desktop-зоне', function (): void {
    $template = file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    assert_true(is_string($template) && is_string($css));

    assert_contains("' site-menu--align-' . \$menuAlignment", $template);
    assert_contains('$navAlign = $menuAlignment', $template);
    foreach (['left', 'center', 'right'] as $alignment) {
        assert_contains('.site-header .site-menu.site-menu--align-' . $alignment, $css);
        assert_contains('.site-topbar .site-menu.site-menu--align-' . $alignment, $css);
    }
});

test('Форма шапки не теряет настройки, которые сохраняет контроллер', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/header/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/HeaderController.php');

    foreach ([
        "'elements_mobile'",
        'name="topbar_style"',
        'name="topbar_mobile"',
        'name="middlebar_bg"',
        'name="middlebar_bg_use"',
        'name="bottombar_bg"',
        'name="bottombar_bg_use"',
        'name="header_shadow"',
        'name="header_shadow_size"',
        'name="styles_nav_pill_bg"',
        'name="styles_nav_pill_bg_use"',
        'name="styles_border_color"',
        'name="styles_border_color_use"',
        'name="styles_border_width"',
        'name="social[',
    ] as $field) {
        assert_contains($field, $view, "поле {$field} присутствует в форме");
    }
    assert_not_contains('name="layout"', $view, 'устаревший выбор макета удалён из формы');
    assert_not_contains("\$_POST['layout']", $controller, 'контроллер больше не сохраняет устаревший макет');
});

test('Миграция удаляет сохранённый layout из конфигурации шапки', function (): void {
    $migrationName = '2026_07_30_remove_header_layout.sql';
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/' . $migrationName);
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');

    assert_contains("JSON_REMOVE(`value`, '$.layout')", $migration);
    assert_contains("`key` = 'header_config'", $migration);
    assert_contains($migrationName, $schema, 'чистая установка отмечает миграцию применённой');
});

test('Конструктор шапки разделяет desktop и mobile и доступен с клавиатуры', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/header/index.php');
    $adminScript = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('data-hdr-tab="desktop"', $view);
    assert_contains('data-hdr-tab="mobile"', $view);
    assert_contains('data-hdr-panel="mobile"', $view);
    assert_contains('role="tablist"', $view);
    assert_contains('aria-selected="true"', $view);
    assert_contains("event.key !== 'ArrowLeft'", $view);
    assert_contains("t.setAttribute('aria-selected'", $adminScript);
});

test('HeaderConfig ограничивает опасные размеры меню', function (): void {
    $cfg = HeaderConfig::normalize(['styles' => [
        'nav_gap' => '999',
        'nav_overflow' => 'unknown',
        'nav_divider_width' => '0',
        'nav_divider_height' => '999',
        'nav_divider_color' => 'red',
    ]]);
    assert_same(64, $cfg['styles']['nav_gap']);
    assert_same('adaptive', $cfg['styles']['nav_overflow']);
    assert_same(1, $cfg['styles']['nav_divider_width']);
    assert_same(64, $cfg['styles']['nav_divider_height']);
    assert_same('', $cfg['styles']['nav_divider_color']);
});

test('CSS шапки получает точные переменные gap и divider', function (): void {
    ensure_test_db();
    $css = SiteThemeCss::build([], HeaderConfig::normalize([
        'styles' => [
            'nav_gap' => 26,
            'nav_divider_color' => '#334155',
            'nav_divider_color_transparent' => '#F8FAFC',
            'nav_divider_width' => 3,
            'nav_divider_height' => 28,
        ],
    ]), false);
    assert_contains('--menu-gap:26px', $css);
    assert_contains('--menu-divider-width:3px', $css);
    assert_contains('--menu-divider-height:28px', $css);
    assert_contains('--menu-divider-color:#334155', $css);
    assert_contains('--menu-divider-color-transparent:#f8fafc', $css);
});

test('Длинное desktop-меню переносит только лишние пункты в меню «Ещё»', function (): void {
    $template = file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    $script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    assert_true(is_string($template) && is_string($script) && is_string($css));
    assert_contains('data-header-menu-adaptive', $template);
    assert_contains('data-priority-menu', $template);
    assert_contains('data-priority-overflow-panel', $template);
    assert_contains('fitMenu', $script);
    assert_contains('menu.insertBefore', $script);
    assert_contains('.site-menu__overflow-toggle', $css);
    assert_true(!str_contains($script, 'site-header--menu-collapsed'), 'desktop-меню не должно скрываться целиком');
    assert_true(!str_contains($css, '.site-header--menu-collapsed'), 'desktop-бургер не заменяет всё меню');
});

test('MenuItem: хранит только ключ Tabler-иконки, разделитель сохраняется (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM menu_items');

    // Пользовательская SVG-разметка больше не хранится.
    $dirtyIcon = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M3 3h18"/></svg>';
    $id = MenuItem::create([
        'title' => 'Раздел', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/x',
        'is_active' => 1, 'icon_svg' => $dirtyIcon,
    ]);
    $row = MenuItem::findById($id);
    assert_same(null, $row['icon_svg'], 'инлайновая SVG-разметка отклонена');

    // Не-SVG в поле иконки → null.
    $id2 = MenuItem::create([
        'title' => 'Без иконки', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/y',
        'is_active' => 1, 'icon_svg' => 'просто текст',
    ]);
    assert_same(null, MenuItem::findById($id2)['icon_svg']);

    // Ключ встроенной AdminUI-иконки хранится компактно и не теряется.
    $idBuiltIn = MenuItem::create([
        'title' => 'Встроенная иконка', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/icon',
        'is_active' => 1, 'icon_svg' => 'home',
    ]);
    assert_same('home', MenuItem::findById($idBuiltIn)['icon_svg']);

    // Разделитель.
    $id3 = MenuItem::create([
        'title' => '—', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => null,
        'is_active' => 1, 'is_divider' => true,
    ]);
    assert_same(1, (int) MenuItem::findById($id3)['is_divider'], 'разделитель сохранён');

    // Обновление снимает разделитель и меняет иконку, а также добавляет плашку.
    MenuItem::update($id3, [
        'title' => 'Теперь ссылка', 'lang' => 'ru', 'url_type' => 'custom', 'url_value' => '/z',
        'is_active' => 1, 'is_divider' => false, 'icon_svg' => 'circle',
        'badge_text' => ' АКТУАЛЬНО! ', 'badge_color' => 'red', 'badge_pos' => 'right',
    ]);
    $upd = MenuItem::findById($id3);
    assert_same(0, (int) $upd['is_divider']);
    assert_same('АКТУАЛЬНО!', $upd['badge_text'], 'текст бейджа очищен и сохранён');
    assert_same('red', $upd['badge_color'], 'цвет бейджа сохранён');
    assert_same('right', $upd['badge_pos'], 'позиция бейджа сохранена');
    assert_same('circle', $upd['icon_svg']);
    $pdo->exec('DELETE FROM menu_items');
});
