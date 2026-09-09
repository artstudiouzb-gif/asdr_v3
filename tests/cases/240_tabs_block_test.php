<?php

declare(strict_types=1);

use App\Controllers\Admin\BlockController;
use App\Core\AssetCollector;
use App\Core\BlockRenderer;
use App\Core\BlockTypeRegistry;
use App\Core\Database;
use App\Models\Block;
use App\Models\Page;

/**
 * Блок «Вкладки»: контейнер, содержимое вкладки — вложенные блоки любого типа.
 * Проверяем ровно то, ради чего он сделан: подписи задаёт блок, наполнение —
 * дети (`column_index` = номер вкладки), а без JavaScript страница остаётся
 * читаемой.
 */

/**
 * @param array<string,mixed> $data
 * @return array{html: string, css: string}
 */
function tabs_block(array $data, int $id = 0): array
{
    // id = 0 намеренно: без реального id блок не ходит в БД за детьми, и
    // разметку контейнера можно проверить без базы.
    $rendered = BlockRenderer::render([
        'id' => $id,
        'type' => 'tabs',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) $rendered['css']];
}

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function collect_tabs_data(array $post): array
{
    $previousPost = $_POST;
    try {
        $_POST = $post;
        $method = new ReflectionMethod(BlockController::class, 'collectData');

        /** @var array<string,mixed> $data */
        $data = $method->invoke(new BlockController(), 'tabs', 'ru');

        return $data;
    } finally {
        $_POST = $previousPost;
    }
}

test('Вкладки: контейнер без шаблона, вкладывать контейнер в контейнер нельзя', function () {
    assert_true(BlockTypeRegistry::has('tabs'));
    assert_true(BlockTypeRegistry::isContainer('tabs'));
    assert_true(BlockTypeRegistry::isContainer('columns'));
    assert_true(!BlockTypeRegistry::isContainer('text'));
    assert_same(null, BlockTypeRegistry::templateFile('tabs'));
});

test('Вкладки: разметка работает без JavaScript — ссылки на видимые панели', function () {
    $out = tabs_block(['items' => [
        ['title' => 'Документы', 'icon' => 'file-text'],
        ['title' => 'Контакты', 'icon' => ''],
    ]]);
    $html = $out['html'];

    assert_contains('class="cms-tabs cms-tabs--segmented cms-tabs--align-left"', $html);
    assert_contains('data-tab-count="2"', $html);
    assert_contains('href="#block-0-tab-1"', $html, 'вкладка — обычная ссылка на панель');
    assert_contains('id="block-0-tab-2"', $html);
    // Роли ARIA расставляет скрипт: в статике переключения нет, обещать его
    // разметкой нельзя.
    assert_not_contains('role="tab"', $html);
    assert_same(0, preg_match('/<div class="cms-tabs__panel"[^>]*\shidden/', $html), 'без скрипта видны все панели');
    // Заголовок панели нужен версии без скрипта: иначе не видно, где кончается
    // одна вкладка и начинается следующая.
    assert_contains('<h3 class="cms-tabs__panel-title">Документы</h3>', $html);
    assert_contains('tabler-file-text', $html, 'иконка вкладки берётся из спрайта');
    assert_not_contains('style=', $html, 'инлайн-стилей в блоке быть не должно');
});

test('Вкладки: неизвестные вариант и выравнивание заменяются умолчанием, вкладка без названия отбрасывается', function () {
    $out = tabs_block([
        'variant' => 'карусель',
        'align' => 'вниз',
        'items' => [['title' => 'Одна'], ['title' => '  '], ['title' => 'Две']],
    ]);

    assert_contains('cms-tabs--segmented cms-tabs--align-left', $out['html']);
    assert_same(2, substr_count($out['html'], 'data-tabs-tab='), 'вкладка без названия не выводится');

    $vertical = tabs_block(['variant' => 'vertical', 'align' => 'stretch', 'items' => [['title' => 'Одна']]]);
    assert_contains('cms-tabs--vertical cms-tabs--align-stretch', $vertical['html']);

    // Блок без вкладок не оставляет на странице пустую разметку: секция без
    // содержимого отсеивается общей проверкой пустого блока в renderPage().
    assert_not_contains('cms-tabs', tabs_block(['items' => []])['html']);
});

test('Вкладки: сохранение оставляет только известные поля', function () {
    $data = collect_tabs_data([
        'variant' => 'underline',
        'align' => 'center',
        'title_field' => 'Информация',
        'description' => '<p>Вводный текст</p><script>alert(1)</script>',
        'items' => [
            ['title' => 'Первая', 'icon' => 'file-text'],
            ['title' => '', 'icon' => 'x'],
            ['title' => 'Вторая', 'icon' => '<svg onload=alert(1)>'],
        ],
        'columns' => 4,
    ]);

    assert_same('underline', $data['variant']);
    assert_same('center', $data['align']);
    assert_same('Информация', $data['title']);
    assert_not_contains('<script', (string) $data['description']);
    assert_same(2, count($data['items']), 'вкладка без названия не сохраняется');
    assert_same('file-text', $data['items'][0]['icon']);
    assert_same('', $data['items'][1]['icon'], 'разметка вместо имени иконки отбрасывается');
    assert_true(!array_key_exists('columns', $data), 'чужие поля в данные блока не попадают');
});

test('Вкладки: скрипт и стили подключаются только на странице с блоком', function () {
    AssetCollector::reset();
    assert_same('', AssetCollector::renderScripts());

    AssetCollector::requireJs('tabs');
    assert_contains('/assets/js/blocks/tabs', AssetCollector::renderScripts());
    assert_contains('/assets/css/blocks/tabs', AssetCollector::renderThemeStyles());
    AssetCollector::reset();
});

test('Вкладки: содержимое — вложенные блоки любого типа (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'tabs', 'title' => 'Tabs', 'status' => 'published',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);

    $tabsBlock = Block::create($pageId, '', 'tabs', 'Вкладки', ['items' => [
        ['title' => 'Первая'],
        ['title' => 'Вторая'],
    ]], '');
    // Разные типы содержимого в разных вкладках — ради этого блок и сделан.
    Block::create($pageId, '', 'text', null, ['content' => 'ТЕКСТ_ПЕРВОЙ'], '', $tabsBlock, 0);
    Block::create($pageId, '', 'faq', null, ['items' => [['question' => 'ВОПРОС_ВТОРОЙ', 'answer' => 'Ответ']]], '', $tabsBlock, 1);
    // Вкладку удалили, а её наполнение осталось: блок сваливается в первую
    // вкладку, а не пропадает молча.
    Block::create($pageId, '', 'text', null, ['content' => 'ТЕКСТ_ИЗ_УДАЛЁННОЙ'], '', $tabsBlock, 5);
    // Контейнер в контейнере при рендере игнорируется.
    Block::create($pageId, '', 'columns', null, ['columns' => 2], '', $tabsBlock, 0);

    $top = Block::forPageLocalized($pageId, '');
    assert_same(1, count($top), 'на верхнем уровне только сам блок вкладок');

    $out = BlockRenderer::renderPage($top);
    $html = $out['html'];

    assert_same(2, substr_count($html, 'cms-tabs__panel"'), 'панелей столько же, сколько вкладок');
    assert_contains('ТЕКСТ_ПЕРВОЙ', $html);
    assert_contains('ВОПРОС_ВТОРОЙ', $html);
    assert_contains('ТЕКСТ_ИЗ_УДАЛЁННОЙ', $html);
    assert_not_contains('cms-columns', $html, 'контейнер в контейнере не рендерится');
    // Скрипт и стили блока подключаются автоматически по типу блока.
    assert_true(in_array('tabs', $out['assets'], true));

    $pdo->exec('DELETE FROM pages');
});

test('Разделы не путаются: проект не открывается формой страницы, страница — формой проекта (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM blocks');
    $pdo->exec('DELETE FROM pages');

    $pageId = Page::create([
        'slug' => 'plain-page', 'title' => 'Страница', 'status' => 'published',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $projectId = \App\Models\Project::create([
        'title' => 'Проект', 'slug' => 'plain-page', 'description' => 'Анонс',
        'cover_image' => null, 'status' => 'published', 'is_featured' => true, 'sort_order' => 0,
    ]);

    // Адрес уникален внутри своего типа: /plain-page и /projects/plain-page
    // живут рядом и не мешают друг другу.
    assert_true($projectId > 0 && $projectId !== $pageId);

    // Каждая модель видит только свои записи.
    assert_same(null, \App\Models\Project::findById($pageId), 'страница не считается проектом');
    assert_true(\App\Models\Project::findById($projectId) !== null);
    $page = Page::findById($projectId);
    assert_same('project', (string) ($page['entity_type'] ?? ''), 'тип записи виден в строке');
    assert_true(Page::findBySlug('plain-page') !== null, 'публичный адрес страницы ведёт на страницу');
    assert_same($pageId, (int) Page::findBySlug('plain-page')['id']);

    $pdo->exec('DELETE FROM pages');
});
