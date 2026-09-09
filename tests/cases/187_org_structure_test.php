<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

/**
 * Блок «Оргструктура»: разметка строк (ссылки, акценты, вложенность),
 * раскладка веток по рядам и безопасность ссылок.
 */
function org_structure_html(array $data): array
{
    return BlockRenderer::render([
        'id' => 870,
        'type' => 'org_structure',
        'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor('org_structure'), $data)),
    ]);
}

test('Оргструктура: совет над руководителем и органы сбоку', function () {
    $rendered = org_structure_html([
        'council' => 'Координационный совет',
        'head_title' => 'Директор',
        'side_items' => 'Советник',
        'branches' => [['title' => 'Заместитель директора', 'units' => "Отдел кадров"]],
    ]);

    assert_contains('orgstruct__council-card', $rendered['html']);
    assert_contains('Координационный совет', $rendered['html']);
    assert_contains('orgstruct__aside-item', $rendered['html']);
    assert_contains('Советник', $rendered['html']);
});

test('Оргструктура: разметка строк даёт ссылки, акценты и вложенные группы', function () {
    $rendered = org_structure_html([
        'head_title' => 'Директор',
        'branches' => [[
            'title' => 'Заместитель директора',
            'units' => "Отдел кадров | /kadry\n* Проектные офисы\nФинансовый отдел\n- группа снабжения",
        ]],
    ]);
    $html = $rendered['html'];

    assert_contains('<a class="orgstruct__link" href="/kadry">Отдел кадров</a>', $html);
    assert_contains('orgstruct__unit--accent', $html);
    assert_contains('Проектные офисы', $html);
    assert_not_contains('* Проектные офисы', $html, 'служебный маркер не должен попадать в текст');
    assert_contains('orgstruct__subunit', $html);
    assert_contains('группа снабжения', $html);
    assert_not_contains('- группа снабжения', $html, 'маркер вложенности не должен попадать в текст');
});

test('Оргструктура: ветка без должности показывает подразделения напрямую', function () {
    $rendered = org_structure_html([
        'head_title' => 'Директор',
        'branches' => [
            ['title' => 'Заместитель директора', 'units' => 'Отдел кадров'],
            ['title' => '', 'name' => '', 'units' => "Пресс-служба\nПервый отдел"],
        ],
    ]);

    assert_contains('orgstruct__branch--headless', $rendered['html']);
    assert_contains('Пресс-служба', $rendered['html']);
});

test('Оргструктура: ветки раскладываются по рядам без одиноких карточек', function () {
    $branches = [];
    for ($i = 1; $i <= 5; $i++) {
        $branches[] = ['title' => 'Заместитель ' . $i, 'units' => 'Отдел ' . $i];
    }
    $rendered = org_structure_html(['head_title' => 'Директор', 'columns' => 4, 'branches' => $branches]);

    // Пять веток при четырёх колонках — это 3+2, а не 4+1.
    assert_same(2, substr_count($rendered['html'], 'class="orgstruct__row"'));
    assert_contains('.orgstruct__row:nth-child(1){--orgstruct-cols:3}', $rendered['css']);
    assert_contains('.orgstruct__row:nth-child(2){--orgstruct-cols:2}', $rendered['css']);
    // Число колонок задаётся scoped CSS, инлайн-стилей в блоке нет.
    assert_not_contains(' style=', $rendered['html']);
});

test('Оргструктура: компактный макет идёт одним рядом', function () {
    $rendered = org_structure_html([
        'layout' => 'spine',
        'collapsible' => true,
        'head_title' => 'Директор',
        'branches' => [
            ['title' => 'Первый заместитель', 'units' => 'Отдел планирования'],
            ['title' => 'Заместитель', 'units' => 'Отдел кадров'],
        ],
    ]);

    assert_contains('orgstruct--spine', $rendered['html']);
    assert_contains('orgstruct--collapsible', $rendered['html']);
    assert_contains('data-org-collapsible', $rendered['html']);
    assert_same(1, substr_count($rendered['html'], 'class="orgstruct__row"'));
});

test('Оргструктура: небезопасные ссылки отбрасываются при выводе', function () {
    $rendered = org_structure_html([
        'head_title' => 'Директор',
        'head_url' => 'javascript:alert(1)',
        'side_items' => 'Советник | javascript:alert(2)',
        'branches' => [[
            'title' => 'Заместитель',
            'url' => 'javascript:alert(3)',
            'units' => 'Отдел кадров | javascript:alert(4)',
        ]],
    ]);

    assert_not_contains('javascript:', $rendered['html']);
    assert_not_contains('orgstruct__deputy-link', $rendered['html']);
    assert_contains('Отдел кадров', $rendered['html']);
});

test('Оргструктура: сохранение формы принимает новые поля', function () {
    // Поля описаны схемой: она же собирает присланное и отдаёт умолчания.
    $fields = \App\Core\BlockData\BlockFieldSchema::fields('org_structure');
    foreach (['council', 'collapsible', 'notes', 'layout'] as $field) {
        assert_true(isset($fields[$field]), "поле {$field} не описано схемой");
    }

    $saved = \App\Core\BlockData\BlockFieldSchema::normalize('org_structure', [
        'council' => ' Координационный совет ',
        'collapsible' => '1',
        'notes' => 'Примечание',
        'layout' => 'spine',
    ], 'ru');
    assert_same('Координационный совет', $saved['council']);
    assert_same(true, $saved['collapsible']);
    assert_same('Примечание', $saved['notes']);
    assert_same('spine', $saved['layout']);
});
