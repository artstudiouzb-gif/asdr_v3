<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\OrgTree;

// Оргструктура: уровень вложенности задаётся отступом, поэтому схема
// переживает смену структуры без новых полей в форме.

test('OrgTree: отступ задаёт уровень, ссылка и акцент разбираются', function () {
    $tree = OrgTree::parse(
        "Департамент | /page#team-a\n"
        . "  Отдел планирования\n"
        . "    Сектор прогнозов\n"
        . "  Отдел анализа\n"
        . "* Проектный офис"
    );

    assert_same(2, count($tree), 'верхний уровень — департамент и проектный офис');
    assert_same('Департамент', $tree[0]['label']);
    assert_same('/page#team-a', $tree[0]['url']);
    assert_same(2, count($tree[0]['children']));
    assert_same('Сектор прогнозов', $tree[0]['children'][0]['children'][0]['label']);
    assert_same([], $tree[0]['children'][1]['children'], 'соседний отдел не забирает чужого потомка');
    assert_true($tree[1]['accent']);
});

test('OrgTree: прежняя разметка дефисом и мусор в ссылке', function () {
    // Так вложенность писали до отступов — сохранённые тексты обязаны читаться.
    $legacy = OrgTree::parse("Отдел\n- Сектор\nДругой отдел");
    assert_same(2, count($legacy));
    assert_same('Сектор', $legacy[0]['children'][0]['label']);

    // Ширина уровня берётся из самого блока: четыре пробела — тоже один шаг.
    $wide = OrgTree::parse("Отдел\n    Сектор");
    assert_same('Сектор', $wide[0]['children'][0]['label']);

    // Прыжок через уровень — опечатка в отступе, а не новый уровень.
    $jump = OrgTree::parse("Отдел\n      Сектор");
    assert_same('Сектор', $jump[0]['children'][0]['label']);

    // Опасная ссылка не сохраняется, текст пункта остаётся.
    $unsafe = OrgTree::parse('Отдел | javascript:alert(1)');
    assert_same('', $unsafe[0]['url']);
    assert_same('Отдел', $unsafe[0]['label']);

    assert_same([], OrgTree::parse("\n   \n"), 'пустые строки не создают пунктов');
});

test('Блок оргструктуры рисует вложенность любой глубины', function () {
    $rendered = BlockRenderer::render([
        'id' => 770,
        'type' => 'org_structure',
        'data' => json_encode([
            'head_title' => 'Директор',
            'branches' => [[
                'title' => 'Заместитель',
                'units' => "Департамент\n  Отдел\n    Сектор\n* Проектный офис",
            ]],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    $html = (string) $rendered['html'];

    assert_contains('orgstruct__unit', $html);
    assert_contains('orgstruct__subunits', $html);
    assert_contains('orgstruct__subunits--deep', $html, 'третий уровень получает свой отступ');
    assert_contains('orgstruct__unit--accent', $html);
    // Список подразделений остаётся сворачиваемым: id и data-атрибут на месте.
    assert_contains('data-org-units', $html);
    assert_contains('id="org-branch-770-1"', $html);

    // Связь «заместитель → подразделения» рисуется линией, а не зазором.
    $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/blocks/org-structure.css');
    assert_contains('.orgstruct__branch .orgstruct__units::before', $css);
});

test('Кириллическая «х» не разрывает строку списка', function () {
    // `\R` без модификатора `u` матчит байт 0x85 — второй байт буквы «х»,
    // и «Бухгалтерия» разваливалась на «Бу» и «галтерия» (в оргструктуре и
    // в строках контактных карточек — там был тот же split).
    $tree = OrgTree::parse("Управление делами\n  Бухгалтерия\n  Кадры");
    assert_same(1, count($tree));
    assert_same('Бухгалтерия', $tree[0]['children'][0]['label']);
    assert_same('Кадры', $tree[0]['children'][1]['label']);

    foreach (['contact_cards.php'] as $file) {
        $tpl = (string) file_get_contents(APP_ROOT . '/templates/blocks/' . $file);
        assert_not_contains("preg_split('/\\R/'", $tpl, $file . ': перевод строки режется по байтам');
    }
});

test('Схема без руководителя: ни пустой карточки, ни линий в пустоту', function () {
    $rendered = BlockRenderer::render([
        'id' => 771,
        'type' => 'org_structure',
        'data' => json_encode([
            'head_title' => '',
            'head_name' => '',
            'branches' => [
                ['title' => 'Департамент А', 'units' => "Отдел А1"],
                ['title' => 'Департамент Б', 'units' => "Отдел Б1"],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    $html = (string) $rendered['html'];

    assert_not_contains('orgstruct__head"', $html, 'пустая тёмная плашка читается как брак');
    assert_contains('orgstruct--nohead', $html);

    // Ствол, перекладина и отводы в этом режиме отключены стилями.
    $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/blocks/org-structure.css');
    assert_contains('.orgstruct--nohead .orgstruct__row::before', $css);
});
