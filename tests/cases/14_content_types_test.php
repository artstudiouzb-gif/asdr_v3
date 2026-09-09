<?php

declare(strict_types=1);

use App\Core\ContentFields;
use App\Models\ContentEntry;
use App\Models\ContentType;

// --- Юнит: сбор значений полей (без БД) ---

test('ContentFields::collect собирает значения и валидирует required/number/date', function () {
    $fields = [
        ['name' => 'salary', 'label' => 'Зарплата', 'field_type' => 'number', 'required' => true, 'options' => []],
        ['name' => 'start', 'label' => 'Старт', 'field_type' => 'date', 'required' => false, 'options' => []],
        ['name' => 'descr', 'label' => 'Описание', 'field_type' => 'textarea', 'required' => false, 'options' => []],
    ];

    $_POST = ['f_salary' => '1500', 'f_start' => '2026-01-01', 'f_descr' => 'текст'];
    [$vals, $errs] = ContentFields::collect($fields, 'f_', null, false);
    assert_same([], $errs);
    assert_same('1500', $vals['salary']);
    assert_same('2026-01-01', $vals['start']);

    $_POST = ['f_salary' => '', 'f_start' => 'не-дата'];
    [$v2, $e2] = ContentFields::collect($fields, 'f_', null, false);
    assert_true(isset($e2['salary']), 'salary required');
    assert_true(isset($e2['start']), 'start bad date');

    $_POST = ['f_salary' => 'abc'];
    [$v3, $e3] = ContentFields::collect($fields, 'f_', null, false);
    assert_true(isset($e3['salary']), 'salary must be number');

    $_POST = [];
});

test('ContentFields::renderInput формирует нужные типы инпутов', function () {
    $txt = ContentFields::renderInput(['name' => 'a', 'label' => 'A', 'field_type' => 'text', 'required' => true, 'options' => []], 'v');
    assert_contains('name="f_a"', $txt);
    assert_contains('value="v"', $txt);
    assert_contains('required', $txt);

    $num = ContentFields::renderInput(['name' => 'n', 'label' => 'N', 'field_type' => 'number', 'required' => false, 'options' => []], 5);
    assert_contains('type="number"', $num);

    $area = ContentFields::renderInput(['name' => 'd', 'label' => 'D', 'field_type' => 'textarea', 'required' => false, 'options' => []], 'x');
    assert_contains('<textarea', $area);
});

// --- БД: типы, поля, записи, переводы ---

test('ContentType: создание типа, замена полей, декодирование options (БД)', function () {
    ensure_test_db();
    $slug = 'vac-' . bin2hex(random_bytes(3));
    $tid = ContentType::create($slug, 'Вакансии', true);
    ContentType::replaceFields($tid, [
        ['name' => 'salary', 'label' => 'Зарплата', 'field_type' => 'number', 'required' => true, 'options' => []],
        ['name' => 'city', 'label' => 'Город', 'field_type' => 'relation', 'required' => false, 'options' => ['relation_type' => 'cities']],
    ]);
    $fields = ContentType::fields($tid);
    assert_same(2, count($fields));
    assert_same('salary', $fields[0]['name']);
    assert_same('cities', $fields[1]['options']['relation_type']);

    ContentType::delete($tid); // уборка: не копим мусорные типы в тестовой БД
});

test('ContentEntry: CRUD с JSON-данными + переводы (БД)', function () {
    ensure_test_db();
    $tid = ContentType::create('rev-' . bin2hex(random_bytes(3)), 'Отзывы', true);

    $eid = ContentEntry::create($tid, 'Иван', 'ivan-' . bin2hex(random_bytes(2)), 'published', ['rating' => '5', 'text' => 'отлично']);
    $e = ContentEntry::findById($eid);
    assert_same('Иван', $e['title']);
    assert_same('5', $e['data']['rating']);
    assert_same('отлично', $e['data']['text']);

    ContentEntry::upsertTranslation($eid, 'uz', 'Ivan', ['text' => 'zo\'r']);
    $tr = ContentEntry::translations($eid);
    assert_same('Ivan', $tr['uz']['title']);
    assert_same('zo\'r', $tr['uz']['data']['text']);

    // список типа
    $list = ContentEntry::forType($tid);
    assert_same(1, count($list));

    // мягкое удаление убирает из списка
    ContentEntry::delete($eid);
    assert_same(0, count(ContentEntry::forType($tid)));

    ContentType::delete($tid); // уборка
});
