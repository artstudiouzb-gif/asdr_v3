<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Translations;
use App\Models\Page;

/*
 * Пакетные выборки заменили поштучные там, где страница перебирает записи:
 * карта сайта спрашивала переводы по одной (900 запросов на 450 записей),
 * шапка разрешала цели меню по одной (36 запросов на каждой странице).
 *
 * Быстрый путь обязан давать то же, что медленный, — иначе он тихо разойдётся
 * с ним при первой правке. Здесь это и проверяется: результаты сверяются
 * запись в запись, а не «оба непустые».
 */

test('Пакетное чтение языковых версий совпадает с поштучным (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM pages');

    $insert = $pdo->prepare(
        "INSERT INTO pages (title, slug, lang, translation_group_id, entity_type, status, created_at, updated_at)
         VALUES (:title, :slug, :lang, :grp, 'page', :status, NOW(), NOW())"
    );
    // Группа из двух языков, одиночная страница и черновик: с publishedOnly
    // = false отдаётся группа целиком, не фильтруя по статусу, — это и сверяем.
    $insert->execute([':title' => 'Об агентстве', ':slug' => 'about', ':lang' => 'ru', ':grp' => 0, ':status' => 'published']);
    $ruId = (int) $pdo->lastInsertId();
    $insert->execute([':title' => 'Agentlik haqida', ':slug' => 'about-uz', ':lang' => 'uz', ':grp' => $ruId, ':status' => 'published']);
    $uzId = (int) $pdo->lastInsertId();
    $insert->execute([':title' => 'Одиночная', ':slug' => 'alone', ':lang' => 'ru', ':grp' => 0, ':status' => 'published']);
    $aloneId = (int) $pdo->lastInsertId();
    $insert->execute([':title' => 'Черновик', ':slug' => 'draft', ':lang' => 'ru', ':grp' => 0, ':status' => 'draft']);
    $draftId = (int) $pdo->lastInsertId();

    $ids = [$ruId, $uzId, $aloneId, $draftId];
    $batch = Translations::rowsBatch('pages', $ids, false);

    foreach ($ids as $id) {
        $one = Translations::rows('pages', $id, false);
        $many = $batch[$id] ?? [];
        assert_same(
            array_keys($one),
            array_keys($many),
            "языки записи {$id} совпадают"
        );
        foreach ($one as $lang => $row) {
            assert_same((int) $row['id'], (int) $many[$lang]['id'], "строка {$lang} записи {$id} та же");
        }
    }

    // Несуществующий id не должен ронять выборку и не выдумывает переводов.
    $withGhost = Translations::rowsBatch('pages', [$ruId, 999999], false);
    assert_same([], $withGhost[999999] ?? [], 'у несуществующей записи переводов нет');

    $pdo->exec('DELETE FROM pages');
});

test('Пакетное разрешение целей меню совпадает с поштучным (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM pages');

    $insert = $pdo->prepare(
        "INSERT INTO pages (title, slug, lang, entity_type, status, created_at, updated_at)
         VALUES (:title, :slug, :lang, :type, :status, NOW(), NOW())"
    );
    $insert->execute([':title' => 'Контакты', ':slug' => 'contacts', ':lang' => 'ru', ':type' => 'page', ':status' => 'published']);
    $insert->execute([':title' => 'Черновик', ':slug' => 'hidden', ':lang' => 'ru', ':type' => 'page', ':status' => 'draft']);
    $insert->execute([':title' => 'Проект', ':slug' => 'bridge', ':lang' => 'ru', ':type' => 'project', ':status' => 'published']);

    // Ведущий слэш, дубль, проект с префиксом, черновик и несуществующий адрес.
    $values = ['contacts', '/contacts', 'projects/bridge', 'hidden', 'nothing-here'];

    Page::forgetMenuTargets();
    $batch = Page::publishedMenuTargets($values, 'ru');

    foreach ($values as $value) {
        Page::forgetMenuTargets();
        $one = Page::findPublishedMenuTarget($value, 'ru');
        $many = $batch[$value] ?? null;
        if ($one === null) {
            assert_same(null, $many, "адрес «{$value}» не разрешается ни там, ни там");
            continue;
        }
        assert_true(is_array($many), "адрес «{$value}» разрешился и пакетом");
        assert_same((int) $one['id'], (int) $many['id'], "адрес «{$value}» ведёт на ту же запись");
    }

    $pdo->exec('DELETE FROM pages');
    Page::forgetMenuTargets();
});

test('Цель пункта меню в пределах запроса спрашивается один раз (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM pages');
    $pdo->exec("INSERT INTO pages (title, slug, lang, entity_type, status, created_at, updated_at)
                VALUES ('Контакты', 'contacts', 'ru', 'page', 'published', NOW(), NOW())");

    // Шапка спрашивает одну и ту же цель дважды: разрешая дерево пунктов и
    // собирая ссылку каждого из них. Без памяти это давало 36 одинаковых
    // запросов на каждой странице сайта.
    Page::forgetMenuTargets();
    $first = Page::findPublishedMenuTarget('contacts', 'ru');
    assert_true(is_array($first), 'цель найдена');

    $pdo->exec('DELETE FROM pages');
    $cached = Page::findPublishedMenuTarget('contacts', 'ru');
    assert_true(is_array($cached), 'повторный вопрос отвечен из памяти, без запроса');

    // Запись страниц сбрасывает память: адреса изменились.
    Page::forgetMenuTargets();
    assert_same(null, Page::findPublishedMenuTarget('contacts', 'ru'), 'после сброса память пуста');
});

test('Карта сайта видит оба механизма перевода, как и <head> страницы (БД)', function () {
    ensure_test_db();
    $pdo = App\Core\Database::pdo();
    $pdo->exec('DELETE FROM news_translations');
    $pdo->exec('DELETE FROM news');

    // Перевод полями (механизм А): отдельной записи нет, языковая версия
    // живёт строкой в news_translations и имеет свой адрес с префиксом.
    $pdo->exec("INSERT INTO news (title, slug, status, lang, published_at, created_at, updated_at)
                VALUES ('Новость', 'a-demo', 'published', 'ru', NOW() - INTERVAL 1 HOUR, NOW(), NOW())");
    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO news_translations (news_id, lang, title, excerpt, content)
                           VALUES (:id, 'uz', 'Yangilik', '', '')");
    $stmt->execute([':id' => $id]);

    // <head> страницы берёт языки отсюда — карта сайта обязана совпадать.
    $head = App\Core\TranslationGroupHelper::publishedPaths('news', $id, 'news/');
    $sitemap = App\Core\Translations::rowsBatch('news', [$id], false)[$id] ?? [];

    assert_true(isset($head['uz']), 'перевод полями виден странице');
    assert_true(
        isset($sitemap['uz']),
        'перевод полями виден и карте сайта — прежде она знала только связанные записи'
    );
    assert_same(
        array_keys($head),
        array_keys($sitemap),
        'наборы языков совпадают'
    );

    // Язык перевода нельзя брать из строки: у наложенного перевода lang
    // остаётся языком оригинала, и hreflang="uz" указал бы на русский адрес.
    assert_same('ru', (string) $sitemap['uz']['lang'], 'строка перевода несёт язык оригинала');

    $pdo->exec('DELETE FROM news_translations');
    $pdo->exec('DELETE FROM news');
});
