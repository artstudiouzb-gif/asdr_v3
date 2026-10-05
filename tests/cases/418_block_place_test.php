<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Page;

test('Перенос блока: в колонку, между колонками, обратно на страницу и запреты места', function () {
    ensure_test_db();

    $pageId = Page::create([
        'slug' => 'place-test', 'title' => 'Place', 'status' => 'draft',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $otherPageId = Page::create([
        'slug' => 'place-other', 'title' => 'Other', 'status' => 'draft',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $lang = \App\Models\Language::defaultCode();

    $columns = Block::create($pageId, $lang, 'columns', 'Колонки', ['columns' => 3], '');
    $text = Block::create($pageId, $lang, 'text', 'Текст', ['content' => 'a'], '');
    $faq = Block::create($pageId, $lang, 'faq', 'FAQ', [], '');
    $inside = Block::create($pageId, $lang, 'text', 'Внутри', [], '', $columns, 1);
    $tabs = Block::create($pageId, $lang, 'tabs', 'Вкладки', ['items' => [['title' => 'Первая'], ['title' => '']]], '');
    $foreign = Block::create($otherPageId, $lang, 'columns', 'Чужие', ['columns' => 2], '');

    assert_same(3, Block::cellCount(Block::findById($columns)));
    assert_same(1, Block::cellCount(Block::findById($tabs)), 'пустая вкладка ячейкой не считается');

    // Готовый блок страницы встаёт во вторую колонку — перед уже лежащим там.
    $block = Block::findById($text);
    assert_same(null, Block::placementError($block, Block::findById($columns), 1));
    Block::place($text, $columns, 1, [$text, $inside]);
    $kids = array_map(static fn (array $b): array => [(int) $b['id'], (int) $b['column_index']], Block::childrenOf($columns));
    assert_same([[$text, 1], [$inside, 1]], $kids);
    $top = array_map(static fn (array $b): int => (int) $b['id'], Block::forPage($pageId, $lang));
    assert_same([$columns, $faq, $tabs], $top, 'со страницы блок ушёл');

    // Между колонками без позиции — в конец; потом обратно на страницу первым.
    Block::place($inside, $columns, 2);
    assert_same(2, (int) Block::findById($inside)['column_index']);
    Block::place($inside, null, 0, [$inside, $columns, $faq]);
    $top = array_map(static fn (array $b): int => (int) $b['id'], Block::forPage($pageId, $lang));
    assert_same([$inside, $columns, $faq, $tabs], $top);
    $row = Block::findById($inside);
    assert_same(null, $row['parent_block_id']);
    assert_same(0, (int) $row['column_index']);

    // Запреты: контейнер в контейнер, несуществующая колонка, чужая страница,
    // обычный блок вместо контейнера.
    assert_true(Block::placementError(Block::findById($tabs), Block::findById($columns), 0) !== null);
    assert_true(Block::placementError(Block::findById($faq), Block::findById($columns), 3) !== null);
    assert_true(Block::placementError(Block::findById($faq), Block::findById($tabs), 1) !== null);
    assert_true(Block::placementError(Block::findById($faq), Block::findById($foreign), 0) !== null);
    assert_true(Block::placementError(Block::findById($faq), Block::findById($inside), 0) !== null);
    assert_same(null, Block::placementError(Block::findById($faq), null, 0));
});
