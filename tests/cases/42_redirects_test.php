<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Redirect;

/** Таблица редиректов (идемпотентно — миграция с IF NOT EXISTS). */
function ensure_redirects_table(): void
{
    ensure_test_db();
    Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_redirects.sql'));
}

test('Redirect::normalizePath: полный URL, query, хвостовые «/», запрет корня и /admin', function () {
    assert_same('/old-page', Redirect::normalizePath('/old-page'));
    assert_same('/old-page', Redirect::normalizePath('https://asdr.gov.uz/old-page?utm=1#top'));
    assert_same('/a/b', Redirect::normalizePath('/a/b/'));
    assert_same('/old', Redirect::normalizePath('old'));
    assert_same(null, Redirect::normalizePath(''));
    assert_same(null, Redirect::normalizePath('/'));
    assert_same(null, Redirect::normalizePath('https://x.uz/'));
    assert_same(null, Redirect::normalizePath('/admin/settings'));
});

test('Redirect::normalizeTarget: относительный путь или http(s); //evil — нет', function () {
    assert_same('/new', Redirect::normalizeTarget('/new'));
    assert_same('https://site.uz/new', Redirect::normalizeTarget('https://site.uz/new'));
    assert_same(null, Redirect::normalizeTarget('//evil.site/x'));
    assert_same(null, Redirect::normalizeTarget('javascript:alert(1)'));
    assert_same(null, Redirect::normalizeTarget('ftp://x'));
    assert_same(null, Redirect::normalizeTarget(''));
});

test('Redirect::buildTarget переносит query-строку; parseImportLine разбирает форматы', function () {
    assert_same('/new?utm=1', Redirect::buildTarget('/new', 'utm=1'));
    assert_same('/new?a=b', Redirect::buildTarget('/new?a=b', 'utm=1'), 'своя query не затирается');
    assert_same('/new', Redirect::buildTarget('/new', ''));

    assert_same(['/old', '/new', 301], Redirect::parseImportLine('/old /new'));
    assert_same(['/old', '/new', 302], Redirect::parseImportLine('/old -> /new 302'));
    assert_same(['/page', '/o-nas', 301], Redirect::parseImportLine('https://asdr.gov.uz/page /o-nas'));
    assert_same(null, Redirect::parseImportLine('# комментарий'));
    assert_same(null, Redirect::parseImportLine('/only-one'));
    assert_same(null, Redirect::parseImportLine('/same /same'));
});

test('Redirect: создание, поиск по пути, дубликат, счётчик, вкл/выкл (БД)', function () {
    ensure_redirects_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM redirects');

    assert_true(Redirect::create('/old-x', '/new-x'));
    assert_false(Redirect::create('/old-x', '/other'), 'дубликат from_path');
    assert_false(Redirect::create('/admin/x', '/y'), 'админку редиректить нельзя');
    assert_false(Redirect::create('/loop', '/loop'), 'редирект сам на себя');

    $r = Redirect::findByPath('/old-x');
    assert_true($r !== null);
    assert_same('/new-x', (string) $r['to_url']);
    assert_same(301, (int) $r['code']);

    Redirect::recordHit((int) $r['id']);
    Redirect::recordHit((int) $r['id']);
    $r2 = Redirect::findByPath('/old-x');
    assert_same(2, (int) $r2['hits']);
    assert_true($r2['last_hit_at'] !== null);

    Redirect::setActive((int) $r['id'], false);
    assert_true(Redirect::findByPath('/old-x') === null, 'выключенный не срабатывает');
    Redirect::setActive((int) $r['id'], true);
    assert_true(Redirect::findByPath('/old-x') !== null);

    Redirect::delete((int) $r['id']);
    assert_true(Redirect::findByPath('/old-x') === null);
    $pdo->exec('DELETE FROM redirects');
});

test('Redirect::import: добавляет валидные строки, считает пропуски (БД)', function () {
    ensure_redirects_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM redirects');

    [$added, $skipped] = Redirect::import(
        "/imp-1 /new-1\n" .
        "# комментарий\n" .
        "/imp-2 -> https://site.uz/new-2 302\n" .
        "мусор\n" .
        "/imp-1 /dup\n"
    );
    assert_same(2, $added);
    assert_same(2, $skipped, 'мусор и дубликат пропущены (комментарий не считается)');

    $r = Redirect::findByPath('/imp-2');
    assert_same(302, (int) $r['code']);
    $pdo->exec('DELETE FROM redirects');
});
