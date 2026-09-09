<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Page;

test('Block::copyLanguageBlocks копирует все блоки с основного языка на целевой язык', function (): void {
    ensure_test_db();

    $pageId = Page::create([
        'title' => 'Тест Копирования Языков',
        'slug' => 'test-copy-lang-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 0,
        'lang' => 'ru',
    ]);

    // Шестой аргумент — customCss, в Block::create() он обязательный.
    Block::create($pageId, 'ru', 'hero', 'Обложка RU', ['title' => 'Заголовок'], '');
    Block::create($pageId, 'ru', 'cta', 'Призыв RU', ['variant' => 'band', 'title' => 'Призыв'], '');

    $ruBlocks = Block::forPage($pageId, 'ru');
    assert_equals(2, count($ruBlocks), 'В RU создано 2 блока');

    $count = Block::copyLanguageBlocks($pageId, 'ru', 'uz');
    assert_equals(2, $count, 'Скопировано 2 блока на UZ');

    $uzBlocks = Block::forPage($pageId, 'uz');
    assert_equals(2, count($uzBlocks), 'На UZ теперь ровно 2 самостоятельных блока');
    assert_equals('uz', $uzBlocks[0]['lang'], 'Блок 1 имеет язык uz');
});
