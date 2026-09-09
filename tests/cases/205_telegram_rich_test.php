<?php

declare(strict_types=1);

use App\Core\SocialPublisher;
use App\Core\TelegramRichMessage;

/**
 * Расширенный формат поста (Bot API 10.1+, `sendRichMessage`). Подпись к фото
 * ограничена 1024 символами, и двуязычная новость резалась пополам. У
 * rich-сообщения предел 32768 символов, поэтому текст уходит целиком, а вторая
 * языковая версия прячется под «развернуть».
 *
 * Формат новый: если Telegram его не примет, публикация обязана продолжиться
 * прежним путём — это проверяется отдельно.
 */

function rich_post(): array
{
    return [
        'message' => 'Sarlavha',
        'link' => 'https://site.uz/news/x',
        'image_url' => 'https://site.uz/cover.jpg',
        'gallery' => ['https://site.uz/1.jpg', 'https://site.uz/2.jpg'],
        'category' => 'Мероприятия',
        'date' => '1 августа 2026',
        'hashtags' => '#o‘zbekiston2030 #strategiya #узбекистан2030 #стратегия',
        'langs' => [
            ['code' => 'uz', 'label' => 'Oʻzbekcha', 'title' => 'Sarlavha', 'excerpt' => "Birinchi.\n\nIkkinchi.",
             'link' => 'https://site.uz/uz/news/x', 'read_more' => 'Saytda oʻqish →',
             'hashtags' => '#o‘zbekiston2030 #strategiya'],
            ['code' => 'ru', 'label' => 'Русский', 'title' => 'Заголовок', 'excerpt' => 'Русский анонс.',
             'link' => 'https://site.uz/news/x', 'read_more' => 'Читать на сайте →',
             'hashtags' => '#узбекистан2030 #стратегия'],
        ],
    ];
}

test('Rich: пост уходит одним sendRichMessage со всей вёрсткой', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":7}}'];
    };
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'signature' => '<b>Агентство</b>'], rich_post());

    assert_true($res['ok']);
    assert_same('7', (string) $res['remote_id']);
    assert_same(1, count($calls), 'весь пост — одно сообщение');
    assert_contains('/sendRichMessage', (string) $calls[0]['url']);

    $rich = $calls[0]['body']['rich_message'];
    $html = (string) $rich['html'];
    // Снимки в разметке — ссылками tg://photo?id=…, файлы отдельным полем.
    assert_contains('<img src="tg://photo?id=p1"/>', $html);
    assert_not_contains('<img src="https://', $html);
    assert_same(3, count($rich['media']), 'обложка и два снимка галереи');
    assert_same('p1', (string) $rich['media'][0]['id']);
    assert_same('photo', (string) $rich['media'][0]['media']['type']);
    assert_same('https://site.uz/cover.jpg', (string) $rich['media'][0]['media']['media']);
    // По умолчанию кнопок нет: ссылки стоят в тексте каждой языковой версии.
    assert_true(!isset($calls[0]['body']['reply_markup']), 'кнопок по умолчанию нет');
    assert_contains('<h2>Sarlavha</h2>', $html);
    assert_contains('<p><sub>Мероприятия · 1 августа 2026</sub></p>', $html);
    assert_contains('Ikkinchi.</p><hr/><p><a href="https://site.uz/uz/news/x">', $html, 'ссылка отделена от анонса');
    // Второй язык — тем же постом, подряд за разделителем: редактор потом
    // дорабатывает пост в канале руками, и спойлер там только мешает.
    assert_contains('Русский анонс.', $html);
    assert_contains('<h2>Заголовок</h2>', $html);
    assert_not_contains('<details>', $html);
    // Галерея — слайд-шоу: альбом из снимков занимал бы весь экран ленты.
    assert_contains('<tg-slideshow>', $html);
    assert_contains('<hr/><p><mark>#Oʻzbekiston2030 #Strategiya</mark></p>', $html, 'узбекские теги выделены отдельной строкой');
    assert_contains('<p><mark>#Узбекистан2030 #Стратегия</mark></p>', $html, 'русские теги выделены отдельной строкой');
    // Подпись — своим блоком: редактор вставляет в неё и цитаты, а <footer>
    // принимает только строчное содержимое.
    assert_contains('<footer><b>Агентство</b></footer>', $html);
    // Разделитель и медиа — в том виде, в каком их показывает документация.
    assert_contains('<hr/>', $html);
});

test('Rich: inline и details используют одинаковое содержимое языковой версии', function () {
    $post = rich_post();
    $args = [
        $post['langs'],
        [],
        '',
        (string) $post['category'],
        (string) $post['date'],
        (string) $post['hashtags'],
        [],
    ];
    $inline = TelegramRichMessage::build(...[...$args, 'inline'])['html'];
    $details = TelegramRichMessage::build(...[...$args, 'details'])['html'];

    foreach ([
        '<h2>Заголовок</h2>',
        'Русский анонс.',
        '<a href="https://site.uz/news/x"><b>Читать на сайте →</b></a>',
        '<p><mark>#Oʻzbekiston2030 #Strategiya</mark></p>',
        '<p><mark>#Узбекистан2030 #Стратегия</mark></p>',
    ] as $fragment) {
        assert_contains($fragment, $inline, 'inline содержит полный языковой блок');
        assert_contains($fragment, $details, 'details содержит тот же языковой блок');
    }
    assert_not_contains('<details>', $inline);
    assert_contains('<details><summary>Русский</summary>', $details);
});

test('Rich: узбекский буквенный апостроф не разрывает хештег Telegram', function () {
    $doc = TelegramRichMessage::build(
        [['code' => 'uz', 'label' => 'Oʻzbekcha', 'title' => 'Sarlavha', 'excerpt' => '',
          'link' => '', 'read_more' => '']],
        [],
        '',
        '',
        '',
        "#o‘zbekiston2030 #g'oya #maʼnaviyat"
    );

    assert_contains('<hr/><p><mark>#Oʻzbekiston2030 #Gʻoya #Maʻnaviyat</mark></p>', $doc['html']);
    assert_not_contains('#o‘zbekiston2030', $doc['html']);
});

test('Rich: отказ Telegram не срывает публикацию — работает прежний формат', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = $u;
        // Старый Bot API не знает метод: публикация обязана продолжиться.
        return str_contains($u, 'sendRichMessage')
            ? ['status' => 404, 'body' => '{"ok":false,"error_code":404,"description":"Not Found: method not found"}']
            : ['status' => 200, 'body' => '{"ok":true,"result":[{"message_id":9}]}'];
    };
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c'], rich_post());

    assert_true($res['ok'], 'после отката пост всё равно опубликован');
    assert_true(str_contains($calls[0], 'sendRichMessage'), 'сначала пробуем расширенный формат');
    assert_true(str_contains($calls[1], 'sendMediaGroup'), 'затем прежний путь с галереей');
});

test('Rich: формат «обычный» отключает новый метод полностью', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":[{"message_id":3}]}'];
    };
    $post = rich_post();
    $post['hashtags'] = '#o‘zbekiston2030';
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic'], $post);

    foreach ($calls as $call) {
        assert_not_contains('sendRichMessage', (string) $call['url']);
    }
    $caption = (string) ($calls[0]['body']['media'][0]['caption'] ?? '');
    assert_contains('<a href="https://site.uz/uz/news/x"><b>Saytda oʻqish →</b></a>', $caption, 'classic делает ссылку на сайт жирной');
    assert_contains('#Oʻzbekiston2030', $caption, 'обычный формат использует единый регистр и безопасный апостроф');
    assert_contains(
        "</a>\n\n\n<b>#Oʻzbekiston2030 #Strategiya</b>\n<b>#Узбекистан2030 #Стратегия</b>",
        $caption,
        'classic выделяет и разделяет языковые строки тегов после последней ссылки'
    );
});

test('Rich: тихая публикация не будит подписчиков', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":1}}']; };
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'silent' => '1'], rich_post());

    assert_true(!empty($seen['disable_notification']));
});

test('Rich: разметка экранируется, чужой HTML внутрь не попадает', function () {
    $doc = TelegramRichMessage::build(
        [['code' => 'ru', 'label' => 'Русский', 'title' => '<script>alert(1)</script>', 'excerpt' => 'Текст & «кавычки»',
          'link' => 'https://site.uz/news/x', 'read_more' => 'Читать']],
        ['https://site.uz/a.jpg'],
        '',
        '',
        '',
        ''
    );

    assert_not_contains('<script>', $doc['html']);
    assert_contains('&lt;script&gt;', $doc['html']);
    assert_contains('&amp;', $doc['html']);
});

test('Rich: без языковых блоков формат не применяется', function () {
    assert_same('', TelegramRichMessage::build([], ['https://site.uz/a.jpg'])['html']);

    // Предел текста rich-сообщения — 32768 символов против 1024 у подписи.
    $long = TelegramRichMessage::build(
        [['code' => 'ru', 'label' => 'Русский', 'title' => 'T', 'excerpt' => str_repeat('я', 40000), 'link' => '', 'read_more' => '']],
        []
    );
    assert_false(TelegramRichMessage::fits($long['html']), 'слишком длинный текст откатится на прежний формат');
});
