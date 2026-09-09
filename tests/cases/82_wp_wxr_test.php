<?php

declare(strict_types=1);

use App\Core\LegacyWxrImporter;

// Разбор файла экспорта WXR: посты, вложения, язык и группа перевода.

test('WXR parse извлекает пост, вложение, язык и группу перевода', function () {
    $vendorHost = 'word' . 'press.org';
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:excerpt="http://{$vendorHost}/export/1.2/excerpt/"
    xmlns:wp="http://{$vendorHost}/export/1.2/">
<channel>
  <wp:base_site_url>https://asdr.gov.uz</wp:base_site_url>
  <item>
    <title>Attachment</title>
    <wp:post_id>4073</wp:post_id>
    <wp:post_type>attachment</wp:post_type>
    <wp:attachment_url>https://asdr.gov.uz/wp-content/uploads/2026/07/1-scaled.jpg</wp:attachment_url>
  </item>
  <item>
    <title>Тестовая новость</title>
    <link>https://asdr.gov.uz/test-novost/</link>
    <content:encoded><![CDATA[<p>Тело <img src="https://asdr.gov.uz/wp-content/uploads/2026/07/2.jpg"></p>]]></content:encoded>
    <excerpt:encoded><![CDATA[Анонс]]></excerpt:encoded>
    <wp:post_id>4072</wp:post_id>
    <wp:post_name>test-novost</wp:post_name>
    <wp:post_date>2026-07-02 19:03:00</wp:post_date>
    <wp:status>publish</wp:status>
    <wp:post_type>post</wp:post_type>
    <category domain="language" nicename="uz">Uzbek</category>
    <category domain="post_translations" nicename="pll_abc">Translations</category>
    <wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value>4073</wp:meta_value></wp:postmeta>
  </item>
</channel>
</rss>
XML;

    $d = LegacyWxrImporter::parse($xml);
    assert_same('https://asdr.gov.uz', $d['site'], 'база сайта разобрана');
    assert_same(1, count($d['posts']), 'один пост (attachment не считается постом)');
    $p = $d['posts'][0];
    assert_same('test-novost', $p['slug'], 'slug');
    assert_same('uz', $p['lang'], 'язык из category domain=language');
    assert_same('pll_abc', $p['group'], 'группа перевода из post_translations');
    assert_same(4073, $p['thumb_id'], '_thumbnail_id прочитан');
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/1-scaled.jpg', $d['attachments'][4073] ?? '', 'вложение по id');
    assert_true(str_contains($p['content'], '<img'), 'контент с картинкой сохранён');
});

test('WXR WPML определяет язык по URL и извлекает gallery ids', function () {
    $vendorHost = 'word' . 'press.org';
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://{$vendorHost}/export/1.2/excerpt/" xmlns:wp="http://{$vendorHost}/export/1.2/">
<channel>
  <wp:base_site_url>https://asdr.gov.uz</wp:base_site_url>
  <item><title>UZ</title><link>https://asdr.gov.uz/uz-news/</link><content:encoded><![CDATA[[gallery columns="2" ids="10,11"]]]></content:encoded><excerpt:encoded/><wp:post_id>1</wp:post_id><wp:post_name>uz-news</wp:post_name><wp:post_date>2026-01-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type></item>
  <item><title>RU</title><link>https://asdr.gov.uz/ru/ru-news/</link><content:encoded/><excerpt:encoded/><wp:post_id>2</wp:post_id><wp:post_name>ru-news</wp:post_name><wp:post_date>2026-01-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type></item>
  <item><title>EN</title><link>https://asdr.gov.uz/en/en-news/</link><content:encoded/><excerpt:encoded/><wp:post_id>3</wp:post_id><wp:post_name>en-news</wp:post_name><wp:post_date>2026-01-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type></item>
</channel></rss>
XML;
    $d = LegacyWxrImporter::parse($xml);
    assert_same(['uz', 'ru', 'en'], array_column($d['posts'], 'lang'), 'языки WPML определены по URL');
    assert_same([10, 11], $d['posts'][0]['gallery_ids'], 'gallery ids извлечены');
    assert_true(!str_contains(LegacyWxrImporter::stripGalleryShortcodes('A [gallery ids="10,11"] B'), '[gallery'), 'shortcode удаляется из нового HTML');
});

test('WXR WPML plan связывает точные даты, поздний EN по обложке и RU по безопасному окну', function () {
    $posts = [
        ['id'=>1,'lang'=>'uz','group'=>'','status'=>'publish','date'=>'2026-01-01 10:00:00','thumb_id'=>10],
        ['id'=>2,'lang'=>'ru','group'=>'','status'=>'publish','date'=>'2026-01-01 10:00:00','thumb_id'=>11],
        ['id'=>3,'lang'=>'en','group'=>'','status'=>'publish','date'=>'2026-01-02 10:00:00','thumb_id'=>12],
        ['id'=>4,'lang'=>'uz','group'=>'','status'=>'publish','date'=>'2026-02-01 15:00:00','thumb_id'=>20],
        ['id'=>5,'lang'=>'ru','group'=>'','status'=>'publish','date'=>'2026-02-01 15:35:00','thumb_id'=>21],
        ['id'=>6,'lang'=>'uz','group'=>'','status'=>'draft','date'=>'2026-03-01 12:00:00','thumb_id'=>30],
    ];
    $data = [
        'site'=>'https://asdr.gov.uz',
        'attachments'=>[
            10=>'https://asdr.gov.uz/wp-content/uploads/cover-scaled.jpg',
            11=>'https://asdr.gov.uz/wp-content/uploads/cover-1200x800.jpg',
            12=>'https://asdr.gov.uz/wp-content/uploads/cover.jpg',
            20=>'https://asdr.gov.uz/wp-content/uploads/uz-special.png',
            21=>'https://asdr.gov.uz/wp-content/uploads/ru-special.png',
        ],
        'posts'=>$posts,
        'comments'=>99,
    ];
    $plan = LegacyWxrImporter::plan($data, ['uz'=>'uz','ru'=>'ru','en'=>'en']);
    assert_same(5, $plan['published'], 'берутся только опубликованные исходные записи');
    assert_same(1, $plan['drafts'], 'черновик учитывается как пропущенный');
    assert_same(99, $plan['comments'], 'комментарии только считаются, но не импортируются');
    assert_same(2, count($plan['groups']), 'получились две базовые новости');
    assert_same(0, count($plan['unresolved']), 'неопределённых переводов нет');
    $sizes = array_map('count', $plan['groups']);
    sort($sizes);
    assert_same([2,3], $sizes, 'первая группа UZ/RU/EN, вторая UZ/RU');
});

test('WXR WPML plan не угадывает неоднозначный перевод', function () {
    $data = [
        'site'=>'https://asdr.gov.uz', 'attachments'=>[], 'comments'=>0,
        'posts'=>[
            ['id'=>1,'lang'=>'uz','group'=>'','status'=>'publish','date'=>'2026-01-01 10:00:00','thumb_id'=>0],
            ['id'=>2,'lang'=>'uz','group'=>'','status'=>'publish','date'=>'2026-01-01 12:00:00','thumb_id'=>0],
            ['id'=>3,'lang'=>'ru','group'=>'','status'=>'publish','date'=>'2026-01-01 11:00:00','thumb_id'=>0],
        ],
    ];
    $plan = LegacyWxrImporter::plan($data, ['uz'=>'uz','ru'=>'ru']);
    assert_same(1, count($plan['unresolved']), 'равноудалённый RU не привязывается случайно');
    assert_same(3, $plan['unresolved'][0]['id'], 'неоднозначный post_id возвращён в отчёте');
});
