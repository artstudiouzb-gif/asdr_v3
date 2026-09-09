<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsCategoryTranslation;

// Рубрикатор новостей по категориям: publishedCategories + фильтр в
// published/publishedCount. Раньше рубрикой служил свободный текст в
// news.badge — он не сводился в справочник, и «Пресс-релиз» с «пресс-релиз»
// были двумя разными рубриками.

test('News::publishedCategories: только категории опубликованных новостей', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $pressId = NewsCategory::create('Пресс-релиз');
    $analyticsId = NewsCategory::create('Аналитика');
    $draftOnlyId = NewsCategory::create('Только черновики');
    $hiddenId = NewsCategory::create('Скрытая рубрика', '', false);

    $ids = [];
    foreach ([
        ['cat-a', 'published', $pressId],
        ['cat-b', 'published', $analyticsId],
        ['cat-c', 'published', null],
        ['cat-d', 'draft', $draftOnlyId],
        ['cat-e', 'published', $hiddenId],
    ] as [$slug, $status, $categoryId]) {
        $stmt = $pdo->prepare(
            'INSERT INTO news (title, slug, status, published_at, category_id) VALUES (?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([$slug, 'test-' . $slug, $status, $categoryId]);
        $ids[] = (int) $pdo->lastInsertId();
    }

    $names = array_map(static fn (array $c): string => (string) $c['name'], News::publishedCategories());
    assert_true(in_array('Пресс-релиз', $names, true), 'рубрика опубликованной новости есть в списке');
    assert_true(in_array('Аналитика', $names, true), 'вторая рубрика есть в списке');
    assert_true(!in_array('Только черновики', $names, true), 'рубрика без опубликованных новостей не попадает');
    // Скрытая рубрика не предлагается посетителю, даже если новости в ней есть:
    // иначе выключение рубрики в админке ни на что бы не влияло.
    assert_true(!in_array('Скрытая рубрика', $names, true), 'скрытая рубрика не попадает в рубрикатор');
    assert_same(1, count(array_keys($names, 'Пресс-релиз', true)), 'без дублей');

    $pdo->exec('DELETE FROM news WHERE id IN (' . implode(',', $ids) . ')');
    foreach ([$pressId, $analyticsId, $draftOnlyId, $hiddenId] as $id) {
        NewsCategory::delete((int) $id);
    }
});

test('News::published и publishedCount фильтруют по категории', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $pressId = (int) NewsCategory::create('Пресс-релиз фильтра');
    $analyticsId = (int) NewsCategory::create('Аналитика фильтра');

    $ids = [];
    foreach ([['flt-a', $pressId], ['flt-b', $pressId], ['flt-c', $analyticsId]] as [$slug, $categoryId]) {
        $stmt = $pdo->prepare(
            "INSERT INTO news (title, slug, status, published_at, category_id) VALUES (?, ?, 'published', NOW(), ?)"
        );
        $stmt->execute([$slug, 'test-' . $slug, $categoryId]);
        $ids[] = (int) $pdo->lastInsertId();
    }

    assert_same(2, News::publishedCount($pressId));
    $rows = News::published(50, 0, null, $pressId);
    assert_same(2, count($rows));
    foreach ($rows as $row) {
        assert_same($pressId, (int) $row['category_id']);
    }
    assert_true(News::publishedCount() >= 3, 'без фильтра — все опубликованные');
    // Несуществующая рубрика — пустая лента, а не вся лента: иначе битая
    // ссылка молча показывала бы всё подряд.
    assert_same(0, News::publishedCount(999999));

    $pdo->exec('DELETE FROM news WHERE id IN (' . implode(',', $ids) . ')');
    NewsCategory::delete($pressId);
    NewsCategory::delete($analyticsId);
});

test('Категория переводится, а slug остаётся общим для всех языков', function () {
    ensure_test_db();

    $id = (int) NewsCategory::create('Мероприятия перевода');
    NewsCategoryTranslation::upsert($id, 'uz', 'Tadbirlar tarjimasi');

    $ru = NewsCategory::localize((array) NewsCategory::find($id), 'ru');
    $uz = NewsCategory::localize((array) NewsCategory::find($id), 'uz');

    assert_same('Мероприятия перевода', (string) $ru['name']);
    assert_same('Tadbirlar tarjimasi', (string) $uz['name']);
    assert_same((string) $ru['slug'], (string) $uz['slug'], 'адрес рубрики один на все языки');

    // Пустой перевод — откат к основному языку, а не пустая рубрика в ленте.
    NewsCategoryTranslation::upsert($id, 'uz', '');
    $uzEmpty = NewsCategory::localize((array) NewsCategory::find($id), 'uz');
    assert_same('Мероприятия перевода', (string) $uzEmpty['name']);

    NewsCategory::delete($id);
});

test('У рубрики своя иконка: хранится ключом Tabler и выводится в рубрикаторе (БД)', function () {
    ensure_test_db();

    // Ключ чистится тем же правилом, что и в блоках: чужой мусор не сохраняется.
    $id = (int) NewsCategory::create('Рубрика с иконкой', '', true, 0, 'building-bank');
    assert_same('building-bank', (string) NewsCategory::find($id)['icon']);

    NewsCategory::update($id, 'Рубрика с иконкой', (string) NewsCategory::find($id)['slug'], true, 0, '<script>');
    assert_same('', (string) NewsCategory::find($id)['icon'], 'непригодный ключ иконки не сохраняется');

    // Иконка — украшение рядом с названием, поэтому скрыта от диктора.
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_index.php');
    assert_contains('listing-filter__icon', $view);
    assert_contains('aria-hidden="true"', $view);
    assert_contains("Icon::cleanName(\$c['icon'] ?? '')", $view);

    NewsCategory::delete($id);
});
