<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;
use App\Models\Setting;

/**
 * Последние публикации Instagram-аккаунта ведомства — для блока страницы и
 * виджета сайдбара.
 *
 * Публичная страница в сеть не ходит (тест 110), поэтому посты забирает
 * воркер по cron или кнопка в админке, а сайт показывает сохранённую копию.
 * Кадры при этом скачиваются в медиатеку (`RemoteImage`): ссылка Instagram на
 * снимок подписана и живёт несколько дней, а публичная CSP чужих картинок не
 * пропускает вовсе — с прямой ссылкой лента опустела бы через неделю.
 *
 * **Доступ — тот же, что у автопубликации.** Посты читает Graph API, и
 * читать их можно теми же данными, которыми сайт публикует новости в
 * Instagram (токен Страницы Facebook и IG User ID, `/admin/social`): второго
 * токена владельцу заводить не нужно. Свой токен у ленты бывает тоже — для
 * аккаунта, подключённого через «Instagram Login» (токен начинается с `IG`):
 * он читает `graph.instagram.com/me` и живёт 60 дней, поэтому проход его
 * продлевает сам (`refreshToken`).
 *
 * Хранится список одной настройкой (`instagram_feed_posts`, JSON): постов в
 * ленте дюжина, своей таблице здесь нечего индексировать.
 */
final class InstagramFeed
{
    public const MAX_POSTS = 24;

    private const POSTS_KEY = 'instagram_feed_posts';

    private const FACEBOOK_GRAPH = 'https://graph.facebook.com/v19.0';

    private const INSTAGRAM_GRAPH = 'https://graph.instagram.com';

    private const FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp';

    /** Токен Instagram Login продлевается не чаще раза в неделю (живёт 60 дней). */
    private const REFRESH_AFTER = 7 * 86400;

    /**
     * @return array{enabled: bool, own_token: bool, token: string, user_id: string, limit: int,
     *     username: string, profile_url: string, last_sync: string, last_result: string}
     */
    public static function settings(): array
    {
        $ownToken = trim(Setting::get('instagram_feed_token', ''));
        $username = trim(Setting::get('instagram_feed_username', ''));

        return [
            'enabled' => Setting::get('instagram_feed_enabled', '0') === '1',
            'own_token' => $ownToken !== '',
            'token' => $ownToken !== '' ? $ownToken : trim(Setting::get('social_instagram_token', '')),
            'user_id' => $ownToken !== '' && self::isInstagramLogin($ownToken)
                ? ''
                : trim(Setting::get('social_instagram_user_id', '')),
            'limit' => max(1, min(self::MAX_POSTS, (int) Setting::get('instagram_feed_limit', '12'))),
            'username' => $username,
            'profile_url' => $username !== '' ? 'https://www.instagram.com/' . rawurlencode($username) . '/' : '',
            'last_sync' => Setting::get('instagram_feed_last_sync', ''),
            'last_result' => Setting::get('instagram_feed_last_result', ''),
        ];
    }

    /** Хватает ли данных, чтобы спросить Instagram. */
    public static function isConfigured(): bool
    {
        $cfg = self::settings();

        return $cfg['token'] !== '' && (self::isInstagramLogin($cfg['token']) || $cfg['user_id'] !== '');
    }

    /**
     * Токен «Instagram Login» начинается с IG и читает graph.instagram.com;
     * токен Страницы Facebook (EAA…) — graph.facebook.com по IG User ID.
     */
    public static function isInstagramLogin(string $token): bool
    {
        return str_starts_with($token, 'IG');
    }

    public static function saveSettings(InputBag $input): void
    {
        Setting::set('instagram_feed_enabled', $input->bool('instagram_feed_enabled') ? '1' : '0');
        Setting::set('instagram_feed_limit', (string) $input->int('instagram_feed_limit', 12, 1, self::MAX_POSTS));

        $username = ltrim($input->str('instagram_feed_username', '', 64), '@');
        // Имя уходит в адрес профиля: буквы, цифры, точка и подчёркивание —
        // ровно алфавит имён Instagram, всё прочее отбрасываем.
        Setting::set('instagram_feed_username', mb_substr(preg_replace('/[^A-Za-z0-9._]/', '', $username) ?? '', 0, 30));

        if ($input->bool('instagram_feed_clear_token')) {
            Setting::set('instagram_feed_token', '');
        } else {
            $token = $input->str('instagram_feed_token', '', 1000);
            if ($token !== '') {
                Setting::set('instagram_feed_token', $token);
                Setting::set('instagram_feed_token_refreshed', (string) time());
            }
        }
    }

    /**
     * Сохранённые посты для вывода. Сеть не трогает.
     *
     * @return list<array{id: string, type: string, caption: string, permalink: string, image: string, timestamp: string}>
     */
    public static function posts(int $limit): array
    {
        $raw = json_decode(Setting::get(self::POSTS_KEY, '[]'), true);
        if (!is_array($raw)) {
            return [];
        }
        $posts = [];
        foreach ($raw as $row) {
            $post = is_array($row) ? self::clean($row) : null;
            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return array_slice($posts, 0, max(1, $limit));
    }

    /**
     * Адрес профиля для ссылки «Подписаться». Имя приходит из настройки или
     * из ответа Instagram при первом проходе; из адреса поста профиль не
     * взять (instagram.com/p/…), поэтому без имени ссылки нет вовсе, а не
     * ссылка в никуда.
     */
    public static function profileUrl(): string
    {
        return self::settings()['profile_url'];
    }

    /**
     * Забирает последние посты и обновляет сохранённую копию.
     *
     * @return array{ok: bool, summary: string, error: string, added: int}
     */
    public static function sync(?int $userId = null): array
    {
        $cfg = self::settings();
        if (!self::isConfigured()) {
            return self::finish(false, 'не задан токен Instagram (или IG User ID для токена Страницы Facebook)', 0);
        }

        $token = self::refreshToken($cfg);
        $fetch = self::fetch($token, $cfg['user_id'], $cfg['limit']);
        if ($fetch['error'] !== '') {
            return self::finish(false, $fetch['error'], 0);
        }
        if ($cfg['username'] === '' && $fetch['username'] !== '') {
            Setting::set('instagram_feed_username', $fetch['username']);
        }

        $known = [];
        foreach (self::posts(self::MAX_POSTS) as $post) {
            $known[$post['id']] = $post;
        }

        $posts = [];
        $added = 0;
        foreach ($fetch['items'] as $item) {
            $image = $known[$item['id']]['image'] ?? '';
            if ($image === '') {
                $image = (string) RemoteImage::import($item['remote'], 'instagram-' . $item['id'], $userId, 'InstagramFeed');
                if ($image === '') {
                    continue;
                }
                $added++;
            }
            $posts[] = [
                'id' => $item['id'],
                'type' => $item['type'],
                'caption' => $item['caption'],
                'permalink' => $item['permalink'],
                'image' => $image,
                'timestamp' => $item['timestamp'],
            ];
        }
        if ($posts === [] && $fetch['items'] !== []) {
            return self::finish(false, 'посты получены, но ни один кадр не скачался', 0);
        }

        $kept = array_column($posts, 'image');
        Setting::set(self::POSTS_KEY, json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        foreach ($known as $old) {
            if (!in_array($old['image'], $kept, true)) {
                self::dropImage($old['image']);
            }
        }
        // Блоки страниц лежат в кэше до правки контента, а новый пост — не
        // правка: без сброса лента на главной стояла бы до следующего сохранения.
        if ($added > 0 || count($posts) !== count($known)) {
            Cache::forgetPrefix('page:');
        }

        return self::finish(true, 'постов: ' . count($posts) . ', новых: ' . $added, $added);
    }

    /**
     * Разбор ответа Graph API. Пост без кадра или со ссылкой не на Instagram
     * пропускается: показать его нечем, а адрес уходит в href.
     *
     * @return list<array{id: string, type: string, caption: string, permalink: string, remote: string, timestamp: string}>
     */
    public static function parseMedia(string $json): array
    {
        $data = json_decode($json, true);
        $rows = is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = preg_replace('/\D/', '', (string) ($row['id'] ?? '')) ?? '';
            $type = strtoupper((string) ($row['media_type'] ?? 'IMAGE'));
            // У ролика кадр — обложка (thumbnail_url), media_url ведёт на видео.
            $remote = (string) ($type === 'VIDEO' ? ($row['thumbnail_url'] ?? '') : ($row['media_url'] ?? ''));
            $permalink = (string) ($row['permalink'] ?? '');
            if ($id === '' || !str_starts_with($remote, 'https://') || !self::isInstagramLink($permalink)) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'type' => in_array($type, ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM'], true) ? $type : 'IMAGE',
                'caption' => mb_substr(trim((string) ($row['caption'] ?? '')), 0, 2200),
                'permalink' => $permalink,
                'remote' => $remote,
                'timestamp' => (string) ($row['timestamp'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return array{items: list<array{id: string, type: string, caption: string, permalink: string, remote: string, timestamp: string}>,
     *     username: string, error: string}
     */
    private static function fetch(string $token, string $userId, int $limit): array
    {
        $base = self::isInstagramLogin($token)
            ? self::INSTAGRAM_GRAPH . '/me'
            : self::FACEBOOK_GRAPH . '/' . rawurlencode($userId);

        $media = self::get($base . '/media?' . http_build_query([
            'fields' => self::FIELDS,
            'limit' => $limit,
            'access_token' => $token,
        ]));
        if ($media['error'] !== '') {
            return ['items' => [], 'username' => '', 'error' => $media['error']];
        }
        $profile = self::get($base . '?' . http_build_query(['fields' => 'username', 'access_token' => $token]));
        $profileData = json_decode($profile['body'], true);
        $username = is_array($profileData) ? (string) ($profileData['username'] ?? '') : '';

        return [
            'items' => self::parseMedia($media['body']),
            'username' => preg_replace('/[^A-Za-z0-9._]/', '', $username) ?? '',
            'error' => '',
        ];
    }

    /**
     * Ответ Graph API. Текст ошибки берётся из ответа, а не из адреса: в
     * адресе лежит токен, и в журнал он попасть не должен.
     *
     * @return array{body: string, error: string}
     */
    private static function get(string $url): array
    {
        try {
            $res = Http::getSafeRemote($url, ['Accept: application/json'], 20, 2097152);
        } catch (\Throwable $e) {
            return ['body' => '', 'error' => 'Instagram не ответил'];
        }
        $body = (string) $res['body'];
        if ((int) $res['status'] === 200) {
            return ['body' => $body, 'error' => ''];
        }
        $data = json_decode($body, true);
        $message = is_array($data) && is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';

        return ['body' => $body, 'error' => 'Instagram ответил ' . (int) $res['status'] . ($message !== '' ? ': ' . mb_substr($message, 0, 300) : '')];
    }

    /**
     * Продлевает токен Instagram Login: он живёт 60 дней, и без продления
     * лента молча остановилась бы через два месяца. Токен Страницы Facebook
     * бессрочный и в продлении не нуждается.
     *
     * @param array{own_token: bool, token: string} $cfg
     */
    private static function refreshToken(array $cfg): string
    {
        $token = $cfg['token'];
        if (!$cfg['own_token'] || !self::isInstagramLogin($token)) {
            return $token;
        }
        $last = (int) Setting::get('instagram_feed_token_refreshed', '0');
        if ($last > 0 && time() - $last < self::REFRESH_AFTER) {
            return $token;
        }
        $res = self::get(self::INSTAGRAM_GRAPH . '/refresh_access_token?' . http_build_query([
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]));
        $data = json_decode($res['body'], true);
        $fresh = is_array($data) ? trim((string) ($data['access_token'] ?? '')) : '';
        if ($res['error'] !== '' || $fresh === '') {
            // Не продлился — работаем прежним, он ещё жив; попытка повторится
            // при следующем проходе.
            Logger::warning('InstagramFeed: токен не продлён — ' . ($res['error'] !== '' ? $res['error'] : 'пустой ответ'));

            return $token;
        }
        Setting::set('instagram_feed_token', $fresh);
        Setting::set('instagram_feed_token_refreshed', (string) time());

        return $fresh;
    }

    /**
     * Кадр поста, ушедшего из ленты, удаляется из медиатеки — если его не
     * поставили куда-то ещё: иначе каждая новая публикация оставляла бы по
     * файлу навсегда.
     */
    private static function dropImage(string $url): void
    {
        try {
            if ($url === '' || MediaCleaner::isReferenced($url)) {
                return;
            }
            $file = FileEntry::findPublicByUrl($url);
            if ($file !== null) {
                FileEntry::delete((int) $file['id']);
            }
            MediaCleaner::purgeUnreferenced([$url]);
        } catch (\Throwable $e) {
            Logger::swallowed('InstagramFeed: кадр ушедшего поста не удалён', $e);
        }
    }

    /**
     * @param array<mixed> $row
     * @return array{id: string, type: string, caption: string, permalink: string, image: string, timestamp: string}|null
     */
    private static function clean(array $row): ?array
    {
        $image = (string) ($row['image'] ?? '');
        $permalink = (string) ($row['permalink'] ?? '');
        if ($image === '' || !UrlGuard::isSafeMedia($image) || !self::isInstagramLink($permalink)) {
            return null;
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'type' => (string) ($row['type'] ?? 'IMAGE'),
            'caption' => (string) ($row['caption'] ?? ''),
            'permalink' => $permalink,
            'image' => $image,
            'timestamp' => (string) ($row['timestamp'] ?? ''),
        ];
    }

    private static function isInstagramLink(string $url): bool
    {
        return preg_match('#^https://(www\.)?instagram\.com/[A-Za-z0-9._/-]+$#', $url) === 1;
    }

    /** @return array{ok: bool, summary: string, error: string, added: int} */
    private static function finish(bool $ok, string $summary, int $added): array
    {
        Setting::set('instagram_feed_last_sync', date('Y-m-d H:i:s'));
        Setting::set('instagram_feed_last_result', mb_substr(($ok ? '' : 'Ошибка: ') . $summary, 0, 500));
        IntegrationStatus::record('instagram_feed', $ok, $ok ? '' : $summary, 'лента Instagram');

        return ['ok' => $ok, 'summary' => $ok ? $summary : '', 'error' => $ok ? '' : $summary, 'added' => $added];
    }
}
