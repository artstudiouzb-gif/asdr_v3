<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Database;
use App\Models\Project;
use App\Models\TeamMember;

/** Хелпер: рендер одиночного блока по типу и данным (без БД). */
function render_block(string $type, array $data): string
{
    $out = BlockRenderer::render([
        'id' => 1,
        'type' => $type,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => null,
    ]);

    return $out['html'];
}

test('Блок testimonials рендерит цитаты и авторов (группа 4)', function () {
    $html = render_block('testimonials', [
        'title' => 'Отзывы',
        'items' => [
            ['quote' => 'Отличная работа', 'name' => 'Иван', 'company' => 'ООО Ромашка', 'photo' => ''],
        ],
    ]);
    assert_contains('block-testimonials', $html);
    assert_contains('Отличная работа', $html);
    assert_contains('Иван', $html);
    assert_contains('ООО Ромашка', $html);
});

test('Блок counters рендерит числа с data-counter-target (группа 4)', function () {
    $html = render_block('counters', [
        'title' => 'Цифры',
        'items' => [['value' => 500, 'suffix' => '+', 'label' => 'клиентов']],
    ]);
    assert_contains('data-counter-target="500"', $html);
    assert_contains('клиентов', $html);
    assert_contains('counter__suffix', $html);
});

test('Блоки team_list/projects_list выводят опубликованные записи с учётом limit (БД, группа 4)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM team_members');
    $pdo->exec("DELETE FROM pages WHERE entity_type = 'project'");

    for ($i = 1; $i <= 3; $i++) {
        TeamMember::create([
            'name' => 'Сотрудник ' . $i, 'position' => 'Роль ' . $i, 'photo' => null,
            'email' => null, 'phone' => null, 'status' => 'published', 'sort_order' => $i,
        ]);
    }
    Project::create(['title' => 'Проект Альфа', 'slug' => 'alpha', 'description' => 'desc', 'cover_image' => null, 'status' => 'published', 'sort_order' => 1]);
    Project::create(['title' => 'Черновик', 'slug' => 'draft-p', 'description' => '', 'cover_image' => null, 'status' => 'draft', 'sort_order' => 2]);

    // team_list с limit=2 → показаны первые двое.
    $team = render_block('team_list', ['title' => 'Команда', 'limit' => 2]);
    assert_contains('Сотрудник 1', $team);
    assert_contains('Сотрудник 2', $team);
    assert_true(!str_contains($team, 'Сотрудник 3'), 'limit=2 должен ограничить вывод');

    // projects_list → только опубликованные (черновик не показывается).
    $proj = render_block('projects_list', ['title' => 'Проекты', 'limit' => 0]);
    assert_contains('Проект Альфа', $proj);
    assert_true(!str_contains($proj, 'Черновик'), 'черновик проекта не выводится');

    $pdo->exec('DELETE FROM team_members');
    $pdo->exec("DELETE FROM pages WHERE entity_type = 'project'");
});
