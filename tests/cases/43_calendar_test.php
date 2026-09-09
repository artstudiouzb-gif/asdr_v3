<?php

declare(strict_types=1);

use App\Core\CalendarGrid;

test('CalendarGrid::build: июль 2026 начинается со среды, 5 недель, все дни на месте', function () {
    $weeks = CalendarGrid::build(2026, 7);
    assert_same(5, count($weeks));
    // 1 июля 2026 — среда: Пн и Вт пустые.
    assert_same(null, $weeks[0][0]['day']);
    assert_same(null, $weeks[0][1]['day']);
    assert_same(1, $weeks[0][2]['day']);
    assert_same('2026-07-01', $weeks[0][2]['date']);
    // Последний день месяца присутствует.
    $days = [];
    foreach ($weeks as $week) {
        foreach ($week as $cell) {
            if ($cell['day'] !== null) {
                $days[] = $cell['day'];
            }
        }
    }
    assert_same(31, count($days));
    assert_same(31, max($days));
    // Каждая неделя — ровно 7 ячеек.
    foreach ($weeks as $week) {
        assert_same(7, count($week));
    }
});

test('CalendarGrid: parseMonth, label и переходы между месяцами', function () {
    assert_same([2026, 7], CalendarGrid::parseMonth('2026-07'));
    assert_same([(int) date('Y'), (int) date('n')], CalendarGrid::parseMonth('мусор'));
    assert_same([(int) date('Y'), (int) date('n')], CalendarGrid::parseMonth('2026-13'));

    assert_same('Июль 2026', CalendarGrid::label(2026, 7));
    assert_same('Iyul 2026', CalendarGrid::label(2026, 7, 'uz'));
    assert_same('July 2026', CalendarGrid::label(2026, 7, 'en'));
    assert_same(['Du', 'Se', 'Ch', 'Pa', 'Ju', 'Sh', 'Ya'], CalendarGrid::weekdays('uz'));
    assert_same('2026-06', CalendarGrid::shiftMonth(2026, 7, -1));
    assert_same('2026-08', CalendarGrid::shiftMonth(2026, 7, 1));
    // Через границу года.
    assert_same('2025-12', CalendarGrid::shiftMonth(2026, 1, -1));
    assert_same('2027-01', CalendarGrid::shiftMonth(2026, 12, 1));
});

test('CalendarGrid::groupByDate: фильтрует месяц, сортирует, декодирует JSON', function () {
    $entries = [
        ['title' => 'B', 'data' => json_encode(['event_date' => '2026-07-15'])],
        ['title' => 'A', 'data' => json_encode(['event_date' => '2026-07-03', 'location' => 'Зал 1'])],
        ['title' => 'Другой месяц', 'data' => json_encode(['event_date' => '2026-08-01'])],
        ['title' => 'Без даты', 'data' => json_encode(['location' => 'X'])],
        ['title' => 'Мусорная дата', 'data' => json_encode(['event_date' => '15.07.2026'])],
    ];

    $grouped = CalendarGrid::groupByDate($entries, 'event_date', 2026, 7);
    assert_same(['2026-07-03', '2026-07-15'], array_keys($grouped));
    assert_same('A', (string) $grouped['2026-07-03'][0]['title']);
    assert_same('Зал 1', (string) $grouped['2026-07-03'][0]['data']['location'], 'JSON декодирован');
});

test('Календарь: тип «Мероприятия» засеян с полем event_date (БД)', function () {
    ensure_test_db();
    \App\Core\Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_events_calendar.sql'));

    $type = \App\Models\ContentType::findBySlug('meropriyatiya');
    assert_true($type !== null, 'тип существует');
    assert_same(1, (int) $type['is_public']);

    $fields = array_column(\App\Models\ContentType::fields((int) $type['id']), 'name');
    assert_true(in_array('event_date', $fields, true), 'поле даты на месте');
});
