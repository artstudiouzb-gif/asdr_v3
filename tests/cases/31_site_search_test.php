<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Search;
use App\Models\ContentEntry;
use App\Models\ContentType;

test('Search::site игнорирует слишком короткий запрос', function () {
    assert_same([], Search::site('a'));
    assert_same([], Search::site(''));
});

test('Search::site находит опубликованные записи публичных типов, скрывает черновики (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    $type = ContentType::findBySlug('tendery');
    assert_true($type !== null, 'тип Тендеры существует');

    $pub = ContentEntry::create((int) $type['id'], 'Тендер на поставку компьютеров', 'tender-pc-' . bin2hex(random_bytes(2)), 'published', ['budget' => '100000']);
    $draft = ContentEntry::create((int) $type['id'], 'Черновик тендера уникум', 'tender-draft-' . bin2hex(random_bytes(2)), 'draft', []);

    $results = Search::site('компьютеров');
    $titles = array_map(static fn ($r) => $r['title'], $results);
    assert_true(in_array('Тендер на поставку компьютеров', $titles, true), 'опубликованный найден');
    foreach ($results as $r) {
        assert_contains('catalog/tendery/', $r['url'], 'ссылка на фронтенд каталога');
    }

    $draftResults = Search::site('уникум');
    $draftTitles = array_map(static fn ($r) => $r['title'], $draftResults);
    assert_false(in_array('Черновик тендера уникум', $draftTitles, true), 'черновик не найден');

    $pdo->prepare('DELETE FROM content_entries WHERE id IN (:a, :b)')->execute([':a' => $pub, ':b' => $draft]);
});

test('Search::site не показывает записи скрытых (непубличных) типов (БД)', function () {
    ensure_test_db();
    $slug = 'secret-' . bin2hex(random_bytes(3));
    $tid = ContentType::create($slug, 'Секретный', false, '', false); // is_public = false
    $eid = ContentEntry::create($tid, 'Секретная запись поиска', 'secret-entry', 'published', []);

    $results = Search::site('Секретная запись поиска');
    $titles = array_map(static fn ($r) => $r['title'], $results);
    assert_false(in_array('Секретная запись поиска', $titles, true), 'запись скрытого типа не в выдаче');

    Database::pdo()->prepare('DELETE FROM content_entries WHERE id = :i')->execute([':i' => $eid]);
    ContentType::delete($tid);
});
