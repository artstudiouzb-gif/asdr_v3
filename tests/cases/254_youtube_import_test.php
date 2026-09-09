<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\YoutubeChannel;
use App\Core\YoutubeImport;
use App\Models\Setting;
use App\Models\Video;

// Автозагрузка карточек роликов с YouTube-канала: название, описание, ссылка
// и кадр-превью. Сеть в тестах не трогаем — проверяем разбор ответов канала,
// разбор адреса и поведение записи в БД (повторный проход не плодит дубли).

function yt_test_feed(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
      xmlns:media="http://search.yahoo.com/mrss/"
      xmlns="http://www.w3.org/2005/Atom">
  <title>Агентство стратегического развития</title>
  <entry>
    <id>yt:video:dQw4w9WgXcQ</id>
    <yt:videoId>dQw4w9WgXcQ</yt:videoId>
    <title>Форум «Стратегия-2030»</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"/>
    <published>2026-08-01T10:00:00+00:00</published>
    <media:group>
      <media:title>Форум «Стратегия-2030»</media:title>
      <media:thumbnail url="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" width="480" height="360"/>
      <media:description>Итоги форума.
Второй абзац описания.</media:description>
    </media:group>
  </entry>
  <entry>
    <id>yt:video:aBcDeFgHiJk</id>
    <yt:videoId>aBcDeFgHiJk</yt:videoId>
    <title>Совещание по инвестициям</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=aBcDeFgHiJk"/>
    <published>2026-07-20T08:30:00+00:00</published>
    <media:group>
      <media:title>Совещание по инвестициям</media:title>
      <media:thumbnail url="https://i.ytimg.com/vi/aBcDeFgHiJk/hqdefault.jpg" width="480" height="360"/>
      <media:description></media:description>
    </media:group>
  </entry>
</feed>
XML;
}

test('YoutubeChannel: адрес канала разбирается во всех ходовых видах', function () {
    $cases = [
        'https://www.youtube.com/@asdr_uz' => ['handle', 'asdr_uz'],
        'https://www.youtube.com/@asdr_uz/videos' => ['handle', 'asdr_uz'],
        '@asdr_uz' => ['handle', 'asdr_uz'],
        'asdr_uz' => ['handle', 'asdr_uz'],
        'https://www.youtube.com/channel/UCabcdefghijklmnopqrstu1' => ['channel', 'UCabcdefghijklmnopqrstu1'],
        'UCabcdefghijklmnopqrstu1' => ['channel', 'UCabcdefghijklmnopqrstu1'],
        'https://www.youtube.com/c/StrategyAgency' => ['user', 'StrategyAgency'],
        'https://www.youtube.com/playlist?list=PLabcdefghijklmno' => ['playlist', 'PLabcdefghijklmno'],
    ];
    foreach ($cases as $input => [$type, $value]) {
        $parsed = YoutubeChannel::parseSource($input);
        assert_true($parsed !== null, $input . ' должен разбираться');
        assert_same($type, $parsed['type'], $input . ': тип источника');
        assert_same($value, $parsed['value'], $input . ': значение источника');
    }

    assert_true(YoutubeChannel::parseSource('') === null, 'пустая строка — не источник');
    assert_true(YoutubeChannel::parseSource('https://example.com/video') === null, 'чужой адрес — не источник');

    // Ленту канала запрашиваем по id, плейлист — по списку.
    assert_same(
        'https://www.youtube.com/feeds/videos.xml?channel_id=UCabcdefghijklmnopqrstu1',
        YoutubeChannel::feedUrl(['type' => 'channel', 'value' => 'UCabcdefghijklmnopqrstu1'])
    );
    assert_true(
        YoutubeChannel::feedUrl(['type' => 'handle', 'value' => 'asdr_uz']) === null,
        'по @псевдониму лента не строится — нужен id канала'
    );
});

test('YoutubeChannel: разбор ленты канала даёт карточку ролика без самого видео', function () {
    $items = YoutubeChannel::parseFeed(yt_test_feed());
    assert_same(2, count($items), 'разобраны оба ролика');

    $first = $items[0];
    assert_same('dQw4w9WgXcQ', $first['youtube_id']);
    assert_same('Форум «Стратегия-2030»', $first['title']);
    assert_contains('Итоги форума.', (string) $first['description']);
    assert_same('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $first['url']);
    assert_same('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $first['thumbnail']);
    assert_same('2026-08-01', substr((string) $first['published_at'], 0, 10));

    // Пустое описание — не повод терять ролик; кадр-превью есть всегда.
    assert_same('', $items[1]['description']);
    assert_true($items[1]['thumbnail'] !== '', 'у второго ролика тоже есть кадр');

    assert_same([], YoutubeChannel::parseFeed('не xml'), 'мусор вместо ленты не роняет разбор');
});

test('YoutubeChannel: ответ Data API и длительность ролика', function () {
    $items = YoutubeChannel::parseApiItems([
        'items' => [
            ['snippet' => [
                'title' => 'Брифинг',
                'description' => 'Текст описания',
                'publishedAt' => '2026-08-10T12:00:00Z',
                'resourceId' => ['videoId' => 'dQw4w9WgXcQ'],
                'thumbnails' => [
                    'high' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
                    'maxres' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg'],
                ],
            ]],
            ['snippet' => ['title' => 'Удалённый ролик', 'resourceId' => ['videoId' => '']]],
        ],
    ]);

    assert_same(1, count($items), 'ролик без id пропущен');
    assert_same('Брифинг', $items[0]['title']);
    assert_same(
        'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg',
        $items[0]['thumbnail'],
        'берётся кадр наибольшего размера'
    );

    assert_same('1:02:03', YoutubeChannel::durationFromIso('PT1H2M3S'));
    assert_same('04:05', YoutubeChannel::durationFromIso('PT4M5S'));
    assert_same('00:42', YoutubeChannel::durationFromIso('PT42S'));
    assert_same('', YoutubeChannel::durationFromIso('прямой эфир'));
});

test('YoutubeChannel: id канала находится на его странице', function () {
    $html = '<link rel="canonical" href="https://www.youtube.com/channel/UCabcdefghijklmnopqrstu1">';
    assert_same('UCabcdefghijklmnopqrstu1', YoutubeChannel::channelIdFromHtml($html));

    $json = '{"header":{"channelId":"UC1234567890abcdefghijkl"}}';
    assert_same('UC1234567890abcdefghijkl', YoutubeChannel::channelIdFromHtml($json));
    assert_true(YoutubeChannel::channelIdFromHtml('<html></html>') === null, 'нет id — нет догадок');
});

test('YoutubeImport: настройки, секретный ключ и смена канала (нужна тестовая БД)', function () {
    if (!Database::isConnected()) {
        skip_test('нет тестовой БД');
    }
    $keys = [
        'youtube_import_enabled', 'youtube_channel', 'youtube_channel_id', 'youtube_api_key',
        'youtube_import_limit', 'youtube_import_status', 'youtube_import_featured',
        'youtube_import_update', 'youtube_import_thumbs', 'youtube_last_sync', 'youtube_last_result',
    ];
    reset_design_state();
    $cleanup = static function () use ($keys): void {
        $in = implode(',', array_fill(0, count($keys), '?'));
        Database::pdo()->prepare("DELETE FROM settings WHERE `key` IN ($in)")->execute($keys);
        Setting::set('youtube_channel', ''); // заодно сбрасывает кэш настроек
    };
    $cleanup();

    YoutubeImport::saveSettings([
        'youtube_channel' => ' https://www.youtube.com/@asdr_uz ',
        'youtube_import_limit' => '999',
        'youtube_import_status' => 'published',
        'youtube_import_enabled' => '1',
        'youtube_api_key' => 'AIzaSyTestKey',
    ]);

    $cfg = YoutubeImport::settings();
    assert_same('https://www.youtube.com/@asdr_uz', $cfg['channel']);
    assert_same(YoutubeChannel::MAX_ITEMS, $cfg['limit'], 'запрос сверх предела обрезается');
    assert_same('published', $cfg['status']);
    assert_true($cfg['enabled'], 'автозагрузка включена');
    assert_true($cfg['featured'] === false && $cfg['update'] === false, 'снятые галочки сохраняются снятыми');
    assert_same('AIzaSyTestKey', $cfg['api_key']);
    assert_true(YoutubeImport::isConfigured(), 'канал разобран');

    // Ключ API — секрет: в таблице он лежит зашифрованным.
    $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = ?');
    $stmt->execute(['youtube_api_key']);
    assert_true(
        !str_contains((string) $stmt->fetchColumn(), 'AIzaSyTestKey'),
        'ключ API не хранится открытым текстом'
    );

    // Пустое поле ключа значит «оставить как есть», а не «стереть».
    Setting::set('youtube_channel_id', 'UCabcdefghijklmnopqrstu1');
    YoutubeImport::saveSettings(['youtube_channel' => 'https://www.youtube.com/@asdr_uz', 'youtube_api_key' => '']);
    assert_same('AIzaSyTestKey', YoutubeImport::settings()['api_key']);
    assert_same(
        'UCabcdefghijklmnopqrstu1',
        YoutubeImport::settings()['channel_id'],
        'канал тот же — найденный id сохраняется'
    );

    // Сменили канал — прежний найденный id забываем, иначе импорт продолжит
    // ходить на старый канал.
    YoutubeImport::saveSettings(['youtube_channel' => 'https://www.youtube.com/@other', 'youtube_api_key' => '']);
    assert_same('', YoutubeImport::settings()['channel_id']);

    YoutubeImport::saveSettings(['youtube_channel' => '@asdr_uz', 'youtube_api_key_clear' => '1']);
    assert_same('', YoutubeImport::settings()['api_key'], 'галочка стирает сохранённый ключ');

    $cleanup();
});

test('YoutubeImport: повторный проход узнаёт ролик и не плодит дубли (нужна тестовая БД)', function () {
    if (!Database::isConnected()) {
        skip_test('нет тестовой БД');
    }
    Database::pdo()->exec('DELETE FROM videos');

    $item = YoutubeChannel::parseFeed(yt_test_feed())[0];
    $id = Video::create((string) $item['title']);
    assert_true($id !== null, 'запись создана');

    Video::applyYoutube((int) $id, [
        'title' => (string) $item['title'],
        'description' => (string) $item['description'],
        'cover_url' => '/uploads/public/youtube-dQw4w9WgXcQ.jpg',
        'video_url' => (string) $item['url'],
        'duration' => '',
        'youtube_id' => (string) $item['youtube_id'],
        'published_at' => $item['published_at'],
    ], true);
    Video::setFlags((int) $id, false, true);

    $found = Video::findByYoutubeId('dQw4w9WgXcQ');
    assert_true($found !== null, 'ролик находится по id — второй записи не появится');
    assert_same('youtube', (string) $found['source']);
    assert_same('/uploads/public/youtube-dQw4w9WgXcQ.jpg', (string) $found['cover_url']);
    assert_same('2026-08-01', substr((string) $found['published_at'], 0, 10));
    assert_same(0, (int) $found['is_published'], 'новые ролики можно заводить черновиками');
    assert_same(1, (int) $found['is_featured']);

    // Редактор поправил название и описание. Пока обновление выключено,
    // проход не должен возвращать текст канала.
    Video::update((int) $id, 'Свой заголовок', 'Свой текст', (string) $found['cover_url'],
        (string) $found['video_url'], '02:35', true, true, 0);
    Video::applyYoutube((int) $id, [
        'title' => 'Название с канала',
        'description' => 'Описание с канала',
        'cover_url' => '',
        'video_url' => (string) $item['url'],
        'duration' => '',
        'youtube_id' => 'dQw4w9WgXcQ',
        'published_at' => $item['published_at'],
    ], false);

    $kept = Video::findById((int) $id);
    assert_same('Свой заголовок', (string) $kept['title'], 'правка редактора важнее источника');
    assert_same('Свой текст', (string) $kept['description']);
    assert_same('02:35', (string) $kept['duration'], 'своя длительность не стирается пустым значением');

    // С включённым обновлением текст канала приезжает.
    Video::applyYoutube((int) $id, [
        'title' => 'Название с канала',
        'description' => 'Описание с канала',
        'cover_url' => '',
        'video_url' => (string) $item['url'],
        'duration' => '',
        'youtube_id' => 'dQw4w9WgXcQ',
        'published_at' => $item['published_at'],
    ], true);
    $updated = Video::findById((int) $id);
    assert_same('Название с канала', (string) $updated['title']);
    assert_same('/uploads/public/youtube-dQw4w9WgXcQ.jpg', (string) $updated['cover_url'], 'обложка не теряется');

    Video::delete((int) $id);
});
