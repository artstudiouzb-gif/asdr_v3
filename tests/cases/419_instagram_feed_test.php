<?php

declare(strict_types=1);

use App\Core\InstagramFeed;

/*
 * Лента Instagram: посты забирает воркер, страница показывает сохранённую
 * копию. Здесь проверяется то, что не требует сети: разбор ответа Graph API,
 * выбор API по виду токена и разметка блока и виджета.
 */

function instagram_sample_posts(): array
{
    return [
        ['id' => '1', 'type' => 'IMAGE', 'caption' => 'Fransiya taraqqiyot agentligi bilan uchrashuv', 'permalink' => 'https://www.instagram.com/p/AAA/', 'image' => '/uploads/public/instagram-1.jpg', 'timestamp' => ''],
        ['id' => '2', 'type' => 'VIDEO', 'caption' => '', 'permalink' => 'https://www.instagram.com/reel/BBB/', 'image' => '/uploads/public/instagram-2.jpg', 'timestamp' => ''],
    ];
}

test('Instagram: разбор ответа Graph API берёт кадр ролика из обложки и отсеивает чужие ссылки', function () {
    $json = json_encode(['data' => [
        ['id' => '17900001', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/a.jpg', 'permalink' => 'https://www.instagram.com/p/AAA/', 'caption' => 'Matn', 'timestamp' => '2026-09-21T10:00:00+0000'],
        ['id' => '17900002', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example/v.mp4', 'thumbnail_url' => 'https://cdn.example/v.jpg', 'permalink' => 'https://www.instagram.com/reel/BBB/'],
        ['id' => '17900003', 'media_type' => 'CAROUSEL_ALBUM', 'media_url' => 'https://cdn.example/c.jpg', 'permalink' => 'https://www.instagram.com/p/CCC/'],
        // Ссылка поста уходит в href: адрес не на Instagram не пропускается.
        ['id' => '17900004', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/d.jpg', 'permalink' => 'javascript:alert(1)'],
        // Без кадра показать пост нечем.
        ['id' => '17900005', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/EEE/'],
    ]]);

    $items = InstagramFeed::parseMedia((string) $json);
    assert_same(['17900001', '17900002', '17900003'], array_column($items, 'id'));
    assert_same('https://cdn.example/v.jpg', $items[1]['remote'], 'у ролика кадр — обложка, а не файл видео');
    assert_same('CAROUSEL_ALBUM', $items[2]['type']);
    assert_same([], InstagramFeed::parseMedia('{"error":{"message":"x"}}'));
});

test('Instagram: API выбирается по виду токена', function () {
    assert_true(InstagramFeed::isInstagramLogin('IGAAexample'), 'токен Instagram Login читает graph.instagram.com');
    assert_false(InstagramFeed::isInstagramLogin('EAAexample'), 'токен Страницы Facebook читает graph.facebook.com по IG User ID');
    assert_true(\App\Models\Setting::isSecret('instagram_feed_token'), 'токен ленты шифруется как остальные секреты');
});

test('Instagram: блок выводит кадры ссылками в Instagram с понятным именем', function () {
    $html = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => [
            'title' => 'Мы в Instagram', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 4, 'ratio' => '1-1', 'caption_lines' => 2, 'foot_align' => 'split',
            'captions' => true, 'all_text' => 'Подписаться', 'all_url' => '',
            'posts' => instagram_sample_posts(), 'profile_url' => 'https://www.instagram.com/asdr_uz/',
        ],
    ]);

    assert_contains('href="https://www.instagram.com/p/AAA/"', $html);
    assert_contains('rel="noopener noreferrer"', $html);
    assert_contains('aria-label="Fransiya taraqqiyot agentligi bilan uchrashuv', $html, 'имя ссылки — начало подписи поста');
    assert_contains('href="https://www.instagram.com/asdr_uz/"', $html, 'ссылка на профиль из настроек ленты');
    assert_contains('ig-feed__follow', $html, 'подписка — кнопкой');
    assert_contains('#tabler-brand-instagram', $html, 'со знаком Instagram');
    assert_true(strpos($html, 'ig-feed__follow') > strpos($html, 'ig-feed__grid'), 'кнопка под кадрами, а не в шапке');
    assert_not_contains('section-head__all', $html);
    assert_contains('ig-feed__kind', $html, 'ролик отмечен значком');
    // Подпись из одной фразы целиком становится заголовком карточки; второй
    // раз под ним она не печатается.
    assert_contains('<span class="ig-feed__title" aria-hidden="true">Fransiya taraqqiyot agentligi bilan uchrashuv</span>', $html);
    assert_not_contains('ig-feed__caption', $html);
});

test('Instagram: пустая лента не выводит ни блока, ни виджета', function () {
    $block = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => ['title' => 'Instagram', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 4, 'ratio' => '1-1', 'caption_lines' => 2, 'foot_align' => 'split', 'captions' => false, 'all_text' => '', 'posts' => []],
    ]);
    assert_same('', trim($block));

    $widget = render_view('templates/widgets/instagram_feed.php', ['data' => ['posts' => []], 'lang' => 'uz']);
    assert_same('', trim($widget));

    $filled = render_view('templates/widgets/instagram_feed.php', [
        'data' => ['posts' => instagram_sample_posts(), 'profile_url' => 'https://www.instagram.com/asdr_uz/'],
        'lang' => 'ru',
    ]);
    assert_contains('widget-instagram', $filled);
    assert_not_contains('ig-feed__caption', $filled, 'в сайдбаре подписей нет — только кадры');
});

test('Instagram: пост узнаётся по ссылке, с какого бы адреса она ни была скопирована', function () {
    assert_same('AAA', InstagramFeed::shortcode('https://www.instagram.com/p/AAA/'));
    assert_same('BBB', InstagramFeed::shortcode('https://instagram.com/reel/BBB/?igsh=xyz'));
    assert_same('BBB', InstagramFeed::shortcode('https://www.instagram.com/asdr_uz/reel/BBB'));
    assert_same('', InstagramFeed::shortcode('https://www.instagram.com/asdr_uz/'), 'ссылка на профиль — не пост');
});

test('Instagram: выбранные по ссылкам идут в порядке ссылок, чужие называются (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('instagram_feed_posts', (string) json_encode(instagram_sample_posts()));
    $picked = InstagramFeed::picked("https://www.instagram.com/reel/BBB/\n\nhttps://www.instagram.com/p/AAA/?img_index=1\nhttps://www.instagram.com/p/ZZZ/");

    assert_same(['BBB', 'AAA'], array_map(fn ($post) => InstagramFeed::shortcode($post['permalink']), $picked['posts']));
    assert_same(['https://www.instagram.com/p/ZZZ/'], $picked['missing']);
    assert_contains('ZZZ', implode(' ', \App\Core\BlockHints::forBlock('instagram_feed', [
        'order' => 'pick', 'links' => 'https://www.instagram.com/p/ZZZ/',
    ])), 'редактор узнаёт, какой ссылки нет в ленте');
});

test('Instagram: случайный порядок прячет лишнее до перемешивания, дата и одна колонка выводятся', function () {
    $posts = instagram_sample_posts();
    $posts[0]['timestamp'] = '2026-10-10T09:00:00+0000';
    $html = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => [
            'title' => '', 'order' => 'random', 'limit' => 1, 'layout' => 'grid', 'columns' => 1, 'ratio' => '4-5', 'caption_lines' => 2, 'foot_align' => 'split',
            'captions' => false, 'dates' => true, 'all_text' => '', 'posts' => $posts,
        ],
    ]);

    assert_contains('data-ig-shuffle="1"', $html, 'перемешивает браузер: страница кэшируется общим ключом');
    assert_same(1, substr_count($html, '<li class="ig-feed__cell" hidden>'), 'без скрипта виден лимит — последние по дате');
    assert_contains('ig-feed--cols-1', $html);
    assert_contains('<time class="ig-feed__date" datetime="2026-10-10"', $html);
    assert_contains('10 октября 2026 г.', $html);

    $plain = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => ['title' => '', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 4, 'ratio' => '1-1', 'caption_lines' => 2, 'foot_align' => 'split', 'captions' => false, 'all_text' => '', 'posts' => $posts],
    ]);
    assert_not_contains('data-ig-shuffle', $plain);
    assert_not_contains('ig-feed__date', $plain, 'дата — только по галочке');
});

test('Instagram: один кадр в ряду — карусель со стрелками, кадр в пропорции поста', function () {
    $render = static fn (array $over): string => render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => $over + [
            'title' => '', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 1, 'ratio' => '4-5', 'caption_lines' => 2, 'foot_align' => 'split',
            'captions' => false, 'all_text' => '', 'posts' => instagram_sample_posts(),
        ],
    ]);

    // Столбец из восьми кадров во всю ширину колонки читается лентой, которую
    // прокручивают мимо, — один кадр в ряду листается.
    $one = $render([]);
    assert_contains('data-carousel', $one);
    assert_contains('ig-feed--row', $one, 'одна колонка раскладывается полосой');
    assert_contains('data-carousel-track', $one);
    assert_same(2, substr_count($one, 'data-carousel-item'));
    assert_contains('data-carousel-nav', $one, 'стрелки и точки');
    assert_contains('ig-feed--ratio-4-5', $one, 'пост 4:5 не срезается квадратом');

    $grid = $render(['columns' => 4, 'ratio' => '1-1']);
    assert_not_contains('data-carousel', $grid, 'сетка остаётся сеткой');
    assert_contains('ig-feed--ratio-1-1', $grid);

    assert_contains('data-carousel-nav', $render(['layout' => 'row', 'columns' => 4]), '«Карусель» получает стрелки при любом числе колонок');
    assert_not_contains('data-carousel', $render(['order' => 'random', 'limit' => 1]), 'один видимый кадр листать некуда');
});

test('Instagram: число строк подписи задаёт длину выдержки', function () {
    $posts = instagram_sample_posts();
    $posts[0]['caption'] = str_repeat('Strategik rivojlanish agentligi yangiliklari ', 8) . 'OXIRGI';
    $render = static fn (int $lines): string => render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => [
            'title' => '', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 4, 'ratio' => '1-1',
            'caption_lines' => $lines, 'foot_align' => 'split', 'captions' => true, 'all_text' => '', 'posts' => $posts,
        ],
    ]);

    // Строки обрезает CSS (--ig-caption-lines в scoped CSS), но текста под
    // шесть строк выдержка на две не даёт: окно осталось бы полупустым.
    assert_not_contains('OXIRGI</span>', $render(2), 'две строки — короткая выдержка');
    assert_contains('OXIRGI</span>', $render(6), 'шесть строк — подпись целиком');
    assert_contains('ig-feed__item--card', $render(10), 'с подписью публикация — карточка, как у новости');
});

test('Instagram: заголовок поста полужирный, подпись под ним обычная и без повтора', function () {
    $posts = instagram_sample_posts();
    $posts[0]['caption'] = 'Agentlik forum oʻtkazdi. Unda 200 dan ortiq ishtirokchi qatnashdi.';
    $posts[0]['title'] = 'Agentlik strategik forumi';
    $posts[1]['caption'] = "🔥 Yangi loyiha taqdimoti boʻlib oʻtdi! Batafsil havolada #ASDR";
    $posts[1]['title'] = '';
    $html = render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => [
            'title' => '', 'order' => 'date', 'limit' => 8, 'layout' => 'carousel', 'columns' => 4, 'ratio' => '1-1',
            'caption_lines' => 4, 'foot_align' => 'split', 'captions' => true, 'all_text' => '', 'posts' => $posts,
        ],
    ]);

    assert_contains('<span class="ig-feed__title" aria-hidden="true">Agentlik strategik forumi</span>', $html, 'заголовок модели — отдельной строкой');
    assert_contains('Agentlik forum oʻtkazdi.', $html, 'подпись выводится целиком под ним');
    assert_contains('aria-label="Agentlik strategik forumi', $html, 'имя ссылки — заголовок');

    // Без заголовка модели берётся первая фраза подписи, и под ним она не повторяется.
    assert_same('Yangi loyiha taqdimoti boʻlib oʻtdi!', \App\Core\InstagramPostTitle::fallback($posts[1]['caption']));
    assert_contains('<span class="ig-feed__caption" aria-hidden="true">Batafsil havolada #ASDR</span>', $html, 'первая фраза не повторяется в подписи');
    assert_same('', \App\Core\InstagramPostTitle::fallback('🔥🔥 #tag'), 'из одних эмодзи и меток заголовка нет');
});

test('Instagram: голый кадр — плитка, дата с подписью — карточка; низ ленты выравнивается', function () {
    $render = static fn (array $over): string => render_view('templates/blocks/instagram_feed.php', [
        'blockId' => 7,
        'data' => $over + [
            'title' => '', 'order' => 'date', 'limit' => 8, 'layout' => 'grid', 'columns' => 4, 'ratio' => '1-1',
            'caption_lines' => 2, 'foot_align' => 'split', 'captions' => false, 'dates' => false,
            'all_text' => 'Подписаться', 'all_url' => '', 'profile_url' => 'https://www.instagram.com/asdr_uz/',
            'posts' => instagram_sample_posts(),
        ],
    ]);

    assert_not_contains('ig-feed__item--card', $render([]), 'без даты и подписи рамка вокруг картинки ничего не отделяет');
    $posts = instagram_sample_posts();
    $posts[0]['timestamp'] = '2026-10-10T09:00:00+0000';
    $dated = $render(['dates' => true, 'posts' => $posts]);
    assert_contains('ig-feed__item--card', $dated);
    assert_contains('ig-feed__body', $dated);
    assert_contains('#tabler-calendar', $dated, 'дата со значком, как у карточки новости');

    assert_contains('ig-feed--foot-split', $render([]));
    assert_contains('ig-feed--foot-center', $render(['foot_align' => 'center']));
});

test('Instagram: ошибка Meta переводится в действие', function () {
    assert_contains('Токен недействителен или истёк', InstagramFeed::graphHint(['code' => 190, 'message' => 'Error validating access token']));
    assert_contains('проверьте IG User ID', InstagramFeed::graphHint(['code' => 100, 'message' => 'Unsupported get request']));
    assert_contains('instagram_basic', InstagramFeed::graphHint(['code' => 10]));
    assert_contains('instagram_basic', InstagramFeed::graphHint(['code' => 200]));
    assert_contains('частоту запросов', InstagramFeed::graphHint(['code' => 4]));
});

test('Instagram: проверка подключения идёт по шагам и называет непрошедший (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('instagram_feed_token', '');
    \App\Models\Setting::set('social_instagram_token', 'EAApage');
    \App\Models\Setting::set('social_instagram_user_id', '178414');

    $ok = InstagramFeed::check(function (string $url): array {
        assert_contains('access_token=EAApage', $url, 'токен уходит параметром запроса');
        $body = match (true) {
            str_contains($url, '/me?') => '{"id":"1","name":"ASDR"}',
            str_contains($url, '/media?') => '{"data":[{"id":"9"}]}',
            default => '{"username":"asdr_uz"}',
        };

        return ['status' => 200, 'body' => $body, 'error' => ''];
    });
    assert_true($ok['ok']);
    assert_same(['Доступ', 'Токен', 'Аккаунт Instagram', 'Чтение публикаций'], array_column($ok['steps'], 'name'));
    assert_contains('@asdr_uz', $ok['steps'][2]['text']);

    $wrongId = InstagramFeed::check(fn (string $url): array => str_contains($url, '/me?')
        ? ['status' => 200, 'body' => '{"id":"1","name":"ASDR"}', 'error' => '']
        : ['status' => 400, 'body' => '{"error":{"code":100,"message":"Unsupported get request"}}', 'error' => '']);
    assert_false($wrongId['ok']);
    assert_same('Аккаунт Instagram', $wrongId['steps'][2]['name'], 'живой токен с чужим IG User ID — проблема аккаунта, а не токена');

    $offline = InstagramFeed::check(fn (string $url): array => ['status' => 0, 'body' => '', 'error' => 'Could not resolve host']);
    assert_contains('хостинг блокирует', $offline['steps'][1]['text'], 'нет сети — не «неверный токен»');

    \App\Models\Setting::set('instagram_feed_token', 'IGAAown');
    $own = InstagramFeed::check(fn (string $url): array => [
        'status' => 200,
        'body' => str_contains($url, '/media?') ? '{"data":[]}' : '{"user_id":"5","username":"asdr_uz"}',
        'error' => '',
    ]);
    assert_true($own['ok']);
    assert_same(['Доступ', 'Токен', 'Чтение публикаций'], array_column($own['steps'], 'name'), 'у Instagram Login IG User ID не нужен');
    assert_contains('пока нет публикаций', $own['steps'][2]['text']);
});

// Примеры с живого сайта: trim() по байтам отрезал у «л» байт, общий с «»»,
// у «“» — байты, общие с «—», и вместо буквы вставал «?»; грубая обрезка до
// 90 символов шла раньше обрезки по слову, а открывающая кавычка снималась и
// тогда, когда закрывающая оставалась в тексте.
test('Instagram: запасной заголовок не ломает буквы, слова и кавычки', function () {
    $ru = \App\Core\InstagramPostTitle::fallback("📊 В разделе «Исследования и аналитика» опубликован первый материал\n\nЛаванда, роза…");
    assert_same('В разделе «Исследования и аналитика» опубликован первый материал', $ru);

    $uz = \App\Core\InstagramPostTitle::fallback('“Tahlil va tadqiqotlar” boʻlimida birinchi material eʼlon qilindi');
    assert_same('“Tahlil va tadqiqotlar” boʻlimida birinchi material eʼlon qilindi', $uz);

    $caption = '«Özbekiston — 2030» strategiyasining 48-maqsadi sanoatning drayver sohalarini rivojlantirish va hududlarning sanoat salohiyatidan toʻliq foydalanishga qaratilgan.';
    $long = \App\Core\InstagramPostTitle::fallback($caption);
    assert_true(str_starts_with($long, '«Özbekiston — 2030»'), 'парная кавычка остаётся');
    assert_true(str_ends_with($long, 'sohalarini…'), 'обрезка по слову с многоточием: ' . $long);
    assert_true(mb_strlen($long) <= \App\Core\InstagramPostTitle::MAX_LENGTH);
    assert_true(str_starts_with(\App\Core\InstagramPostTitle::rest($caption, $long), 'rivojlantirish va'), 'подпись продолжает с целого слова');

    assert_same('Agentlik forumi', \App\Core\InstagramPostTitle::clean('«Agentlik forumi'), 'кавычка без пары снимается');
});
