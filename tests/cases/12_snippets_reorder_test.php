<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Block;
use App\Models\BlockSnippet;
use App\Models\Page;

test('Block::reorder переставляет блоки по списку id (задача 134)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pid = Page::create(['title' => 'DnD', 'slug' => 'dnd-' . bin2hex(random_bytes(3)), 'meta_title' => null, 'meta_description' => null, 'status' => 'draft', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    $a = Block::create($pid, 'ru', 'text', 'A', ['content' => 'a'], '');
    $b = Block::create($pid, 'ru', 'text', 'B', ['content' => 'b'], '');
    $c = Block::create($pid, 'ru', 'text', 'C', ['content' => 'c'], '');

    // Изначально порядок A,B,C. Переставляем в C,A,B.
    Block::reorder($pid, 'ru', [$c, $a, $b]);
    $ids = array_map(fn ($r) => (int) $r['id'], Block::forPage($pid, 'ru'));
    assert_same([$c, $a, $b], $ids);

    // Посторонний id игнорируется, свои — переставляются.
    Block::reorder($pid, 'ru', [$b, 999999, $a, $c]);
    $ids2 = array_map(fn ($r) => (int) $r['id'], Block::forPage($pid, 'ru'));
    assert_same([$b, $a, $c], $ids2);
});

test('Сниппет: сохранение и вставка создаёт новые блоки (задача 133)', function () {
    ensure_test_db();
    $pid = Page::create(['title' => 'Src', 'slug' => 'src-' . bin2hex(random_bytes(3)), 'meta_title' => null, 'meta_description' => null, 'status' => 'draft', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    Block::create($pid, 'ru', 'text', 'Услуги', ['content' => '<p>x</p>'], '.t{color:red}');
    Block::create($pid, 'ru', 'cta', 'CTA', ['title' => 'Зовём'], '');

    // Сохраняем как сниппет (как это делает контроллер).
    $blocks = [];
    foreach (Block::forPage($pid, 'ru') as $bl) {
        $blocks[] = ['type' => $bl['type'], 'title' => $bl['title'], 'data' => json_decode((string) $bl['data'], true) ?: [], 'custom_css' => (string) $bl['custom_css']];
    }
    $sid = BlockSnippet::create('Секция услуг', $blocks);
    $snippet = BlockSnippet::findById($sid);
    assert_true($snippet !== null);

    // Вставляем в другую страницу.
    $pid2 = Page::create(['title' => 'Dst', 'slug' => 'dst-' . bin2hex(random_bytes(3)), 'meta_title' => null, 'meta_description' => null, 'status' => 'draft', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    $decoded = json_decode((string) $snippet['blocks_json'], true);
    $newIds = [];
    foreach ($decoded as $b) {
        $newIds[] = Block::create($pid2, 'ru', (string) $b['type'], $b['title'], (array) $b['data'], (string) $b['custom_css']);
    }
    $dstBlocks = Block::forPage($pid2, 'ru');
    assert_same(2, count($dstBlocks));
    // Новые id отличаются от исходных (важно для CssScoper).
    $srcIds = array_map(fn ($r) => (int) $r['id'], Block::forPage($pid, 'ru'));
    assert_same([], array_intersect($srcIds, $newIds));
    // custom_css сохранён.
    assert_same('.t{color:red}', (string) $dstBlocks[0]['custom_css']);
});
