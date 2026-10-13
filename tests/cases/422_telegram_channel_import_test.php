<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\TelegramChannelImporter;

/*
 * Импорт новостей из выгрузки Telegram-канала (Telegram Desktop, JSON).
 *
 * Риски тихие: альбом, разложенный выгрузкой на отдельные сообщения, дал бы
 * новость на каждое фото; репост чужого канала стал бы новостью ведомства;
 * пост, который сайт сам когда-то отправил в канал, удвоил бы ленту; повтор
 * после обрыва — дубли.
 */

function tg_export(): array
{
    return ['name' => 'ASDR', 'type' => 'public_channel', 'messages' => [
        ['id' => 1, 'type' => 'service', 'action' => 'create_channel', 'date_unixtime' => '1700000000', 'text' => ''],
        // Альбом: подпись у первого фото, ещё два — той же секундой.
        ['id' => 2, 'type' => 'message', 'date' => '2024-03-01T10:00:00', 'date_unixtime' => '1709280000',
            'photo' => 'photos/a.jpg', 'text_entities' => [
                ['type' => 'plain', 'text' => "📌 Agentlik yangi loyihani taqdim etdi 🔥\n\nBugun "],
                ['type' => 'bold', 'text' => 'muhim'],
                ['type' => 'plain', 'text' => " uchrashuv bo'ldi va loyiha bilan tanishtirildi.\nBatafsil: "],
                ['type' => 'text_link', 'text' => 'sayt', 'href' => 'https://example.uz/x'],
                ['type' => 'plain', 'text' => "\n\n"],
                ['type' => 'hashtag', 'text' => '#ASDR'],
                ['type' => 'plain', 'text' => ' '],
                ['type' => 'hashtag', 'text' => '#loyiha'],
            ]],
        ['id' => 3, 'type' => 'message', 'date_unixtime' => '1709280000', 'photo' => 'photos/b.jpg', 'text_entities' => []],
        ['id' => 4, 'type' => 'message', 'date_unixtime' => '1709280001', 'photo' => '(File not included. Change data exporting settings to download.)', 'text_entities' => []],
        // Репост чужого канала с фото-альбомом.
        ['id' => 5, 'type' => 'message', 'date_unixtime' => '1709290000', 'forwarded_from' => 'Gov.uz', 'photo' => 'photos/c.jpg',
            'text_entities' => [['type' => 'plain', 'text' => 'Чужая новость']]],
        ['id' => 6, 'type' => 'message', 'date_unixtime' => '1709290000', 'forwarded_from' => 'Gov.uz', 'photo' => 'photos/d.jpg', 'text_entities' => []],
        // Длинная первая строка на русском, старый формат текста строкой.
        ['id' => 7, 'type' => 'message', 'date_unixtime' => '1709300000',
            'text' => 'Агентство провело совещание с представителями министерств. На встрече обсуждались вопросы реализации стратегии развития и план работ на следующий год, а также сроки и ответственные лица.'],
        ['id' => 8, 'type' => 'message', 'date_unixtime' => '1709310000', 'poll' => ['question' => '?'], 'text' => ''],
        ['id' => 9, 'type' => 'message', 'date_unixtime' => '1709320000', 'photo' => 'photos/e.jpg', 'text_entities' => []],
    ]];
}

test('Разбор выгрузки: альбом — один пост, репосты и опросы пропущены с причиной', function (): void {
    $data = TelegramChannelImporter::parse(tg_export());

    assert_same('ASDR', $data['channel']);
    assert_same([2, 7], array_column($data['posts'], 'id'), 'альбом не дробится, служебное и чужое — не посты');
    assert_same(['photos/a.jpg', 'photos/b.jpg'], $data['posts'][0]['photos'], 'фото альбома собраны в один пост');
    assert_same(1, $data['posts'][0]['missing'], 'невыгруженное фото посчитано, а не потеряно молча');
    assert_same('2024-03-01 10:00:00', $data['posts'][0]['date']);
    $reasons = array_column($data['skipped'], 'reason', 'id');
    assert_same('репост из «Gov.uz»', $reasons[5], 'репост узнаётся по forwarded_from');
    assert_same('репост из «Gov.uz»', $reasons[6], 'и фото его альбома тоже');
    assert_same('опрос', $reasons[8]);
    assert_same('нет текста', $reasons[9], 'одиночное фото без подписи новостью не становится');
});

test('Пост → новость: заголовок без значков, хештеги отдельно, разметка безопасна', function (): void {
    $posts = TelegramChannelImporter::parse(tg_export())['posts'];
    $news = TelegramChannelImporter::map($posts[0]);

    assert_same('Agentlik yangi loyihani taqdim etdi', $news['title'], 'значки в начале и конце строки сняты');
    assert_same('#ASDR #loyiha', $news['hashtags']);
    assert_same("<p>Bugun <strong>muhim</strong> uchrashuv bo&apos;ldi va loyiha bilan tanishtirildi.<br>Batafsil: <a href=\"https://example.uz/x\">sayt</a></p>", $news['content']);
    assert_not_contains('#ASDR', $news['content'], 'строка хештегов не попадает в текст');
    assert_same('uz', $news['lang']);
    assert_true(str_starts_with($news['excerpt'], 'Bugun muhim'), 'анонс — первый абзац текста');

    $long = TelegramChannelImporter::map($posts[1]);
    assert_same('Агентство провело совещание с представителями министерств.', $long['title'], 'длинная строка режется по предложению');
    assert_contains('Агентство провело совещание', $long['content'], 'и абзац остаётся в тексте целиком');
    assert_same('ru', $long['lang']);

    $evil = TelegramChannelImporter::map(['id' => 1, 'date' => '', 'photos' => [], 'videos' => 0, 'missing' => 0, 'runs' => [
        ['text' => "Заголовок\n", 'type' => 'plain', 'href' => ''],
        ['text' => '<b>x</b>', 'type' => 'text_link', 'href' => 'javascript:alert(1)'],
    ]]);
    assert_same('<p>&lt;b&gt;x&lt;/b&gt;</p>', $evil['content'], 'HTML экранирован, javascript-ссылка снята');
});

test('Ссылки на посты превращаются в номера сообщений', function (): void {
    $ids = TelegramChannelImporter::parseLinks(
        "https://t.me/asdruzbekistan/2933\nhttps://t.me/asdruzbekistan/2914?single, t.me/s/asdruzbekistan/2885\n"
        . "https://t.me/c/1234567/2877 2874 #2853 https://t.me/asdruzbekistan/2933 https://example.uz/12 мусор"
    );
    assert_same([2853, 2874, 2877, 2885, 2914, 2933], $ids, 'дубли схлопнуты, чужие ссылки и слова отброшены');
    assert_same([], TelegramChannelImporter::parseLinks('  '));
});

test('Язык поста определяется по тексту', function (): void {
    assert_same('ru', TelegramChannelImporter::detectLang('Совещание в агентстве'));
    assert_same('uz', TelegramChannelImporter::detectLang('Агентликда йиғилиш бўлиб ўтди'), 'узбекская кириллица — узбекский');
    assert_same('uz', TelegramChannelImporter::detectLang('Agentlikda yigʻilish boʻlib oʻtdi'));
    assert_same('en', TelegramChannelImporter::detectLang('The agency held a meeting with the ministries and partners in the region'));
});

test('Импорт создаёт черновики, переносит фото и не дублирует при повторе (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(4));
    $inbox = sys_get_temp_dir() . '/tg-inbox-' . $tag;
    $dir = $inbox . '/ChatExport_2026-10-13';
    mkdir($dir . '/photos', 0755, true);
    $image = imagecreatetruecolor(40, 30);
    foreach (['a', 'b', 'c', 'd'] as $name) {
        imagejpeg($image, $dir . '/photos/' . $name . '.jpg');
    }
    $export = tg_export();
    $export['messages'][1]['text_entities'][0]['text'] = "Loyiha {$tag} taqdim etildi\n\nBugun ";
    file_put_contents($dir . '/result.json', json_encode($export, JSON_UNESCAPED_UNICODE));

    // Пост, который сайт сам отправил в канал, уже лежит в ленте.
    $existing = 'Агентство провело совещание с представителями министерств.';
    $ruLang = \App\Models\Language::isActive('ru') ? 'ru' : \App\Models\Language::defaultCode();
    $pdo->prepare("INSERT INTO news (title, slug, excerpt, content, status, lang, created_at) VALUES (:t, :s, '', '', 'published', :l, NOW())")
        ->execute([':t' => $existing, ':s' => 'existing-' . $tag, ':l' => $ruLang]);

    $filesBefore = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
    $summary = TelegramChannelImporter::summary($inbox);
    assert_same(2, $summary['posts']);
    assert_same(4, $summary['skipped'], 'два сообщения репоста, опрос и фото без подписи');

    // Выбор ссылками: альбом находится по любому своему фото, репост и
    // номер вне выгрузки названы, а не потеряны молча.
    TelegramChannelImporter::saveSelection($inbox, [3, 5, 9999]);
    $picked = TelegramChannelImporter::summary($inbox);
    assert_same(1, $picked['posts'], 'выбран один пост — альбом по ссылке на второе фото');
    assert_same(['#5 — репост из «Gov.uz»', '#9999 — нет в выгрузке'], $picked['not_found']);
    $pickedDry = TelegramChannelImporter::run($inbox, true, 0, 30.0, null);
    assert_same(1, $pickedDry['total'], 'перенос видит тот же выбор, что сводка');
    TelegramChannelImporter::saveSelection($inbox, []);
    assert_same([], TelegramChannelImporter::loadSelection($inbox), 'пустой выбор снимает фильтр');

    $dry = TelegramChannelImporter::run($inbox, true, 0, 30.0, null);
    assert_same(1, $dry['planned'], 'проверка считает будущие новости');
    assert_same(1, $dry['exists'], 'и узнаёт пост, уже опубликованный на сайте');
    assert_same($filesBefore, (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(), 'проверка ничего не пишет');

    $run = TelegramChannelImporter::run($inbox, false, 0, 30.0, null);
    $state = json_decode((string) file_get_contents($inbox . '/.import-state.json'), true);
    $newsId = (int) ($state['posts'][2] ?? 0);
    try {
        assert_true($run['done']);
        assert_same(1, $run['imported']);
        assert_same(1, $run['missing'], 'невыгруженное фото названо');
        $row = $pdo->query('SELECT title, status, lang, image, published_at, hashtags FROM news WHERE id = ' . $newsId)->fetch(PDO::FETCH_ASSOC);
        assert_same('Loyiha ' . $tag . ' taqdim etildi', $row['title']);
        assert_same('draft', $row['status'], 'новости приходят черновиками');
        assert_same('2024-03-01 10:00:00', (string) $row['published_at'], 'дата — местное время поста');
        assert_true(str_starts_with((string) $row['image'], '/uploads/'), 'обложка — из медиатеки');
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM news_images WHERE news_id = ' . $newsId)->fetchColumn(), 'альбом стал галереей');
        assert_false(is_file($dir . '/photos/a.jpg'), 'фото перенесено, а не скопировано');
        assert_true(is_file($dir . '/photos/c.jpg'), 'фото репоста не тронуто');

        $again = TelegramChannelImporter::run($inbox, false, 0, 30.0, null);
        assert_same(0, $again['imported'], 'повтор не дублирует');
        assert_same(1, $again['skipped']);
    } finally {
        $uploads = rtrim((string) \App\Core\Config::get('paths.public_uploads'), '/');
        foreach ($pdo->query('SELECT path FROM news_images WHERE news_id = ' . $newsId)->fetchAll(PDO::FETCH_COLUMN) as $url) {
            @unlink($uploads . '/' . basename((string) $url));
            $pdo->prepare('DELETE FROM files WHERE stored_name = :n')->execute([':n' => basename((string) $url)]);
        }
        array_map('unlink', array_filter((array) glob($dir . '/{,.,*/}*', GLOB_BRACE), 'is_file'));
        array_map('unlink', array_filter((array) glob($inbox . '/.*'), 'is_file'));
    }
});
