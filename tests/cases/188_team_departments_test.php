<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\TeamMember;

/**
 * Распределение команды по подразделениям: сектор — верхний уровень и якорь
 * ссылки из схемы оргструктуры, отдел или группа — уровень внутри сектора.
 */

test('Команда: якорь сектора не зависит от языка страницы', function () {
    $ru = ['department' => 'Сектор анализа и исследований'];
    // Так строку отдаёт localize(): перевод наложен, базовое название сохранено.
    $uz = [
        'department' => 'Tahlil va tadqiqotlar shoʻbasi',
        'department_base' => 'Сектор анализа и исследований',
    ];

    assert_same('sektor-analiza-i-issledovaniy', TeamMember::departmentSlug($ru));
    assert_same(TeamMember::departmentSlug($ru), TeamMember::departmentSlug($uz));
    assert_same('', TeamMember::departmentSlug(['department' => '']));
});

test('Команда: группировка по секторам и отделам внутри них', function () {
    $rows = [
        ['name' => 'Директор', 'department' => '', 'unit' => ''],
        ['name' => 'Руководитель сектора', 'department' => 'Сектор анализа и исследований', 'unit' => ''],
        ['name' => 'Кадровик', 'department' => 'Информационно-аналитический сектор', 'unit' => 'группа по работе с кадрами'],
        ['name' => 'Специалист', 'department' => 'Информационно-аналитический сектор', 'unit' => 'первый отдел'],
        ['name' => 'Начальник', 'department' => 'Информационно-аналитический сектор', 'unit' => ''],
    ];

    $groups = TeamMember::groupByDepartment($rows);

    assert_same(3, count($groups));
    assert_same('Сектор анализа и исследований', $groups[0]['name']);
    assert_same('sektor-analiza-i-issledovaniy', $groups[0]['slug']);
    assert_same([], $groups[0]['units']);

    // Внутри сектора: сначала сотрудники без отдела, затем отделы и группы.
    assert_same('Информационно-аналитический сектор', $groups[1]['name']);
    assert_same(1, count($groups[1]['members']));
    assert_same('Начальник', $groups[1]['members'][0]['name']);
    assert_same(2, count($groups[1]['units']));
    assert_same('группа по работе с кадрами', $groups[1]['units'][0]['name']);
    assert_same('первый отдел', $groups[1]['units'][1]['name']);

    // Сотрудник без сектора не теряется: последняя группа без названия.
    assert_same('', $groups[2]['name']);
    assert_same('Директор', $groups[2]['members'][0]['name']);
});

test('Команда: без секторов группировка возвращает пустой список', function () {
    assert_same([], TeamMember::groupByDepartment([]));

    $groups = TeamMember::groupByDepartment([['name' => 'Иванов', 'department' => '', 'unit' => '']]);
    assert_same(1, count($groups));
    assert_same('', $groups[0]['slug']);
});

test('Команда: блок выводит группы с якорями и фильтрует по сектору (БД)', function () {
    // Состав блок берёт из БД, поэтому проверяем на реальных записях.
    ensure_test_db();
    $pdo = Database::pdo();

    $pdo->exec(
        "INSERT INTO team_members (name, position, department, unit, status, sort_order)
         VALUES ('Тестов Руководитель', 'Руководитель сектора', 'Сектор анализа и исследований', NULL, 'published', 901)"
    );
    $lead = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO team_members (name, position, department, unit, status, sort_order)
         VALUES ('Тестов Кадровик', 'Главный специалист', 'Сектор анализа и исследований', 'группа по работе с кадрами', 'published', 902)"
    );
    $staff = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO team_members (name, position, department, unit, status, sort_order)
         VALUES ('Тестов Пресс-секретарь', 'Специалист', 'Сектор по связям с общественностью', NULL, 'published', 903)"
    );
    $other = (int) $pdo->lastInsertId();

    $render = static fn (array $data): array => \App\Core\BlockRenderer::render([
        'id' => 880,
        'type' => 'team_list',
        'custom_css' => '',
        'data' => json_encode($data),
    ]);

    $grouped = $render(['title' => 'Команда', 'group_by_department' => true])['html'];
    assert_contains('id="team-sektor-analiza-i-issledovaniy"', $grouped);
    assert_contains('block-team__group-title', $grouped);
    assert_contains('группа по работе с кадрами', $grouped);
    assert_contains('Тестов Кадровик', $grouped);
    assert_contains('Тестов Пресс-секретарь', $grouped);

    // Фильтр по сектору: чужой сектор в блок не попадает.
    $filtered = $render([
        'title' => 'Команда',
        'group_by_department' => true,
        'department' => 'sektor-analiza-i-issledovaniy',
    ])['html'];
    assert_contains('Тестов Руководитель', $filtered);
    assert_not_contains('Тестов Пресс-секретарь', $filtered);

    // Без группировки якорей и заголовков секторов нет.
    $plain = $render(['title' => 'Команда'])['html'];
    assert_not_contains('block-team__group-title', $plain);
    assert_contains('Тестов Руководитель', $plain);

    $pdo->exec("DELETE FROM team_members WHERE id IN ({$lead}, {$staff}, {$other})");
});

test('Команда: сектор и отдел сохраняются формой и переводами', function () {
    $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/TeamController.php');
    assert_contains("'department' => \$department !== '' ? \$department : null", $controller);
    assert_contains("'unit' => \$unit !== '' ? \$unit : null", $controller);
    assert_contains("'department' => trim((string) (\$t['department'] ?? ''))", $controller);
    assert_contains("'unit' => trim((string) (\$t['unit'] ?? ''))", $controller);

    $translation = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Models/TeamMemberTranslation.php');
    assert_contains('department = VALUES(department), unit = VALUES(unit)', $translation);
});
