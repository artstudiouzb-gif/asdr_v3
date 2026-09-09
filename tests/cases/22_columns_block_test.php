<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\ColumnRatio;
use App\Core\Database;
use App\Models\Block;
use App\Models\Page;

test('Пропорции колонок: только известные варианты, доли считаются в minmax', function () {
    assert_same('', ColumnRatio::normalize('', 2), 'пустое значение = равные колонки');
    assert_same('2:1', ColumnRatio::normalize('2:1', 2));
    assert_same('', ColumnRatio::normalize('2:1', 3), 'пропорция не по числу колонок отбрасывается');
    assert_same('', ColumnRatio::normalize('7:13', 2), 'произвольные доли не принимаются');

    assert_same('minmax(0, 2fr) minmax(0, 1fr)', ColumnRatio::template('2:1', 2));
    assert_same('', ColumnRatio::template('2:1', 3));
    assert_same('2 : 1 : 1', ColumnRatio::label('2:1:1'));
});

test('Блок columns рендерит вложенные блоки по колонкам, запрещая columns-в-columns (БД, группа 4.1)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'cols', 'title' => 'Cols', 'status' => 'published',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);

    // Родитель columns с 2 колонками.
    $colBlock = Block::create($pageId, '', 'columns', 'Сетка', ['columns' => 2, 'gap' => 'large'], '');
    // По одному текстовому блоку в каждую колонку.
    Block::create($pageId, '', 'text', null, ['content' => 'ЛЕВАЯ_КОЛОНКА'], '', $colBlock, 0);
    Block::create($pageId, '', 'text', null, ['content' => 'ПРАВАЯ_КОЛОНКА'], '', $colBlock, 1);
    // Попытка вложить columns-в-columns — при рендере должна игнорироваться.
    Block::create($pageId, '', 'columns', 'Вложенная', ['columns' => 2], '', $colBlock, 0);

    // Верхний уровень: только сам columns-блок (дети исключены).
    $top = Block::forPageLocalized($pageId, '');
    assert_same(1, count($top), 'на верхнем уровне только columns-блок');

    $out = BlockRenderer::renderPage($top);
    $html = $out['html'];

    assert_contains('cms-columns cms-columns--2 cms-columns--gap-large', $html, 'сетка колонок отрисована');
    assert_contains('ЛЕВАЯ_КОЛОНКА', $html);
    assert_contains('ПРАВАЯ_КОЛОНКА', $html);
    // Ровно две колонки в разметке.
    assert_same(2, substr_count($html, 'cms-columns__col'));
    // Вложенный columns-блок не должен появиться внутри (без второй сетки).
    assert_same(1, substr_count($html, 'cms-columns--2'), 'columns-в-columns не рендерится');

    $pdo->exec('DELETE FROM pages');
});

test('Пропорции колонок уходят в scoped CSS и не действуют на телефоне (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'cols-ratio', 'title' => 'Cols', 'status' => 'published',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $colBlock = Block::create($pageId, '', 'columns', 'Сетка', ['columns' => 2, 'gap' => 'medium', 'ratio' => '2:1'], '');
    Block::create($pageId, '', 'text', null, ['content' => 'ТЕКСТ'], '', $colBlock, 0);

    $out = BlockRenderer::renderPage(Block::forPageLocalized($pageId, ''));

    assert_contains('#block-' . $colBlock . ' .cms-columns{grid-template-columns:minmax(0, 2fr) minmax(0, 1fr)}', $out['css']);
    assert_contains('@media (min-width: 721px)', $out['css'], 'на телефоне колонки остаются одна под другой');
    assert_not_contains('style=', $out['html'], 'инлайн-стилей в блоке быть не должно');

    $pdo->exec('DELETE FROM pages');
});
