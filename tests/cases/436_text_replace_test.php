<?php

declare(strict_types=1);

use App\Core\TextReplace;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\Page;

test('Замена текста: в JSON меняются только текстовые значения, в HTML — только текст', function () {
    $pattern = TextReplace::pattern('Агентство', false);
    $data = json_encode([
        'title' => 'Агентство развития',
        'layout' => 'агентство',
        'image' => '/uploads/Агентство.jpg',
        'items' => [['text' => '<p>Про <a href="/Агентство" title="x">агентство</a></p>']],
    ], JSON_UNESCAPED_UNICODE);
    $count = 0;
    $out = json_decode(TextReplace::replaceIn((string) $data, $pattern, 'Ведомство', $count), true);
    assert_same(2, $count);
    assert_same('Ведомство развития', $out['title']);
    assert_same('агентство', $out['layout'], 'раскладка — не текст');
    assert_same('/uploads/Агентство.jpg', $out['image'], 'путь к файлу не трогается');
    assert_same('<p>Про <a href="/Агентство" title="x">Ведомство</a></p>', $out['items'][0]['text'], 'адрес ссылки не трогается');

    // Без совпадения строка возвращается побайтно та же — даже JSON.
    $count = 0;
    assert_same((string) $data, TextReplace::replaceIn((string) $data, TextReplace::pattern('нет такого', false), 'x', $count));
});

test('Замена текста: пробел совпадает с неразрывным, регистр — по выбору, $1 — буквально', function () {
    $count = 0;
    $out = TextReplace::replaceIn("в\u{00A0}Агентстве и в&nbsp;Агентстве", TextReplace::pattern('в Агентстве', false), 'в $1 Ведомстве', $count);
    assert_same(2, $count);
    assert_same('в $1 Ведомстве и в $1 Ведомстве', $out);

    $count = 0;
    TextReplace::replaceIn('агентство', TextReplace::pattern('Агентство', true), 'x', $count);
    assert_same(0, $count, 'с учётом регистра строчная не совпадает');
    TextReplace::replaceIn('агентство', TextReplace::pattern('Агентство', false), 'x', $count);
    assert_same(1, $count);
});

test('Найти и заменить: поиск по блокам, замена с версией блока и отмена', function () {
    ensure_test_db();

    $pageId = Page::create([
        'slug' => 'replace-test', 'title' => 'Страница про Узагентство', 'status' => 'draft',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $lang = \App\Models\Language::defaultCode();
    $blockId = Block::create($pageId, $lang, 'text', 'Текст', ['content' => '<p>Узагентство открыто</p>', 'variant' => 'plain'], '');

    $found = TextReplace::find('узагентство', false, false);
    $refs = array_column($found['hits'], 'ref');
    assert_true(in_array('blocks|' . $blockId . '|data', $refs, true), 'блок найден');
    assert_true(in_array('pages|' . $pageId . '|title', $refs, true), 'заголовок страницы найден');
    $blockHit = $found['hits'][array_search('blocks|' . $blockId . '|data', $refs, true)];
    assert_same('Узагентство', $blockHit['match']);
    assert_true(str_contains($blockHit['label'], 'replace') || str_contains($blockHit['label'], 'Страница'), 'место названо страницей');

    $revisions = BlockRevision::countForBlock($blockId);
    // Отмечен только блок: заголовок страницы остаётся.
    $result = TextReplace::apply('Узагентство', 'Агентство', false, ['blocks|' . $blockId . '|data', 'users|1|username'], null);
    assert_same(1, $result['fields'], 'чужая таблица из формы не принимается');
    $data = json_decode((string) Block::findById($blockId)['data'], true);
    assert_same('<p>Агентство открыто</p>', $data['content']);
    assert_same('plain', $data['variant']);
    assert_same($revisions + 1, BlockRevision::countForBlock($blockId), 'прежняя версия в истории блока');
    assert_same('Страница про Узагентство', (string) Page::findById($pageId)['title']);

    $undo = TextReplace::undo($result['log']);
    assert_same(['restored' => 1, 'conflicts' => 0], $undo);
    $data = json_decode((string) Block::findById($blockId)['data'], true);
    assert_same('<p>Узагентство открыто</p>', $data['content']);
    assert_same(null, TextReplace::undo($result['log']), 'дважды не отменяется');

    // Поле, которое правили после замены, отмена не трогает.
    $again = TextReplace::apply('Узагентство', 'Агентство', false, ['blocks|' . $blockId . '|data'], null);
    Block::update($blockId, 'Текст', ['content' => '<p>Правка редактора</p>'], '');
    assert_same(['restored' => 0, 'conflicts' => 1], TextReplace::undo($again['log']));
    $data = json_decode((string) Block::findById($blockId)['data'], true);
    assert_same('<p>Правка редактора</p>', $data['content']);

    foreach ([$result['log'], $again['log']] as $log) {
        @unlink(APP_ROOT . '/storage/text-replace/' . $log . '.json');
    }
});
