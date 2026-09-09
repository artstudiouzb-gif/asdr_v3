<?php

declare(strict_types=1);

use App\Core\BlockHints;
use App\Core\BlockRenderer;
use App\Core\Database;
use App\Core\Search;

/**
 * Доводка схемы оргструктуры и связки с командой: печать, семантика схемы,
 * предупреждения редактору и поиск по сотрудникам.
 */

test('Оргсхема: печать не теряет тёмные карточки', function () {
    $print = public_print_css();
    assert_contains('.orgstruct', $print, 'нет печатных правил для схемы');
    // Тёмные карточки на печати становятся контуром с чёрным текстом.
    assert_contains('.orgstruct__head', $print);
    assert_contains('.orgstruct__council-card', $print);
    assert_contains('color: #000 !important', $print);
    // Линиям соединителей нужна принудительная заливка, иначе схема рассыпается.
    assert_contains('print-color-adjust: exact', $print);
    // Свёрнутые ветки на листе должны раскрываться.
    assert_contains('.orgstruct__units[hidden]', $print);
});

test('Оргсхема: иерархия размечена списками, а не набором карточек', function () {
    $rendered = BlockRenderer::render([
        'id' => 890,
        'type' => 'org_structure',
        'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('org_structure'), [
            'council' => 'Координационный совет',
            'head_title' => 'Директор',
            'side_items' => 'Советник',
            'notes' => 'Примечание',
            'branches' => [['title' => 'Заместитель директора', 'units' => "Сектор кадров\n- группа учёта"]],
        ])),
    ]);
    $html = $rendered['html'];

    assert_contains('<ul class="orgstruct__council"', $html);
    assert_contains('<ul class="orgstruct__row"', $html);
    assert_contains('<li class="orgstruct__branch"', $html);
    assert_contains('<ul class="orgstruct__notes"', $html);
    // Список подразделений подписан должностью ветки.
    assert_contains('aria-label="Заместитель директора"', $html);
    assert_contains('orgstruct__subunits', $html);
});

test('Подсказки: фильтр по несуществующему сектору и выключенная группировка (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec(
        "INSERT INTO team_members (name, position, department, status, sort_order)
         VALUES ('Тестов Аналитик', 'Специалист', 'Сектор проверки подсказок', 'published', 940)"
    );
    $id = (int) $pdo->lastInsertId();

    $missing = BlockHints::forBlock('team_list', [
        'department' => 'nesuschestvuyuschiy-sektor',
        'group_by_department' => true,
    ]);
    assert_true($missing !== [], 'фильтр по отсутствующему сектору должен предупреждать');

    $ungrouped = BlockHints::forBlock('team_list', ['department' => '', 'group_by_department' => false]);
    assert_true($ungrouped !== [], 'выключенная группировка при наличии секторов должна предупреждать');

    // Настроенный блок предупреждений не вызывает.
    $ok = BlockHints::forBlock('team_list', [
        'department' => 'sektor-proverki-podskazok',
        'group_by_department' => true,
    ]);
    assert_same([], $ok);

    $pdo->exec("DELETE FROM team_members WHERE id = {$id}");
});

test('Подсказки: ссылка со схемы на якорь сектора и блок команды (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $schemeWithLink = [
        'branches' => [['title' => 'Заместитель', 'units' => 'Сектор анализа | /rukovodstvo#team-sektor-analiza']],
    ];

    // Схема без ссылок на команду предупреждений не вызывает никогда.
    assert_same([], BlockHints::forBlock('org_structure', [
        'branches' => [['title' => 'Заместитель', 'units' => 'Сектор анализа']],
    ]));

    // Пока на сайте есть блок «Команда» с группировкой, ссылка рабочая.
    $slug = 'hints-komanda-' . bin2hex(random_bytes(3));
    $pdo->exec(
        "INSERT INTO pages (title, slug, status, lang, created_at, updated_at)
         VALUES ('Команда для подсказок', '{$slug}', 'published', 'ru', NOW(), NOW())"
    );
    $pageId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO blocks (page_id, lang, type, data, sort_order, created_at, updated_at)
         VALUES ({$pageId}, 'ru', 'team_list', '{\"group_by_department\":true}', 0, NOW(), NOW())"
    );
    $blockId = (int) $pdo->lastInsertId();

    assert_same([], BlockHints::forBlock('org_structure', $schemeWithLink), 'группировка есть — предупреждать не о чем');

    // Группировку выключили — ссылка со схемы больше никуда не ведёт.
    $pdo->exec("UPDATE blocks SET data = '{\"group_by_department\":false}' WHERE id = {$blockId}");
    $others = (int) $pdo->query(
        "SELECT COUNT(*) FROM blocks WHERE type = 'team_list'
           AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.group_by_department')) IN ('true', '1')"
    )->fetchColumn();
    if ($others === 0) {
        assert_true(BlockHints::forBlock('org_structure', $schemeWithLink) !== [], 'ссылка без группировки должна предупреждать');
    }

    $pdo->exec("DELETE FROM blocks WHERE id = {$blockId}");
    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});

test('Поиск: сотрудник находится по фамилии и ведёт на якорь сектора (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    // Страница с блоком команды: без неё вести сотрудника некуда.
    $slug = 'poisk-komandy-' . bin2hex(random_bytes(3));
    $pdo->exec(
        "INSERT INTO pages (title, slug, status, lang, created_at, updated_at)
         VALUES ('Руководство поиска', '{$slug}', 'published', 'ru', NOW(), NOW())"
    );
    $pageId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO blocks (page_id, lang, type, data, sort_order, created_at, updated_at)
         VALUES ({$pageId}, 'ru', 'team_list', '{\"group_by_department\":true}', 0, NOW(), NOW())"
    );
    $blockId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO team_members (name, position, department, status, sort_order)
         VALUES ('Каримовский Бехзод', 'Руководитель сектора', 'Сектор анализа и исследований', 'published', 950)"
    );
    $memberId = (int) $pdo->lastInsertId();

    $results = Search::site('Каримовский');
    $found = null;
    foreach ($results as $row) {
        if ($row['type'] === 'Сотрудник' && str_contains($row['title'], 'Каримовский')) {
            $found = $row;
            break;
        }
    }

    assert_true($found !== null, 'сотрудник не найден поиском по сайту');
    assert_contains('#team-sektor-analiza-i-issledovaniy', (string) $found['url']);
    assert_contains('Руководитель сектора', (string) $found['excerpt']);

    $pdo->exec("DELETE FROM team_members WHERE id = {$memberId}");
    $pdo->exec("DELETE FROM blocks WHERE id = {$blockId}");
    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});
