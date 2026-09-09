<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Поиск по схеме оргструктуры и готовые ссылки на состав сектора.

test('Поиск по схеме включается настройкой и приходит скрытым', function () {
    $on = BlockRenderer::render([
        'id' => 780,
        'type' => 'org_structure',
        'data' => json_encode([
            'head_title' => 'Директор',
            'search' => true,
            'branches' => [['title' => 'Заместитель', 'units' => "Департамент\n  Отдел"]],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    $html = (string) $on['html'];

    assert_contains('data-org-structure', $html);
    assert_contains('data-org-search', $html);
    // Без скрипта фильтровать нечем — поле открывает JS.
    assert_contains('hidden', $html);
    assert_contains('data-org-search-status', $html);

    $off = BlockRenderer::render([
        'id' => 781,
        'type' => 'org_structure',
        'data' => json_encode(['head_title' => 'Директор'], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    assert_not_contains('orgstruct-search', (string) $off['html']);
    assert_not_contains('data-org-structure', (string) $off['html']);
});

test('Скрипт схемы подключается по типу блока и ищет по собственному тексту узла', function () {
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    assert_contains("'org_structure' => '/assets/js/blocks/org_structure.js'", $collector);

    // Проверяем результат сборки, а не строку в её исходнике: список блочных
    // ассетов выводится из каталога, поэтому имени файла в скрипте нет — и
    // манифест отвечает на настоящий вопрос «файл собран?», а не на «упомянут?».
    $manifest = json_decode((string) file_get_contents(APP_ROOT . '/public/assets/asset-manifest.json'), true);
    $entry = $manifest['blocks']['/assets/js/blocks/org_structure.js'] ?? null;
    assert_true(is_array($entry), 'скрипт должен попадать в сборку');
    assert_true(
        is_file(APP_ROOT . '/public' . (string) ($entry['path'] ?? '')),
        'собранный файл лежит на диске'
    );

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/org_structure.js');
    // Текст родителя включает вложенные списки: без отсечения департамент
    // «совпадал» с каждым своим сектором.
    assert_contains('ownText', $js);
    assert_contains('is-match-path', $js);
});

test('Ссылки на состав сектора собираются по данным команды (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $pageId = \App\Models\Page::create([
        'title' => 'Команда', 'slug' => 'team-anchor-' . $suffix,
        'status' => 'published', 'lang' => 'ru',
    ]);
    $pdo->prepare('INSERT INTO blocks (page_id, type, data, sort_order, is_active, lang) VALUES (?,?,?,1,1,?)')
        ->execute([$pageId, 'team_list', json_encode(['group_by_department' => true], JSON_UNESCAPED_UNICODE), 'ru']);

    $memberId = \App\Models\TeamMember::create([
        'name' => 'Иванов И.', 'position' => 'Начальник',
        'department' => 'Сектор проверки ' . $suffix,
        'status' => 'published', 'lang' => 'ru',
    ]);

    $options = \App\Core\TeamAnchors::options();
    $found = null;
    foreach ($options as $option) {
        if (str_contains((string) $option['name'], $suffix)) {
            $found = $option;
            break;
        }
    }

    assert_true($found !== null, 'сектор сотрудника попадает в список');
    assert_same('/team-anchor-' . $suffix . '#team-' . $found['slug'], (string) $found['path']);

    $pdo->exec('DELETE FROM team_members WHERE id = ' . (int) $memberId);
    $pdo->exec('DELETE FROM blocks WHERE page_id = ' . (int) $pageId);
    $pdo->exec('DELETE FROM pages WHERE id = ' . (int) $pageId);
});

test('Раздел «Дизайн» не предлагает настроек, которыми не управляет', function () {
    $options = (new ReflectionClass(\App\Core\DesignSettings::class))->getConstant('OPTIONS');

    // Шапка, подвал и мобильные зоны настраиваются в своих конструкторах:
    // классы design-header-*/mheader-*/footer-* больше не выводятся, и поля
    // в «Дизайне» ничего не меняли. Шаблон сайта был выбором из одного пункта.
    foreach (['site_template', 'header_style', 'header_sticky', 'search_type', 'footer_style', 'mobile_menu', 'mobile_header'] as $dead) {
        assert_false(isset($options[$dead]), 'настройка «' . $dead . '» ничем не управляет');
    }

    // Пресеты не должны тащить удалённые ключи обратно.
    foreach ((new ReflectionClass(\App\Core\DesignSettings::class))->getConstant('PRESETS') as $name => $preset) {
        foreach (array_keys($preset['values']) as $key) {
            assert_true(isset($options[$key]), 'пресет «' . $name . '» задаёт неизвестную настройку ' . $key);
        }
    }

    // Каждая оставшаяся настройка должна иметь потребителя: класс на <body>,
    // переменную темы или чтение в коде.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css')
        . file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $body = \App\Core\DesignSettings::bodyClasses(['catalog_layout' => 'list', 'sidebar_position' => 'fixed',
        'card_style' => 'flat', 'detail_layout' => 'sidebar', 'scroll_top' => 'on',
        'title_reveal' => 'wipe']);
    foreach (['design-catalog-list', 'design-sidebar-fixed', 'design-cards-flat', 'design-detail-sidebar',
              'design-scrolltop', 'design-title-wipe'] as $class) {
        assert_contains($class, $body);
        assert_contains($class, $css, 'класс ' . $class . ' выводится, но не оформлен');
    }

    // Мёртвых правил под удалённые настройки в теме не осталось.
    foreach (['design-header-light', 'design-header-dark', 'design-header-accent',
              'design-mheader-', 'design-footer-minimal', 'design-mmenu-wrap'] as $stale) {
        assert_not_contains($stale, $css, 'правило под удалённую настройку: ' . $stale);
    }
});
