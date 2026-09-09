<?php

declare(strict_types=1);

use App\Core\AgencyContentSeeder;

// Загрузка реального контента на настоящей базе: языковые версии, группа
// переводов, иерархия для хлебных крошек, переводы сотрудников и повторный
// запуск. Раньше эту логику можно было проверить только руками на сервере.

test('Контент Агентства: загрузка создаёт страницы, блоки и руководителей (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    $fixture = AgencyContentSeeder::fixture();
    foreach (array_keys($fixture['pages']) as $slug) {
        $pdo->prepare('DELETE FROM pages WHERE slug = ?')->execute([$slug]);
    }
    foreach ($fixture['team'] as $member) {
        $pdo->prepare('DELETE FROM team_members WHERE name = ?')->execute([$member['name']]);
    }

    $report = AgencyContentSeeder::run($pdo, false, $fixture);

    $expectedPages = 0;
    $expectedBlocks = 0;
    foreach ($fixture['pages'] as $langData) {
        foreach ($langData as $page) {
            $expectedPages++;
            $expectedBlocks += count($page['blocks']);
        }
    }
    assert_same($expectedPages, $report['pages_created'], 'создано страниц');
    assert_same($expectedBlocks, $report['blocks'], 'записано блоков');
    assert_same(count($fixture['team']), $report['team_created'], 'создано сотрудников');

    // Языковые версии одной страницы делят группу переводов и slug.
    $stmt = $pdo->prepare('SELECT lang, translation_group_id, `lead` FROM pages WHERE slug = ? ORDER BY lang');
    $stmt->execute(['o-nas']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assert_same(count($fixture['pages']['o-nas']), count($rows), 'языковые версии «Об Агентстве»');
    $groups = array_unique(array_column($rows, 'translation_group_id'));
    assert_same(1, count($groups), 'все версии в одной группе переводов');

    // Хлебные крошки строятся по parent_id: директор внутри «Руководства».
    $parent = $pdo->query(
        'SELECT par.slug FROM pages p JOIN pages par ON par.id = p.parent_id
          WHERE p.slug = \'direktor\' AND p.lang = \'ru\''
    )->fetchColumn();
    assert_same('rukovodstvo', $parent, 'родитель страницы директора');

    // У страницы с профилем лид пустой: заголовок даёт сам блок, иначе h1 два.
    $lead = $pdo->query('SELECT `lead` FROM pages WHERE slug = \'direktor\' AND lang = \'ru\'')->fetchColumn();
    assert_same('', trim((string) $lead), 'лид страницы профиля');

    $name = $pdo->query(
        'SELECT JSON_UNQUOTE(JSON_EXTRACT(b.data, \'$.name\')) FROM blocks b
           JOIN pages p ON p.id = b.page_id
          WHERE p.slug = \'direktor\' AND p.lang = \'ru\' AND b.type = \'person_profile\''
    )->fetchColumn();
    assert_same($fixture['team'][0]['name'], (string) $name, 'профиль директора');

    // Переводы сотрудников доехали.
    $langs = $pdo->query(
        'SELECT t.lang FROM team_member_translations t JOIN team_members m ON m.id = t.member_id
          WHERE m.name = ' . $pdo->quote((string) $fixture['team'][1]['name']) . ' ORDER BY t.lang'
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedLangs = array_keys($fixture['team'][1]['translations']);
    sort($expectedLangs);
    assert_same($expectedLangs, $langs, 'языки перевода сотрудника');
});

test('Контент Агентства: повторный запуск ничего не дублирует, dry-run не пишет (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    $fixture = AgencyContentSeeder::fixture();
    AgencyContentSeeder::run($pdo, false, $fixture);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();

    $second = AgencyContentSeeder::run($pdo, false, $fixture);
    assert_same(0, $second['pages_created'], 'повторный запуск не создаёт страницы заново');
    assert_true($second['pages_updated'] > 0, 'повторный запуск обновляет существующие');
    assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(), 'число страниц не выросло');

    // Блоки заменяются, а не накапливаются.
    $blocks = (int) $pdo->query(
        'SELECT COUNT(*) FROM blocks b JOIN pages p ON p.id = b.page_id
          WHERE p.slug = \'o-nas\' AND p.lang = \'ru\' AND b.lang = \'ru\''
    )->fetchColumn();
    assert_same(count($fixture['pages']['o-nas']['ru']['blocks']), $blocks, 'блоки не дублируются');

    // Пробный прогон откатывает транзакцию целиком.
    $pdo->prepare('DELETE FROM pages WHERE slug = ?')->execute(['o-nas']);
    $dry = AgencyContentSeeder::run($pdo, true, $fixture);
    assert_true($dry['pages_created'] > 0, 'dry-run отчитывается о создании');
    $exists = (int) $pdo->query('SELECT COUNT(*) FROM pages WHERE slug = \'o-nas\'')->fetchColumn();
    assert_same(0, $exists, 'dry-run ничего не сохранил');

    AgencyContentSeeder::run($pdo, false, $fixture);
});
