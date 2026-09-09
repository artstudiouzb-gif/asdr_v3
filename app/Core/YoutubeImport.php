<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;
use App\Models\Setting;
use App\Models\Video;

/**
 * Автозагрузка роликов с YouTube-канала в раздел «Видео».
 *
 * Забирается карточка ролика — название, описание, ссылка и кадр-превью;
 * само видео остаётся на YouTube (сайт его не хранит и не раздаёт).
 * Повторный проход узнаёт запись по id ролика и не создаёт дубль, поэтому
 * импорт можно вешать на cron и запускать руками сколько угодно раз.
 *
 * Правки редактора приоритетнее источника: название и описание уже
 * импортированной записи переписываются только при включённой настройке
 * «обновлять» — иначе ручной перевод названия затирался бы каждой ночью.
 */
final class YoutubeImport
{
    /** Ограничение на длину описания: у роликов бывают простыни со ссылками. */
    private const MAX_DESCRIPTION = 5000;

    private const THUMB_MAX_BYTES = 5242880;

    /**
     * Настройки раздела со значениями по умолчанию.
     *
     * @return array{enabled:bool, channel:string, channel_id:string, api_key:string, limit:int,
     *               status:string, featured:bool, update:bool, thumbs:string,
     *               last_sync:string, last_result:string}
     */
    public static function settings(): array
    {
        $limit = (int) Setting::get('youtube_import_limit', '15');

        return [
            'enabled' => Setting::get('youtube_import_enabled', '0') === '1',
            'channel' => trim(Setting::get('youtube_channel', '')),
            'channel_id' => trim(Setting::get('youtube_channel_id', '')),
            'api_key' => trim(Setting::get('youtube_api_key', '')),
            'limit' => max(1, min(YoutubeChannel::MAX_ITEMS, $limit > 0 ? $limit : 15)),
            'status' => Setting::get('youtube_import_status', 'draft') === 'published' ? 'published' : 'draft',
            'featured' => Setting::get('youtube_import_featured', '0') === '1',
            'update' => Setting::get('youtube_import_update', '0') === '1',
            'thumbs' => Setting::get('youtube_import_thumbs', 'local') === 'remote' ? 'remote' : 'local',
            'last_sync' => Setting::get('youtube_last_sync', ''),
            'last_result' => Setting::get('youtube_last_result', ''),
        ];
    }

    public static function isConfigured(): bool
    {
        return YoutubeChannel::parseSource(trim(Setting::get('youtube_channel', ''))) !== null;
    }

    /**
     * Сохраняет настройки из формы админки. Ключ API — секрет: пустое поле
     * означает «оставить сохранённый», очистка — отдельной галочкой.
     *
     * @param array<string, mixed> $input
     */
    public static function saveSettings(array $input): void
    {
        $channel = trim((string) ($input['youtube_channel'] ?? ''));
        $previous = trim(Setting::get('youtube_channel', ''));

        Setting::set('youtube_channel', mb_substr($channel, 0, 255));
        // Сменили канал — забываем найденный id, иначе импорт продолжит ходить
        // на прежний канал.
        if ($channel !== $previous) {
            Setting::set('youtube_channel_id', '');
        }

        Setting::set('youtube_import_enabled', !empty($input['youtube_import_enabled']) ? '1' : '0');
        Setting::set('youtube_import_featured', !empty($input['youtube_import_featured']) ? '1' : '0');
        Setting::set('youtube_import_update', !empty($input['youtube_import_update']) ? '1' : '0');
        Setting::set(
            'youtube_import_status',
            ($input['youtube_import_status'] ?? '') === 'published' ? 'published' : 'draft'
        );
        Setting::set(
            'youtube_import_thumbs',
            ($input['youtube_import_thumbs'] ?? '') === 'remote' ? 'remote' : 'local'
        );

        $limit = (int) ($input['youtube_import_limit'] ?? 15);
        Setting::set('youtube_import_limit', (string) max(1, min(YoutubeChannel::MAX_ITEMS, $limit)));

        if (!empty($input['youtube_api_key_clear'])) {
            Setting::set('youtube_api_key', '');
        } else {
            $key = trim((string) ($input['youtube_api_key'] ?? ''));
            if ($key !== '') {
                Setting::set('youtube_api_key', mb_substr($key, 0, 255));
            }
        }
    }

    /**
     * Один проход импорта.
     *
     * @return array{ok:bool, error:string, created:int, updated:int, linked:int, skipped:int, total:int, summary:string}
     */
    public static function sync(?int $userId = null): array
    {
        $cfg = self::settings();
        if ($cfg['channel'] === '') {
            return self::result(false, 'Канал не указан.');
        }

        $fetch = YoutubeChannel::fetch($cfg['channel'], $cfg['limit'], $cfg['api_key'], $cfg['channel_id']);
        if (!$fetch['ok']) {
            self::remember(false, $fetch['error']);
            return self::result(false, $fetch['error']);
        }
        if ($fetch['channel_id'] !== '' && $fetch['channel_id'] !== $cfg['channel_id']) {
            Setting::set('youtube_channel_id', $fetch['channel_id']);
        }

        $known = self::knownByYoutubeId();
        $created = $updated = $linked = $skipped = 0;

        // Старые ролики первыми: у свежих окажется больший id, и при равной
        // дате публикации порядок списка совпадёт с порядком канала.
        foreach (array_reverse($fetch['items']) as $item) {
            $youtubeId = (string) $item['youtube_id'];
            $existing = $known[$youtubeId] ?? null;

            try {
                if ($existing === null) {
                    if (self::createOne($item, $cfg, $userId)) {
                        $created++;
                    }
                    continue;
                }

                $id = (int) $existing['id'];
                $isImported = trim((string) ($existing['youtube_id'] ?? '')) !== '';

                if (!$isImported) {
                    // Ролик уже был заведён вручную: не плодим дубль, а
                    // привязываем запись к источнику, не трогая тексты.
                    Video::applyYoutube($id, self::payload($item, self::coverFor($item, $cfg, $userId, $existing)), false);
                    $linked++;
                    continue;
                }

                if (!$cfg['update']) {
                    $skipped++;
                    continue;
                }

                Video::applyYoutube($id, self::payload($item, self::coverFor($item, $cfg, $userId, $existing)), true);
                $updated++;
            } catch (\Throwable $e) {
                Logger::swallowed('YoutubeImport: ролик ' . $youtubeId . ' не импортирован', $e);
                $skipped++;
            }
        }

        $result = self::result(true, '', $created, $updated, $linked, $skipped, count($fetch['items']));
        self::remember(true, $result['summary']);

        return $result;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $cfg */
    private static function createOne(array $item, array $cfg, ?int $userId): bool
    {
        $title = trim((string) $item['title']);
        $id = Video::create($title !== '' ? $title : 'Видео ' . (string) $item['youtube_id']);
        if ($id === null) {
            return false;
        }

        Video::applyYoutube($id, self::payload($item, self::coverFor($item, $cfg, $userId, null)), true);
        Video::setFlags($id, $cfg['status'] === 'published', $cfg['featured']);

        return true;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function payload(array $item, string $cover): array
    {
        return [
            'title' => (string) $item['title'],
            'description' => mb_substr(trim((string) $item['description']), 0, self::MAX_DESCRIPTION),
            'cover_url' => $cover,
            'video_url' => (string) $item['url'],
            'duration' => (string) ($item['duration'] ?? ''),
            'youtube_id' => (string) $item['youtube_id'],
            'published_at' => $item['published_at'] ?? null,
        ];
    }

    /**
     * Адрес кадра-превью для записи. Существующей записи с обложкой ничего не
     * меняем: заново скачанный кадр породил бы копию файла при каждом проходе.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $cfg
     * @param array<string, mixed>|null $existing
     */
    private static function coverFor(array $item, array $cfg, ?int $userId, ?array $existing): string
    {
        if ($existing !== null && trim((string) ($existing['cover_url'] ?? '')) !== '') {
            return (string) $existing['cover_url'];
        }

        $remote = (string) $item['thumbnail'];
        if ($cfg['thumbs'] === 'remote') {
            return $remote;
        }

        $local = self::storeThumbnail((string) $item['youtube_id'], $remote, $userId);

        // Не скачалось — оставляем адрес на i.ytimg.com: карточка без обложки
        // выглядит сломанной, а публичная CSP внешние картинки допускает.
        return $local ?? $remote;
    }

    /**
     * Скачивает кадр-превью в медиатеку. Сначала пробуем maxresdefault
     * (1280×720): в RSS канал отдаёт только 480×360, и на широкой карточке
     * такой кадр заметно мылит.
     */
    private static function storeThumbnail(string $youtubeId, string $feedUrl, ?int $userId): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            'https://i.ytimg.com/vi/' . $youtubeId . '/maxresdefault.jpg',
            $feedUrl,
            \App\Core\Video::youtubeThumbnail($youtubeId),
        ])));

        foreach ($candidates as $url) {
            $tmp = null;
            try {
                $res = Http::getSafeRemote($url, ['Accept: image/*'], 20, self::THUMB_MAX_BYTES);
                if ((int) $res['status'] !== 200 || strlen($res['body']) < 1024) {
                    continue;
                }
                $temp = tempnam(sys_get_temp_dir(), 'ytimg_');
                if ($temp === false) {
                    return null;
                }
                $tmp = $temp;
                if (file_put_contents($tmp, $res['body']) === false) {
                    @unlink($tmp);
                    continue;
                }
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => null,
                };
                if ($ext === null) {
                    @unlink($tmp);
                    continue;
                }
                $named = $tmp . '.' . $ext;
                @rename($tmp, $named);
                $file = Uploader::storeFromPath(
                    $named,
                    'youtube-' . $youtubeId . '.' . $ext,
                    (int) filesize($named),
                    'public',
                    $userId,
                    false,
                    self::THUMB_MAX_BYTES,
                    ['jpg', 'jpeg', 'png', 'webp']
                );
                @unlink($named);

                return FileEntry::publicUrl($file);
            } catch (\Throwable $e) {
                if ($tmp !== null) {
                    @unlink($tmp);
                }
                Logger::swallowed('YoutubeImport: кадр-превью не сохранён (' . $url . ')', $e);
            }
        }

        return null;
    }

    /**
     * Уже известные ролики: и импортированные (youtube_id), и заведённые
     * вручную — их узнаём по ссылке, чтобы импорт не создал дубль.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function knownByYoutubeId(): array
    {
        $map = [];
        foreach (Video::all() as $row) {
            $id = trim((string) ($row['youtube_id'] ?? ''));
            if ($id === '') {
                $id = (string) (\App\Core\Video::youtubeId((string) ($row['video_url'] ?? '')) ?? '');
            }
            if ($id !== '' && !isset($map[$id])) {
                $map[$id] = $row;
            }
        }

        return $map;
    }

    private static function remember(bool $ok, string $summary): void
    {
        Setting::set('youtube_last_sync', date('Y-m-d H:i:s'));
        Setting::set('youtube_last_result', mb_substr(($ok ? '' : 'Ошибка: ') . $summary, 0, 500));
    }

    /** @return array{ok:bool, error:string, created:int, updated:int, linked:int, skipped:int, total:int, summary:string} */
    private static function result(
        bool $ok,
        string $error = '',
        int $created = 0,
        int $updated = 0,
        int $linked = 0,
        int $skipped = 0,
        int $total = 0
    ): array {
        $summary = $ok
            ? sprintf(
                'просмотрено %d, добавлено %d, обновлено %d, привязано %d, без изменений %d',
                $total,
                $created,
                $updated,
                $linked,
                $skipped
            )
            : $error;

        return [
            'ok' => $ok,
            'error' => $error,
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'skipped' => $skipped,
            'total' => $total,
            'summary' => $summary,
        ];
    }
}
