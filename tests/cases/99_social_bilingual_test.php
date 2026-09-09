<?php

declare(strict_types=1);

use App\Core\SocialPublisher;
use App\Core\SocialSettings;

// Двуязычный пост (узбекский + русский) и настраиваемая подпись под постом.

/** Пост с готовыми языковыми блоками (как их отдаёт SocialSettings::buildPost). */
function bilingual_post(): array
{
    return [
        'message' => 'Sarlavha',
        'title' => 'Sarlavha',
        'link' => 'https://site.uz/news/x',
        'image_url' => '',
        'gallery' => [],
        'langs' => [
            ['code' => 'uz', 'label' => 'O‘zbekcha', 'title' => 'Sarlavha', 'excerpt' => 'Qisqacha matn.',
             'link' => 'https://site.uz/uz/news/x', 'read_more' => 'Saytda o‘qish →'],
            ['code' => 'ru', 'label' => 'Русский', 'title' => 'Заголовок', 'excerpt' => 'Краткий текст.',
             'link' => 'https://site.uz/news/x', 'read_more' => 'Читать на сайте →'],
        ],
    ];
}

test('Telegram: оба языка в одном посте, узбекский первым, обе ссылки и подпись', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}']; };
    $sig = '🌐 <a href="https://site.uz">Сайт</a>';
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'signature' => $sig], bilingual_post());

    assert_true($res['ok']);
    $text = (string) $seen['text'];
    // Узбекский блок идёт раньше русского.
    assert_true(mb_strpos($text, 'Sarlavha') < mb_strpos($text, 'Заголовок'), 'узбекский первым');
    assert_contains('Qisqacha matn.', $text);
    assert_contains('Краткий текст.', $text);
    assert_contains('———', $text);
    // Ссылки на обе языковые версии + подпись.
    assert_contains('https://site.uz/uz/news/x', $text);
    assert_contains('Saytda o‘qish', $text);
    assert_contains('Читать на сайте', $text);
    assert_contains('🌐 <a href="https://site.uz">Сайт</a>', $text);
    assert_same('HTML', $seen['parse_mode']);
});

test('Telegram: длинный текст с фото уходит отдельным сообщением, а не режется', function () {
    $calls = [];
    $http = function ($m, $u, $b, $h) use (&$calls) {
        $calls[] = ['url' => $u, 'body' => json_decode($b, true)];
        return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}'];
    };
    $post = bilingual_post();
    $post['image_url'] = 'https://site.uz/cover.jpg';
    // 1600 знаков не влезают в подпись (1024), но свободно влезают в
    // сообщение (4096) — именно этот разрыв и был причиной обрезки.
    $post['langs'][0]['excerpt'] = str_repeat('u', 800);
    $post['langs'][1]['excerpt'] = str_repeat('р', 800);
    $sig = '🌐 <a href="https://site.uz">Сайт</a> | <a href="https://t.me/x">Telegram</a>';

    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'signature' => $sig], $post);
    assert_true($res['ok']);

    // Подпись к фото ограничена 1024 символами, поэтому фото уходит отдельно,
    // а текст — обычным сообщением: анонсы больше не обрезаются пополам.
    assert_same(2, count($calls), 'фото и текст — два сообщения');
    assert_contains('/sendPhoto', (string) $calls[0]['url']);
    assert_same('', (string) ($calls[0]['body']['caption'] ?? ''), 'подпись у фото пустая — весь текст ниже');

    assert_contains('/sendMessage', (string) $calls[1]['url']);
    $text = (string) $calls[1]['body']['text'];
    assert_contains('Sarlavha', $text);
    assert_contains('Заголовок', $text);
    assert_contains('Telegram</a>', $text);
    assert_not_contains('…', $text, 'текст уходит целиком, без многоточия обрезки');
    assert_true(mb_strlen(strip_tags($text)) <= 4096, 'лимит обычного сообщения — 4096');
    assert_true(mb_strlen(strip_tags($text)) > 1024, 'в подпись такой текст не поместился бы');
});

test('Telegram: включённые кнопки ведут на обе языковые версии', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}']; };
    // Кнопки — не поведение по умолчанию, а галочка в настройках канала.
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic', 'buttons' => '1'], bilingual_post());

    $rows = $seen['reply_markup']['inline_keyboard'][0] ?? [];
    assert_same(2, count($rows), 'по кнопке на язык');
    assert_same('https://site.uz/uz/news/x', (string) $rows[0]['url']);
    // Стрелка из подписи кнопки убрана: кнопка и так ведёт наружу.
    assert_not_contains('→', (string) $rows[0]['text']);
});

test('Telegram: рубрика, дата и хештеги попадают в пост', function () {
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":5}}']; };
    $post = bilingual_post() + ['category' => 'Мероприятия', 'date' => '1 августа 2026', 'hashtags' => '#реформы #экономика'];
    (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic'], $post);

    $text = (string) $seen['text'];
    assert_contains('Мероприятия · 1 августа 2026', $text);
    // Хештеги редактор уже вводит в новости, но в Telegram они не доезжали.
    assert_contains('#Реформы #Экономика', $text);
    assert_true(mb_strpos($text, 'Мероприятия') < mb_strpos($text, 'Sarlavha'), 'рубрика над заголовком');
});

test('Facebook/LinkedIn/Instagram: оба языка обычным текстом, без HTML, с голыми URL', function () {
    // Facebook
    $fb = [];
    $httpFb = function ($m, $u, $b, $h) use (&$fb) { $fb = $b; return ['status' => 200, 'body' => '{"id":"1_2"}']; };
    (new SocialPublisher($httpFb))->publish('facebook', ['token' => 'T', 'page_id' => 'P', 'signature' => 'Сайт: https://site.uz'], bilingual_post());
    $msg = urldecode($fb);
    assert_contains('Sarlavha', $msg);
    assert_contains('Заголовок', $msg);
    assert_contains('https://site.uz/uz/news/x', $msg);
    assert_contains('Сайт: https://site.uz', $msg);
    assert_true(!str_contains($msg, '<a href'), 'HTML недопустим в Facebook');
    assert_true(!str_contains($msg, '<b>'), 'HTML недопустим в Facebook');

    // LinkedIn
    $li = [];
    $httpLi = function ($m, $u, $b, $h) use (&$li) { $li = json_decode($b, true); return ['status' => 201, 'body' => '{"id":"urn:li:share:1"}']; };
    (new SocialPublisher($httpLi))->publish('linkedin', ['token' => 'T', 'author' => 'urn:li:organization:1', 'signature' => 'Сайт: https://site.uz'], bilingual_post());
    $text = (string) $li['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'];
    assert_contains('Sarlavha', $text);
    assert_contains('Заголовок', $text);
    assert_true(!str_contains($text, '<b>'), 'HTML недопустим в LinkedIn');

    // Instagram
    $ig = [];
    $step = 0;
    $httpIg = function ($m, $u, $b, $h) use (&$ig, &$step) {
        $step++;
        if ($step === 1) { $ig = $b; return ['status' => 200, 'body' => '{"id":"C1"}']; }
        return ['status' => 200, 'body' => '{"id":"M1"}'];
    };
    $post = bilingual_post();
    $post['image_url'] = 'https://site.uz/c.jpg';
    (new SocialPublisher($httpIg))->publish('instagram', ['token' => 'T', 'user_id' => 'IG', 'signature' => '#ASR #Uzbekistan2030'], $post);
    $cap = urldecode($ig);
    assert_contains('Sarlavha', $cap);
    assert_contains('#ASR #Uzbekistan2030', $cap);
    assert_true(!str_contains($cap, '<a href'), 'HTML недопустим в Instagram');
});

test('Подпись — отдельное поле у каждой сети и не обязательна', function () {
    foreach (['telegram', 'facebook', 'linkedin', 'instagram'] as $net) {
        assert_true(in_array('signature', SocialSettings::FIELDS[$net], true), "$net: есть поле подписи");
    }
    // Без подписи пост всё равно уходит.
    $seen = [];
    $http = function ($m, $u, $b, $h) use (&$seen) { $seen = json_decode($b, true); return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":1}}']; };
    $res = (new SocialPublisher($http))->publish('telegram', ['token' => 'T', 'chat_id' => '@c', 'format' => 'classic'], bilingual_post());
    assert_true($res['ok']);
    assert_contains('Заголовок', (string) $seen['text']);
});

test('Двуязычие при узбекском основном языке: база — узбекский, русский из перевода (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('app_url', 'https://site.uz');
    $pdo = \App\Core\Database::pdo();

    // Боевая конфигурация сайта: основной язык — узбекский.
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        assert_same('uz', \App\Models\Language::defaultCode(), 'основной язык — узбекский');

        // Основное поле — узбекский, вкладка перевода — русский.
        $id = \App\Models\News::create([
            'title' => 'Uzbek sarlavha', 'slug' => 'uz-main-' . uniqid(), 'excerpt' => 'Uzbek anons',
            'content' => 'matn', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
        ]);
        \App\Models\NewsTranslation::upsert($id, 'ru', [
            'title' => 'Русский заголовок', 'excerpt' => 'Русский анонс', 'content' => 'текст',
        ]);

        $post = SocialSettings::buildPost(\App\Models\News::findById($id));
        assert_same(2, count($post['langs']));

        // Узбекский идёт первым и берётся из базовой строки, ссылка — без префикса.
        assert_same('uz', $post['langs'][0]['code']);
        assert_same('Uzbek sarlavha', $post['langs'][0]['title']);
        assert_same('Uzbek anons', $post['langs'][0]['excerpt']);
        assert_true(!str_contains($post['langs'][0]['link'], '/uz/'), 'основной язык — без префикса в URL');

        // Русский — из перевода, со своим префиксом.
        assert_same('ru', $post['langs'][1]['code']);
        assert_same('Русский заголовок', $post['langs'][1]['title']);
        assert_contains('/ru/news/', $post['langs'][1]['link']);
    } finally {
        // Возвращаем основной язык, иначе следующие тесты получат чужую конфигурацию.
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});

test('Список новостей: языки контента одним запросом, без N+1 (БД)', function () {
    ensure_test_db();

    $id1 = \App\Models\News::create([
        'title' => 'Только русский', 'slug' => 'lang-a-' . uniqid(), 'excerpt' => 'a',
        'content' => 'a', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    $id2 = \App\Models\News::create([
        'title' => 'С переводом', 'slug' => 'lang-b-' . uniqid(), 'excerpt' => 'b',
        'content' => 'b', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    \App\Models\NewsTranslation::upsert($id2, 'uz', ['title' => 'Tarjima', 'excerpt' => '', 'content' => '']);

    $map = \App\Models\News::availableLangsForIds([$id1, $id2]);
    assert_same(['ru'], $map[$id1], 'без перевода — только базовый язык');
    assert_true(in_array('uz', $map[$id2], true), 'перевод виден в карте языков');
    assert_true(in_array('ru', $map[$id2], true), 'базовый язык всегда есть');

    // Пустой список не должен падать и ходить в БД.
    assert_same([], \App\Models\News::availableLangsForIds([]));
});

test('Вью списка новостей: метки языков и кнопка публикации в соцсети', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/news/index.php');
    assert_contains('<th>Языки</th>', $view);
    assert_contains('availableLangsForIds', $view);
    assert_contains('/social', $view);
    // Публикация в соцсети — в отдельной колонке со статусом (без N+1).
    assert_contains('<th>Соцсети</th>', $view);
    assert_contains('statusForNewsIds', $view);
    // Кнопка только для опубликованных (иначе прочерк) и при настроенных сетях.
    assert_contains("\$item['status'] !== 'published'", $view);
    assert_contains('if (!empty($readyNets))', $view);
    // Отдельная кнопка на каждую готовую сеть (передаёт network + свой цвет).
    assert_contains('foreach ($readyNets as $net)', $view);
    assert_contains('name="network"', $view);
    assert_contains('btn--social-', $view);
});

test('SocialSettings::buildPost отдаёт языковые блоки; без перевода — только базовый язык (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('app_url', 'https://site.uz');

    $id = \App\Models\News::create([
        'title' => 'Русский заголовок', 'slug' => 'bilingual-' . uniqid(), 'excerpt' => 'Русский анонс',
        'content' => 'текст', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);
    assert_true($id > 0);
    $news = \App\Models\News::findById($id);

    // Пока перевода нет — только русский блок, без дубля.
    $post = SocialSettings::buildPost($news);
    assert_same(1, count($post['langs']), 'без перевода — один язык');
    assert_same('ru', $post['langs'][0]['code']);

    // Добавляем узбекский перевод — в посте появляются оба языка.
    \App\Models\NewsTranslation::upsert($id, 'uz', ['title' => 'Uzbek sarlavha', 'excerpt' => 'Uzbek anons', 'content' => 'matn']);
    $post = SocialSettings::buildPost(\App\Models\News::findById($id));
    assert_same(2, count($post['langs']), 'с переводом — два языка');

    // Первым идёт основной язык сайта, а не жёстко узбекский: порядок задаётся
    // Language::defaultCode(), и на стенде с русским основным ожидание «uz
    // первым» было неверным.
    $default = \App\Models\Language::defaultCode();
    assert_same($default, $post['langs'][0]['code'], 'первым идёт основной язык сайта');

    $codes = array_map(static fn (array $b): string => (string) $b['code'], $post['langs']);
    sort($codes);
    assert_same(['ru', 'uz'], $codes, 'в посте оба языка');

    $byCode = [];
    foreach ($post['langs'] as $block) {
        $byCode[(string) $block['code']] = $block;
    }
    assert_same('Uzbek sarlavha', $byCode['uz']['title']);
    assert_contains('/uz/news/', $byCode['uz']['link']);
});
