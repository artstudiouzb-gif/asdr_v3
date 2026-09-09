<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\BlockSnippet;
use App\Models\Page;

test('BlockSnippet::applyToPage с replace=true заменяет только выбранный язык', function (): void {
    ensure_test_db();

    $pageId = Page::create([
        'title' => 'Тестовая Страница Замены',
        'slug' => 'test-replace-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 0,
        'lang' => 'ru',
    ]);

    // Создаем старые блоки
    Block::create($pageId, 'ru', 'text', 'Старый текст 1', ['content' => 'Old 1'], '');
    Block::create($pageId, 'uz', 'text', 'Старый текст 2', ['content' => 'Old 2'], '');

    // Block::forPage() всегда фильтрует по одному языку: null означает не «все
    // языки», а язык по умолчанию. Проверяем заготовку по каждому языку
    // отдельно — иначе счётчик видит только русский блок.
    assert_equals(1, count(Block::forPage($pageId, 'ru', false)), 'Вначале один русский блок');
    assert_equals(1, count(Block::forPage($pageId, 'uz', false)), 'Вначале один узбекский блок');

    $preset = \App\Core\PagePresets::find('home');
    BlockSnippet::applyToPage($preset['blocks'], $pageId, 'ru', true);

    $remainingRu = Block::forPage($pageId, 'ru');
    assert_true(count($remainingRu) > 0, 'Созданы новые блоки');

    $allBlocksAfter = Block::forPage($pageId, 'uz');
    assert_equals(1, count($allBlocksAfter), 'Узбекские блоки не затронуты');
    assert_same('Old 2', (string) (json_decode((string) $allBlocksAfter[0]['data'], true)['content'] ?? ''));
});
