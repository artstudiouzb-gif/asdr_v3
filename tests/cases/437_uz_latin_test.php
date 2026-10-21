<?php

declare(strict_types=1);

use App\Core\Slug;
use App\Core\TextReplace;
use App\Core\UzCyrillic;
use App\Core\UzLatin;
use App\Models\Block;
use App\Models\Page;

test('Новая латиница: Oʻ → Ö, Gʻ → Ğ, Sh → Ş, Ch → Ç, регистр и апострофы', function () {
    $cases = [
        'O‘zbekiston Respublikasi' => 'Özbekiston Respublikasi',
        "Oʻzbek tili, g'oya" => 'Özbek tili, ğoya',
        'Toshkent shahri, choyxona' => 'Toşkent şahri, çoyxona',
        'SHAHAR CHEGARASI O‘ZBEKISTON' => 'ŞAHAR ÇEGARASI ÖZBEKISTON',
        'tog‘ va bog‘' => 'toğ va boğ',
        "Is'hoq" => "Is'hoq",
        '‘Mirzo’ dedi' => '‘Mirzo’ dedi',
        'maʼlumot' => 'maʼlumot',
        'Facebook, YouTube va Microsoft' => 'Facebook, YouTube va Microsoft',
        'Özbekiston' => 'Özbekiston',
    ];
    foreach ($cases as $old => $new) {
        assert_same($new, UzLatin::text($old), $old);
    }
});

test('Новая латиница: адреса, почта, теги и data-no-translit не трогаются', function () {
    assert_same('https://shop.uz/chat va info@shahar.uz', UzLatin::text('https://shop.uz/chat va info@shahar.uz'));
    assert_same(
        '<p class="sh">Şahar <a href="/shahar">çoy</a><code>shell</code><span data-no-translit>Shoh</span></p>',
        UzLatin::html('<p class="sh">Shahar <a href="/shahar">choy</a><code>shell</code><span data-no-translit>Shoh</span></p>')
    );
});

test('Новая латиница: новые буквы понимают кириллица, поиск и адреса', function () {
    assert_same('SHAHAR Shahar oʻz gʻoya Choy', UzLatin::toOld('ŞAHAR Şahar öz ğoya Çoy'));
    assert_same('Шаҳар Ўзбекистон', UzCyrillic::text('Şahar Özbekiston'));
    assert_same(\App\Core\SearchQuery::normalize('shahar o‘zbek'), \App\Core\SearchQuery::normalize('Şahar özbek'));
    assert_same(Slug::make('Shahar O‘zbekiston'), Slug::make('Şahar Özbekiston'));
});

test('Новая латиница в базе: только узбекские записи, без адресов, с отменой', function () {
    ensure_test_db();

    $uz = Page::create([
        'slug' => 'shahar-test', 'title' => 'Shahar haqida', 'status' => 'draft', 'lang' => 'uz',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $ru = Page::create([
        'slug' => 'shahar-ru', 'title' => 'Shahar (ru)', 'status' => 'draft', 'lang' => 'ru',
        'meta_title' => '', 'meta_description' => '', 'layout_type' => 'no_sidebar',
    ]);
    $block = Block::create($uz, 'uz', 'text', 'Matn', ['content' => '<p>O‘zbekiston <a href="/shahar">shahri</a></p>', 'variant' => 'plain'], '');

    $preview = TextReplace::convertLanguage('uz', static fn (string $t): string => UzLatin::html($t), 'test', null, true);
    assert_true($preview['fields'] >= 2, 'пробный проход считает поля');
    assert_same('Shahar haqida', (string) Page::findById($uz)['title'], 'пробный проход ничего не пишет');

    $result = TextReplace::convertLanguage('uz', static fn (string $t): string => UzLatin::html($t), 'test', null);
    assert_same('Şahar haqida', (string) Page::findById($uz)['title']);
    assert_same('shahar-test', (string) Page::findById($uz)['slug'], 'адрес не меняется');
    assert_same('Shahar (ru)', (string) Page::findById($ru)['title'], 'русская запись не тронута');
    $data = json_decode((string) Block::findById($block)['data'], true);
    assert_same('<p>Özbekiston <a href="/shahar">şahri</a></p>', $data['content']);
    assert_same('plain', $data['variant']);

    TextReplace::undo($result['log']);
    assert_same('Shahar haqida', (string) Page::findById($uz)['title']);
    @unlink(APP_ROOT . '/storage/text-replace/' . $result['log'] . '.json');
});
