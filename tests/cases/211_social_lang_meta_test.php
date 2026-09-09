<?php

declare(strict_types=1);

use App\Core\SocialPublisher;
use App\Core\SocialSettings;
use App\Core\TelegramRichMessage;

/**
 * Рубрика и дата — на языке своего блока. Раньше они считались один раз по
 * основному языку сайта, и под русским заголовком в канале стояло
 * «1-avgust, 2026-yil».
 */

/** @return list<array<string,string>> */
function meta_langs(): array
{
    return [
        ['code' => 'uz', 'label' => 'O‘zbekcha', 'title' => 'Sarlavha', 'excerpt' => 'Matn.',
         'link' => 'https://site.uz/news/x', 'read_more' => 'Saytda o‘qish →',
         'category' => 'Tadbirlar', 'date' => '1-avgust, 2026-yil'],
        ['code' => 'ru', 'label' => 'Русский', 'title' => 'Заголовок', 'excerpt' => 'Текст.',
         'link' => 'https://site.uz/ru/news/x', 'read_more' => 'Читать на сайте →',
         'category' => 'Мероприятия', 'date' => '1 августа 2026 г.'],
    ];
}

test('Rich-пост: у каждого языка своя рубрика и дата', function () {
    $doc = TelegramRichMessage::build(meta_langs(), [], '', 'Tadbirlar', '1-avgust, 2026-yil');
    $html = $doc['html'];

    assert_contains('Tadbirlar · 1-avgust, 2026-yil', $html);
    assert_contains('Мероприятия · 1 августа 2026 г.', $html);
    // Русская строка — над русским заголовком, а не в шапке всего поста.
    assert_true(
        mb_strpos($html, 'Sarlavha') < mb_strpos($html, 'Мероприятия · 1 августа'),
        'русские рубрика и дата идут при своём заголовке'
    );
});

test('Второй язык: подряд по умолчанию, под «развернуть» — по настройке', function () {
    $inline = TelegramRichMessage::build(meta_langs(), [])['html'];
    assert_not_contains('<details>', $inline);
    assert_contains('<h2>Заголовок</h2>', $inline);

    $details = TelegramRichMessage::build(meta_langs(), [], '', '', '', '', [], 'details')['html'];
    assert_contains('<details><summary>Русский</summary>', $details);
});

test('Кнопок под постом нет, пока их не включат настройкой', function () {
    $post = ['message' => 'Sarlavha', 'title' => 'Sarlavha', 'link' => 'https://site.uz/news/x',
             'image_url' => '', 'gallery' => [], 'langs' => meta_langs()];
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}']; };

    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c'], $post);
    assert_true(!isset($seen['reply_markup']), 'по умолчанию кнопок нет');
    // Ссылки при этом остаются в тексте своих языковых блоков.
    assert_contains('https://site.uz/ru/news/x', (string) $seen['rich_message']['html']);

    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'buttons' => '1'], $post);
    assert_same(2, count($seen['reply_markup']['inline_keyboard'][0] ?? []), 'галочка возвращает кнопки');
});

test('Классический пост: дата над заголовком своего языка', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}']; };
    $post = ['message' => 'Sarlavha', 'title' => 'Sarlavha', 'link' => 'https://site.uz/news/x',
             'image_url' => '', 'gallery' => [], 'langs' => meta_langs()];
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic'], $post);

    $text = (string) $seen['text'];
    assert_true(mb_strpos($text, '1-avgust, 2026-yil') < mb_strpos($text, 'Sarlavha'), 'узбекская дата над узбекским заголовком');
    assert_true(mb_strpos($text, '1 августа 2026 г.') < mb_strpos($text, 'Заголовок'), 'русская дата над русским заголовком');
    assert_true(mb_strpos($text, 'Sarlavha') < mb_strpos($text, '1 августа 2026 г.'), 'русская дата не уезжает в шапку поста');
});

test('Языковые блоки несут собственные рубрику и дату (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        // Рубрика поста — категория: её название переводится, а сама она
        // одна на все языковые версии новости.
        $categoryId = (int) \App\Models\NewsCategory::create('Tadbirlar ' . uniqid());
        \App\Models\NewsCategoryTranslation::upsert($categoryId, 'ru', 'Мероприятия');

        $id = \App\Models\News::create([
            'title' => 'Uzbek sarlavha', 'slug' => 'meta-lang-' . uniqid(), 'excerpt' => 'Uzbek anons',
            'content' => 'matn', 'status' => 'published', 'category_id' => $categoryId,
            'published_at' => '2026-08-01 10:00:00',
        ]);
        \App\Models\NewsTranslation::upsert($id, 'ru', [
            'title' => 'Русский заголовок', 'excerpt' => 'Русский анонс', 'content' => 'текст',
        ]);

        $langs = SocialSettings::buildPost(\App\Models\News::findById($id))['langs'];
        assert_same(2, count($langs));
        assert_contains('yil', (string) $langs[0]['date']);
        assert_contains('августа', (string) $langs[1]['date']);
        assert_same('Мероприятия', (string) $langs[1]['category']);
    } finally {
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});
