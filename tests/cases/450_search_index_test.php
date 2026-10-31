<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Search;
use App\Core\SearchIndex;

/*
 * Поиск по индексу (App\Core\SearchIndex): находит текст блоков страницы,
 * документ отдельным результатом, альбом; новость до даты публикации не
 * показывает; апостроф узбекского письма совпадает в любом написании.
 */

function search_index_fixture(): string
{
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = 'zq' . bin2hex(random_bytes(3));

    $pdo->prepare("INSERT INTO pages (title, slug, status, is_home, layout_type, lang, entity_type)
        VALUES (?, ?, 'published', 0, 'no_sidebar', 'ru', 'page')")->execute(['Страница ' . $tag, 'p-' . $tag]);
    $pageId = (int) $pdo->lastInsertId();
    $blocks = $pdo->prepare('INSERT INTO blocks (page_id, lang, type, data, sort_order) VALUES (?, ?, ?, ?, ?)');
    $blocks->execute([$pageId, 'ru', 'text', json_encode(['content' => '<p>Внутри блока слово ' . $tag . 'blok и o‘lchov' . $tag . '</p>']), 1]);
    $blocks->execute([$pageId, 'ru', 'docs_list', json_encode(['items' => [
        ['title' => 'Положение ' . $tag . 'doc', 'url' => '/uploads/public/' . $tag . '.pdf', 'number' => 'ПП-12'],
    ]]), 2]);

    $pdo->prepare("INSERT INTO news (title, slug, content, status, lang, published_at)
        VALUES (?, ?, ?, 'published', 'ru', DATE_ADD(NOW(), INTERVAL 2 DAY))")
        ->execute(['Будущая ' . $tag . 'future', 'n-' . $tag, '']);
    $pdo->prepare("INSERT INTO photo_albums (title, slug, description, is_published) VALUES (?, ?, ?, 1)")
        ->execute(['Альбом ' . $tag . 'album', 'a-' . $tag, '']);

    SearchIndex::rebuild();

    return $tag;
}

test('Поиск: текст блока страницы находится, документ — отдельным результатом', function (): void {
    $tag = search_index_fixture();
    assert_true(SearchIndex::ready());

    $hits = Search::all($tag . 'blok');
    assert_same(1, count($hits), 'страница найдена по тексту блока');
    assert_same('page', $hits[0]['entity']);
    assert_contains($tag . 'blok', $hits[0]['excerpt'], 'строка выдачи — кусок вокруг совпадения');

    $docs = Search::all($tag . 'doc');
    assert_same('document', $docs[0]['entity'] ?? '', 'документ ищут как документ');
    assert_contains($tag . '.pdf', $docs[0]['url']);

    assert_same('album', Search::all($tag . 'album')[0]['entity'] ?? '');
});

test('Поиск: новость до даты публикации не показывается, апостроф любой', function (): void {
    $tag = search_index_fixture();
    assert_same([], Search::all($tag . 'future'), 'отложенная новость не видна до срока');
    assert_same(1, count(Search::all("o'lchov" . $tag)), 'прямой апостроф находит «‘»');
    assert_same(1, count(Search::all('oʻlchov' . $tag)), 'и модификатор буквы тоже');
});
