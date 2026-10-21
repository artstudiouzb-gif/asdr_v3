<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Page;

test('Копия блока встаёт сразу за оригиналом, контейнер копируется с содержимым', function () {
    ensure_test_db();

    $pageId = Page::create([
        'slug' => 'duplicate-test', 'title' => 'Duplicate', 'status' => 'draft',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $lang = \App\Models\Language::defaultCode();
    $ids = static fn (array $rows): array => array_map(static fn (array $b): int => (int) $b['id'], $rows);

    $columns = Block::create($pageId, $lang, 'columns', 'Колонки', ['columns' => 2], '');
    $faq = Block::create($pageId, $lang, 'faq', 'FAQ', [], '');
    $card = Block::create($pageId, $lang, 'leader_card', 'Сотрудник', ['name' => 'Имя', 'layout' => 'stack'], '', $columns, 0);
    $below = Block::create($pageId, $lang, 'text', 'Ниже', ['content' => 'a'], '', $columns, 0);
    $other = Block::create($pageId, $lang, 'text', 'Соседняя колонка', [], '', $columns, 1);
    Block::setActive($faq, false);

    // Вложенный: копия в той же колонке, сразу под оригиналом, данные те же.
    $copy = Block::duplicate($card);
    assert_true($copy !== null && $copy !== $card);
    $col0 = array_values(array_filter(Block::childrenOf($columns), static fn (array $b): bool => (int) $b['column_index'] === 0));
    assert_same([$card, $copy, $below], $ids($col0));
    $copyRow = Block::findById((int) $copy);
    assert_same(json_decode((string) Block::findById($card)['data'], true), json_decode((string) $copyRow['data'], true));
    assert_same($columns, (int) $copyRow['parent_block_id']);
    assert_same([$other], $ids(array_values(array_filter(Block::childrenOf($columns), static fn (array $b): bool => (int) $b['column_index'] === 1))));

    // Скрытый остаётся скрытым — сам на сайте не появляется.
    $faqCopy = (int) Block::duplicate($faq);
    assert_same(0, (int) Block::findById($faqCopy)['is_active']);

    // Контейнер — вместе с содержимым ячеек, по своим колонкам.
    $columnsCopy = (int) Block::duplicate($columns);
    assert_same([$columns, $columnsCopy, $faq, $faqCopy], $ids(Block::forPage($pageId, $lang)));
    $copiedKids = array_map(static fn (array $b): array => [(string) $b['title'], (int) $b['column_index']], Block::childrenOf($columnsCopy));
    assert_same([['Сотрудник', 0], ['Сотрудник', 0], ['Ниже', 0], ['Соседняя колонка', 1]], $copiedKids);
    assert_same(4, count(Block::childrenOf($columns)), 'оригинал не тронут');

    assert_same(null, Block::duplicate(999999999));
});
