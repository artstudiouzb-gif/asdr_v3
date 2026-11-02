<?php

declare(strict_types=1);

use App\Core\InputBag;
use App\Core\NewsArchive;
use App\Core\SeoHelper;
use App\Models\News;

/*
 * Архив ленты по году и месяцу (FUNC-6): у каждого месяца свой адрес,
 * чужие значения из адреса не строят запрос, рубрика и месяц не теряют
 * друг друга в ссылках.
 */

test('Архив: период месяца и года, чужие значения сбрасываются', function (): void {
    assert_same(['2025-03-01 00:00:00', '2025-04-01 00:00:00'], NewsArchive::period(2025, 3));
    assert_same(['2025-12-01 00:00:00', '2026-01-01 00:00:00'], NewsArchive::period(2025, 12), 'декабрь кончается январём');
    assert_same(['2025-01-01 00:00:00', '2026-01-01 00:00:00'], NewsArchive::period(2025, 0), 'весь год');
    assert_same(null, NewsArchive::period(0, 3));

    $available = [2026 => [10, 2], 2025 => [3]];
    assert_same(['year' => 2025, 'month' => 3], NewsArchive::selected(new InputBag(['year' => '2025', 'month' => '3']), $available));
    assert_same(['year' => 2026, 'month' => 0], NewsArchive::selected(new InputBag(['year' => '2026', 'month' => '5']), $available), 'пустой месяц — весь год');
    assert_same(['year' => 0, 'month' => 0], NewsArchive::selected(new InputBag(['year' => '1900']), $available), 'год без новостей — лента');
    assert_same(['year' => 0, 'month' => 0], NewsArchive::selected(new InputBag(['year' => ['x']]), $available));
});

test('Архив: одна функция адреса для рубрики, месяца и страницы', function (): void {
    $url = NewsArchive::url('press', 2025, 3, 2);
    assert_true(str_ends_with($url, '/news?category=press&year=2025&month=3&page=2'), $url);
    assert_true(str_ends_with(NewsArchive::url('', 2025), '/news?year=2025'));
    assert_true(str_ends_with(NewsArchive::url(), '/news'));
    assert_true(!str_contains(NewsArchive::url('', 0, 3), 'month'), 'месяц без года не пишется');
    assert_true(NewsArchive::monthName(3) !== '' && NewsArchive::monthName(13) === '');
});

test('Архив: у месяца свой canonical, метки кампаний в него не идут', function (): void {
    assert_same(
        'https://example.uz/news?year=2025&month=3',
        SeoHelper::canonicalUrl('https://example.uz', '/news', 'month=3&utm_source=tg&year=2025')
    );
});

test('Архив: месяцы с новостями и выборка за период (БД)', function (): void {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    $make = static function (string $slug, string $at): int {
        return News::create([
            'title' => 'Архив ' . $slug, 'slug' => $slug, 'excerpt' => '', 'content' => 'Текст',
            'image' => '', 'status' => 'published', 'published_at' => $at, 'author_id' => null,
        ]);
    };
    $march = $make('arch-march-' . bin2hex(random_bytes(3)), '2019-03-15 10:00:00');
    $april = $make('arch-april-' . bin2hex(random_bytes(3)), '2019-04-01 00:00:00');

    $months = News::publishedMonths();
    assert_true(in_array(3, $months[2019] ?? [], true) && in_array(4, $months[2019] ?? [], true), 'оба месяца в архиве');

    $period = NewsArchive::period(2019, 3);
    $ids = array_map(static fn (array $r): int => (int) $r['id'], News::published(50, 0, null, null, $period));
    assert_true(in_array($march, $ids, true), 'мартовская в марте');
    assert_true(!in_array($april, $ids, true), 'полночь 1 апреля — уже апрель');
    assert_same(count($ids), News::publishedCount(null, null, $period), 'счётчик совпадает с выборкой');
});
