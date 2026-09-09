<?php

declare(strict_types=1);

use App\Core\Translations;

/**
 * Единая точка доступа к языковым версиям. В CMS уживаются два механизма
 * перевода — поля в `*_translations` и отдельная связанная запись — и код,
 * написанный под один из них, второй просто не замечал: тихо, без ошибок.
 * Фасад обязан отвечать одинаково независимо от того, как заведён перевод.
 */

test('Связанная запись и поля перевода дают одинаковый ответ (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    // Механизм А: перевод полями.
    $withFields = \App\Models\News::create([
        'title' => 'База А', 'slug' => 'facade-a-' . $suffix, 'excerpt' => 'Анонс',
        'content' => 'текст', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    \App\Models\NewsTranslation::upsert($withFields, 'uz', [
        'title' => 'Tarjima A', 'excerpt' => 'Anons', 'content' => 'matn',
    ]);

    // Механизм Б: перевод отдельной связанной записью.
    $base = \App\Models\News::create([
        'title' => 'База Б', 'slug' => 'facade-b-' . $suffix, 'excerpt' => 'Анонс',
        'content' => 'текст', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    $linked = \App\Models\News::create([
        'title' => 'Tarjima B', 'slug' => 'facade-b-uz-' . $suffix, 'excerpt' => 'Anons',
        'content' => 'matn', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'), 'lang' => 'uz',
    ]);
    $pdo->prepare('UPDATE news SET translation_group_id = :g WHERE id IN (:a, :b)')
        ->execute([':g' => $base, ':a' => $base, ':b' => $linked]);

    foreach ([$withFields, $base] as $id) {
        $langs = Translations::langs('news', $id);
        sort($langs);
        assert_same(['ru', 'uz'], $langs, 'обе языковые версии видны, как бы ни был заведён перевод');
    }

    // Разница только в адресе: у связанной записи он свой.
    assert_same('news/facade-a-' . $suffix, Translations::paths('news', $withFields, 'news/')['uz']);
    assert_same('news/facade-b-uz-' . $suffix, Translations::paths('news', $base, 'news/')['uz']);

    // Поля перевода накладываются на базовую запись, а не заменяют её целиком.
    $rows = Translations::rows('news', $withFields);
    assert_same('Tarjima A', (string) $rows['uz']['title']);
    assert_same('facade-a-' . $suffix, (string) $rows['uz']['slug'], 'общий slug сохраняется');

    // Группа действует от имени записи основного языка.
    assert_same($base, Translations::primaryId('news', $linked));
    assert_same($withFields, Translations::primaryId('news', $withFields));
});

test('Неопубликованная версия видна только при явном запросе (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $base = \App\Models\News::create([
        'title' => 'База', 'slug' => 'facade-draft-' . $suffix, 'excerpt' => 'Анонс',
        'content' => 'текст', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    $linked = \App\Models\News::create([
        'title' => 'Qoralama', 'slug' => 'facade-draft-uz-' . $suffix, 'excerpt' => 'Anons',
        'content' => 'matn', 'status' => 'draft', 'lang' => 'uz',
    ]);
    $pdo->prepare('UPDATE news SET translation_group_id = :g WHERE id IN (:a, :b)')
        ->execute([':g' => $base, ':a' => $base, ':b' => $linked]);

    assert_same(['ru'], Translations::langs('news', $base), 'черновик не выдаём за готовую версию');
    $all = Translations::langs('news', $base, false);
    sort($all);
    assert_same(['ru', 'uz'], $all, 'админке нужны и черновики');
    // В hreflang черновик тоже не объявляем: страницы ещё нет.
    assert_false(isset(Translations::paths('news', $base, 'news/')['uz']));
});

test('Сущности без связанных записей обслуживаются тем же фасадом (БД)', function () {
    ensure_test_db();

    // У команды перевода отдельной записью не бывает — только поля, и
    // переводится имя, а не заголовок.
    assert_false(Translations::supportsLinkedRecords('team_members'));
    assert_true(Translations::supportsLinkedRecords('news'));
    assert_true(Translations::supportsLinkedRecords('pages'));

    $id = \App\Models\TeamMember::create([
        'name' => 'Иванов Иван', 'position' => 'Директор', 'status' => 'published',
    ]);
    \App\Models\TeamMemberTranslation::upsert($id, 'uz', ['name' => 'Ivanov Ivan', 'position' => 'Direktor']);

    $rows = Translations::rows('team_members', $id);
    assert_true(isset($rows['uz']), 'перевод по имени, а не по заголовку');
    assert_same('Ivanov Ivan', (string) $rows['uz']['name']);
});

test('Неизвестная таблица и несуществующая запись отвечают пустотой', function () {
    assert_same([], Translations::rows('cats', 1));
    assert_same([], Translations::langs('news', 0));
    assert_same([], Translations::paths('news', -5));
    // primaryId никогда не теряет исходный id — иначе публикация уйдёт «в никуда».
    assert_same(42, Translations::primaryId('cats', 42));
    assert_same(42, Translations::primaryId('team_members', 42));
});
