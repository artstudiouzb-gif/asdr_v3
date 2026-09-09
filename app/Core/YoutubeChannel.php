<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Чтение публичного списка роликов YouTube-канала: название, описание,
 * ссылка и адрес кадра-превью. Само видео не скачивается — оно остаётся на
 * YouTube, сайт хранит только карточку.
 *
 * Два источника данных:
 *  - RSS-лента канала (`/feeds/videos.xml`) — работает без ключа API и без
 *    Composer-зависимостей, отдаёт последние 15 роликов с полным описанием;
 *  - Data API v3 — если в настройках задан ключ: больше 15 роликов за проход
 *    и длительность ролика, которой в RSS нет.
 *
 * Разбор ответа отделён от сети (parseFeed/parseApiItems), чтобы проверять
 * его тестами без обращения к YouTube.
 */
final class YoutubeChannel
{
    private const FEED_URL = 'https://www.youtube.com/feeds/videos.xml';
    private const API_URL = 'https://www.googleapis.com/youtube/v3/';

    /** Страница канала весит несколько мегабайт — id лежит в самом начале. */
    private const CHANNEL_PAGE_MAX_BYTES = 6291456;

    public const MAX_ITEMS = 50;

    /**
     * Разбирает то, что ввёл редактор: ссылку на канал, @псевдоним, UC-id,
     * ссылку на плейлист или на конкретный ролик канала.
     *
     * @return array{type:string, value:string}|null type: channel|playlist|handle|user
     */
    public static function parseSource(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('#[?&]list=(PL[A-Za-z0-9_-]{10,}|UU[A-Za-z0-9_-]{10,}|FL[A-Za-z0-9_-]{10,})#', $input, $m)) {
            return ['type' => 'playlist', 'value' => $m[1]];
        }
        if (preg_match('#youtube\.com/(?:channel/)(UC[A-Za-z0-9_-]{22})#', $input, $m)) {
            return ['type' => 'channel', 'value' => $m[1]];
        }
        if (preg_match('#youtube\.com/(?:user|c)/([A-Za-z0-9_.-]{2,100})#', $input, $m)) {
            return ['type' => 'user', 'value' => $m[1]];
        }
        if (preg_match('#youtube\.com/@([A-Za-z0-9_.-]{2,100})#', $input, $m)) {
            return ['type' => 'handle', 'value' => $m[1]];
        }
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $input)) {
            return ['type' => 'channel', 'value' => $input];
        }
        if (preg_match('/^(PL|UU|FL)[A-Za-z0-9_-]{10,}$/', $input)) {
            return ['type' => 'playlist', 'value' => $input];
        }
        if (preg_match('/^@([A-Za-z0-9_.-]{2,100})$/', $input, $m)) {
            return ['type' => 'handle', 'value' => $m[1]];
        }
        // Голое имя без @ трактуем как псевдоним канала: так его чаще всего и
        // копируют из адресной строки.
        if (preg_match('/^[A-Za-z0-9_.-]{2,100}$/', $input)) {
            return ['type' => 'handle', 'value' => $input];
        }

        return null;
    }

    /** Адрес RSS-ленты источника; для @псевдонима нужен предварительно найденный id канала. */
    public static function feedUrl(array $source): ?string
    {
        return match ($source['type']) {
            'channel' => self::FEED_URL . '?channel_id=' . rawurlencode($source['value']),
            'playlist' => self::FEED_URL . '?playlist_id=' . rawurlencode($source['value']),
            'user' => self::FEED_URL . '?user=' . rawurlencode($source['value']),
            default => null,
        };
    }

    /** Человеческий адрес канала — для ссылки «Открыть канал» в админке. */
    public static function channelUrl(array $source): string
    {
        return match ($source['type']) {
            'channel' => 'https://www.youtube.com/channel/' . $source['value'],
            'playlist' => 'https://www.youtube.com/playlist?list=' . $source['value'],
            'user' => 'https://www.youtube.com/user/' . $source['value'],
            default => 'https://www.youtube.com/@' . $source['value'],
        };
    }

    /**
     * Находит UC-id канала по его странице (@псевдоним или /c/Имя): RSS-лента
     * умеет только channel_id, playlist_id и старый user. Результат вызывающий
     * код запоминает в настройках, чтобы не ходить сюда каждый раз.
     */
    public static function resolveChannelId(array $source): ?string
    {
        if ($source['type'] === 'channel') {
            return $source['value'];
        }
        $url = self::channelUrl($source);
        $res = Http::getSafeRemote($url, ['Accept-Language: ru,en;q=0.8'], 20, self::CHANNEL_PAGE_MAX_BYTES);
        if ((int) $res['status'] !== 200 || $res['body'] === '') {
            return null;
        }

        return self::channelIdFromHtml($res['body']);
    }

    /** Вытаскивает UC-id из HTML страницы канала (canonical или поле channelId). */
    public static function channelIdFromHtml(string $html): ?string
    {
        if (preg_match('#/channel/(UC[A-Za-z0-9_-]{22})#', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/"(?:channelId|externalId)"\s*:\s*"(UC[A-Za-z0-9_-]{22})"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Список последних роликов источника.
     *
     * @return array{ok:bool, error:string, items:array<int,array<string,mixed>>, channel_id:string}
     */
    public static function fetch(string $input, int $limit = 15, string $apiKey = '', string $knownChannelId = ''): array
    {
        $source = self::parseSource($input);
        if ($source === null) {
            return self::fail('Не удалось разобрать адрес канала. Укажите ссылку вида https://www.youtube.com/@канал или id канала UC…');
        }
        $limit = max(1, min(self::MAX_ITEMS, $limit));
        $apiKey = trim($apiKey);

        $channelId = $source['type'] === 'channel' ? $source['value'] : trim($knownChannelId);
        if ($channelId === '' && $source['type'] !== 'playlist' && $apiKey === '') {
            $channelId = (string) (self::resolveChannelId($source) ?? '');
            if ($channelId === '') {
                return self::fail('YouTube не отдал id канала по этому адресу. Проверьте ссылку или укажите id канала UC….');
            }
        }
        if ($channelId !== '') {
            $source = ['type' => 'channel', 'value' => $channelId];
        }

        $result = $apiKey !== ''
            ? self::fetchViaApi($source, $limit, $apiKey)
            : self::fetchViaFeed($source, $limit);
        $result['channel_id'] = $result['channel_id'] !== '' ? $result['channel_id'] : $channelId;

        return $result;
    }

    /** @return array{ok:bool, error:string, items:array<int,array<string,mixed>>, channel_id:string} */
    private static function fetchViaFeed(array $source, int $limit): array
    {
        $url = self::feedUrl($source);
        if ($url === null) {
            return self::fail('Для этого адреса нужен id канала UC… или ключ API.');
        }
        $res = Http::getSafeRemote($url, ['Accept: application/atom+xml'], 20);
        if ((int) $res['status'] === 404) {
            return self::fail('YouTube не нашёл такой канал (404). Проверьте адрес.');
        }
        if ((int) $res['status'] !== 200 || trim($res['body']) === '') {
            $why = $res['error'] !== '' ? ' (' . $res['error'] . ')' : '';
            return self::fail('Не удалось получить ленту канала: ответ ' . (int) $res['status'] . $why);
        }

        $items = self::parseFeed($res['body']);
        if ($items === []) {
            return self::fail('В ленте канала нет роликов.');
        }

        return ['ok' => true, 'error' => '', 'items' => array_slice($items, 0, $limit), 'channel_id' => ''];
    }

    /**
     * Разбор RSS-ленты канала (Atom + пространство имён media).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseFeed(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($feed === false) {
            return [];
        }

        $items = [];
        foreach ($feed->entry ?? [] as $entry) {
            $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
            $media = $entry->children('http://search.yahoo.com/mrss/');
            $group = $media->group ?? null;

            $videoId = trim((string) ($yt->videoId ?? ''));
            if ($videoId === '') {
                $videoId = (string) (Video::youtubeId((string) ($entry->link['href'] ?? '')) ?? '');
            }
            if ($videoId === '') {
                continue;
            }

            $thumb = '';
            if ($group !== null && isset($group->thumbnail)) {
                $attributes = $group->thumbnail->attributes();
                $thumb = $attributes !== null ? trim((string) ($attributes['url'] ?? '')) : '';
            }

            $items[] = self::item(
                $videoId,
                (string) ($group->title ?? $entry->title ?? ''),
                (string) ($group->description ?? ''),
                (string) ($entry->published ?? $entry->updated ?? ''),
                $thumb,
                ''
            );
        }

        return $items;
    }

    /**
     * Data API v3: список загрузок канала + длительность роликов.
     *
     * @return array{ok:bool, error:string, items:array<int,array<string,mixed>>, channel_id:string}
     */
    private static function fetchViaApi(array $source, int $limit, string $apiKey): array
    {
        $channelId = '';
        if ($source['type'] === 'playlist') {
            $playlist = $source['value'];
        } else {
            $params = match ($source['type']) {
                'channel' => ['id' => $source['value']],
                'user' => ['forUsername' => $source['value']],
                default => ['forHandle' => '@' . $source['value']],
            };
            $channel = self::api('channels', $params + ['part' => 'contentDetails'], $apiKey);
            if (!$channel['ok']) {
                return self::fail($channel['error']);
            }
            $item = $channel['data']['items'][0] ?? null;
            if (!is_array($item)) {
                return self::fail('Канал не найден по ключу API. Проверьте адрес канала.');
            }
            $channelId = (string) ($item['id'] ?? '');
            $playlist = (string) ($item['contentDetails']['relatedPlaylists']['uploads'] ?? '');
            if ($playlist === '') {
                return self::fail('У канала нет плейлиста загрузок.');
            }
        }

        $items = [];
        $pageToken = '';
        $pages = 0;
        while (count($items) < $limit && $pages++ < 5) {
            $page = self::api('playlistItems', array_filter([
                'part' => 'snippet',
                'playlistId' => $playlist,
                'maxResults' => (string) min(50, $limit - count($items)),
                'pageToken' => $pageToken,
            ]), $apiKey);
            if (!$page['ok']) {
                return self::fail($page['error']);
            }
            $items = array_merge($items, self::parseApiItems($page['data']));
            $pageToken = (string) ($page['data']['nextPageToken'] ?? '');
            if ($pageToken === '') {
                break;
            }
        }
        if ($items === []) {
            return self::fail('На канале нет роликов.');
        }
        $items = array_slice($items, 0, $limit);

        $durations = self::fetchDurations(
            array_map(static fn (array $item): string => (string) $item['youtube_id'], $items),
            $apiKey
        );
        foreach ($items as $i => $item) {
            $items[$i]['duration'] = $durations[$item['youtube_id']] ?? '';
        }

        return ['ok' => true, 'error' => '', 'items' => $items, 'channel_id' => $channelId];
    }

    /**
     * Разбор ответа playlistItems.list.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public static function parseApiItems(array $data): array
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $row) {
            $snippet = (array) ($row['snippet'] ?? []);
            $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');
            if ($videoId === '' || Video::youtubeId($videoId) === null) {
                continue;
            }
            $thumbs = (array) ($snippet['thumbnails'] ?? []);
            $thumb = '';
            foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
                if (isset($thumbs[$size]['url'])) {
                    $thumb = (string) $thumbs[$size]['url'];
                    break;
                }
            }
            $items[] = self::item(
                $videoId,
                (string) ($snippet['title'] ?? ''),
                (string) ($snippet['description'] ?? ''),
                (string) ($snippet['publishedAt'] ?? ''),
                $thumb,
                ''
            );
        }

        return $items;
    }

    /**
     * Длительности роликов (videos.list отдаёт до 50 id за запрос).
     *
     * @param array<int, string> $ids
     * @return array<string, string>
     */
    private static function fetchDurations(array $ids, string $apiKey): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            $res = self::api('videos', ['part' => 'contentDetails', 'id' => implode(',', $chunk)], $apiKey);
            if (!$res['ok']) {
                return $out;
            }
            foreach ((array) ($res['data']['items'] ?? []) as $row) {
                $id = (string) ($row['id'] ?? '');
                $iso = (string) ($row['contentDetails']['duration'] ?? '');
                if ($id !== '' && $iso !== '') {
                    $out[$id] = self::durationFromIso($iso);
                }
            }
        }

        return $out;
    }

    /** PT1H2M3S → 1:02:03, PT4M5S → 04:05. */
    public static function durationFromIso(string $iso): string
    {
        if (!preg_match('/^P(?:\d+D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', trim($iso), $m)) {
            return '';
        }
        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $sec = (int) ($m[3] ?? 0);
        if ($h === 0 && $min === 0 && $sec === 0) {
            return '';
        }

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $min, $sec)
            : sprintf('%02d:%02d', $min, $sec);
    }

    /**
     * @param array<string, string> $query
     * @return array{ok:bool, error:string, data:array<string, mixed>}
     */
    private static function api(string $method, array $query, string $apiKey): array
    {
        $url = self::API_URL . $method . '?' . http_build_query($query + ['key' => $apiKey]);
        $res = Http::getSafeRemote($url, ['Accept: application/json'], 20);
        $data = json_decode($res['body'] !== '' ? $res['body'] : '[]', true);
        $data = is_array($data) ? $data : [];

        if ((int) $res['status'] !== 200) {
            $reason = (string) ($data['error']['message'] ?? $res['error']);
            return [
                'ok' => false,
                'error' => 'YouTube API ответил ' . (int) $res['status'] . ($reason !== '' ? ': ' . $reason : ''),
                'data' => [],
            ];
        }

        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    /** @return array<string, mixed> */
    private static function item(
        string $videoId,
        string $title,
        string $description,
        string $published,
        string $thumbnail,
        string $duration
    ): array {
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)) ?? '');
        $ts = $published !== '' ? strtotime($published) : false;

        return [
            'youtube_id' => $videoId,
            'title' => $title,
            'description' => trim(strip_tags($description)),
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'thumbnail' => $thumbnail !== '' ? $thumbnail : Video::youtubeThumbnail($videoId),
            'published_at' => $ts !== false ? date('Y-m-d H:i:s', $ts) : null,
            'duration' => $duration,
        ];
    }

    /** @return array{ok:bool, error:string, items:array<int,array<string,mixed>>, channel_id:string} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'items' => [], 'channel_id' => ''];
    }
}
