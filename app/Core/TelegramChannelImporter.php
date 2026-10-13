<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;
use App\Models\Language;
use App\Models\News;
use App\Models\NewsImage;

/**
 * Импорт новостей из выгрузки Telegram-канала («Экспорт истории чата» в
 * Telegram Desktop, формат JSON, с фотографиями).
 *
 * Канал не читается по сети намеренно. Открытая витрина `t.me/s/<канал>`
 * отдаёт двадцать постов за запрос, сжатые кадры и подписанные ссылки на
 * них, которые живут несколько дней, — архив за годы через неё собирается
 * часами и с потерями. Выгрузка приложения даёт всё сразу: полный текст с
 * разметкой, оригиналы фото и точные даты. Владелец кладёт папку выгрузки в
 * закрытый `storage/imports/telegram/` файловым менеджером хостинга (сотни
 * мегабайт фото форма браузера не пропустит) — тот же путь, что у импорта
 * документов.
 *
 * Новости приходят черновиками: пост канала — не готовая новость (заголовка у
 * него нет, первая строка бывает эмодзи и «Diqqat!»), и публиковать сотни
 * записей, не взглянув на них, незачем. Массовая публикация в списке новостей
 * есть. Пост, который уже есть на сайте под тем же заголовком (сайт сам
 * публикует новости в канал), пропускается — иначе импорт удвоил бы ленту.
 *
 * @phpstan-type Run array{text:string,type:string,href:string}
 * @phpstan-type Post array{id:int,ids:list<int>,date:string,runs:list<Run>,photos:list<string>,videos:int,missing:int}
 * @phpstan-type Mapped array{title:string,excerpt:string,content:string,hashtags:string,lang:string}
 * @phpstan-type State array{posts:array<array-key,int>}
 */
final class TelegramChannelImporter
{
    public const INBOX = 'storage/imports/telegram';

    private const STATE_FILE = '.import-state.json';

    // Выбор «только эти посты» лежит файлом рядом с выгрузкой, а не в сессии:
    // перенос идёт пакетами, и каждый пакет обязан видеть тот же выбор, что
    // показала сводка, — даже если перенос продолжают на следующий день.
    private const SELECTION_FILE = '.import-only.json';

    private const EXPORT_FILE = 'result.json';

    // Фото альбома Telegram выгружает отдельными сообщениями без общего
    // признака группы; отличает их одна и та же секунда отправки. Две секунды
    // — запас на альбом, ушедший на границе секунды.
    private const ALBUM_GAP_SECONDS = 2;

    private const TITLE_MAX = 150;

    private const EXCERPT_MAX = 300;

    private const NOT_EXPORTED = '(File not included';

    /**
     * Сообщения выгрузки → посты. Служебные сообщения и посты без текста
     * (одиночное фото, опрос) новостью не становятся и перечисляются в
     * `skipped` с причиной.
     *
     * @param array<string, mixed> $export
     * @return array{channel:string,posts:list<Post>,skipped:list<array{id:int,reason:string}>}
     */
    public static function parse(array $export): array
    {
        $posts = [];
        $skipped = [];
        $current = null;
        $lastTime = 0;

        foreach ((array) ($export['messages'] ?? []) as $message) {
            if (!is_array($message) || ($message['type'] ?? '') !== 'message') {
                continue;
            }
            $id = (int) ($message['id'] ?? 0);
            $time = (int) ($message['date_unixtime'] ?? 0) ?: (int) strtotime((string) ($message['date'] ?? ''));
            $runs = self::runs($message);
            $hasText = trim(implode('', array_column($runs, 'text'))) !== '';
            [$photo, $missing] = self::photoOf($message);
            $isVideo = in_array((string) ($message['media_type'] ?? ''), ['video_file', 'animation', 'video_message'], true);
            $hasMedia = $photo !== null || $missing > 0 || $isVideo;

            $joins = $current !== null && $hasMedia && abs($time - $lastTime) <= self::ALBUM_GAP_SECONDS
                && (!$hasText || $current['runs'] === []);
            if ($joins) {
                $current['ids'][] = $id;
                if ($hasText) {
                    $current['runs'] = $runs;
                }
            } else {
                if ($current !== null) {
                    self::finish($current, $posts, $skipped);
                }
                if (isset($message['poll'])) {
                    $skipped[] = ['id' => $id, 'reason' => 'опрос'];
                    $current = null;
                    continue;
                }
                // Репост — чужой материал: у него свой автор и свой канал, и
                // новостью ведомства на сайте он становиться не должен.
                // Выгрузка помечает его полем `forwarded_from` (имя источника).
                $source = self::forwardedFrom($message);
                if ($source !== null) {
                    $skipped[] = ['id' => $id, 'reason' => 'репост из «' . $source . '»'];
                    $current = null;
                    $lastTime = $time;
                    continue;
                }
                $current = [
                    'id' => $id,
                    'ids' => [$id],
                    'date' => self::dateOf($message, $time),
                    'runs' => $runs,
                    'photos' => [],
                    'videos' => 0,
                    'missing' => 0,
                ];
            }
            if ($photo !== null) {
                $current['photos'][] = $photo;
            }
            $current['missing'] += $missing;
            $current['videos'] += $isVideo ? 1 : 0;
            $lastTime = $time;
        }
        if ($current !== null) {
            self::finish($current, $posts, $skipped);
        }

        return ['channel' => (string) ($export['name'] ?? ''), 'posts' => $posts, 'skipped' => $skipped];
    }

    /**
     * Пост → поля новости. Заголовок — первая строка без ведущих значков;
     * длинная первая строка режется по концу предложения, и тогда абзац
     * целиком остаётся в тексте. Строки из одних хештегов уходят в поле
     * хештегов, а не в текст.
     *
     * @param Post $post
     * @return Mapped
     */
    public static function map(array $post): array
    {
        $lines = self::lines($post['runs']);
        $hashtags = [];
        foreach ($post['runs'] as $run) {
            if ($run['type'] === 'hashtag') {
                $hashtags[] = $run['text'];
            }
        }
        while ($lines !== [] && self::isTagLine($lines[count($lines) - 1]['plain'])) {
            array_pop($lines);
        }
        while ($lines !== [] && trim($lines[0]['plain']) === '') {
            array_shift($lines);
        }

        $first = $lines === [] ? '' : self::cleanTitle($lines[0]['plain']);
        $title = self::shorten($first, self::TITLE_MAX, true);
        $body = $title === $first ? array_slice($lines, 1) : $lines;

        $paragraphs = [];
        $plain = [];
        $html = [];
        foreach ($body as $line) {
            if (trim($line['plain']) === '') {
                if ($html !== []) {
                    $paragraphs[] = ['html' => implode('<br>', $html), 'plain' => implode(' ', $plain)];
                }
                $html = $plain = [];
                continue;
            }
            $html[] = trim($line['html']);
            $plain[] = trim($line['plain']);
        }
        if ($html !== []) {
            $paragraphs[] = ['html' => implode('<br>', $html), 'plain' => implode(' ', $plain)];
        }

        $content = implode("\n", array_map(static fn (array $p): string => '<p>' . $p['html'] . '</p>', $paragraphs));
        $lead = $paragraphs === [] ? $title : $paragraphs[0]['plain'];
        $all = implode(' ', array_column($post['runs'], 'text'));

        return [
            'title' => $title,
            'excerpt' => self::shorten($lead, self::EXCERPT_MAX, false),
            'content' => $content,
            'hashtags' => implode(' ', array_unique($hashtags)),
            'lang' => self::detectLang($all),
        ];
    }

    /**
     * Язык поста по тексту: в выгрузке его нет. Кириллица — русский, если в
     * ней нет узбекских букв (ў қ ғ ҳ); латиница — узбекский, если только
     * английские служебные слова не встречаются заметно чаще узбекских.
     */
    public static function detectLang(string $text): string
    {
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text);
        $lat = preg_match_all('/[A-Za-z]/', $text);
        if ($cyr > $lat) {
            return preg_match('/[ўқғҳЎҚҒҲ]/u', $text) === 1 ? 'uz' : 'ru';
        }
        $en = preg_match_all('/\b(the|and|of|to|in|for|with|is|on)\b/i', $text);
        $uz = preg_match_all('/\b(va|bilan|uchun|bo[ʻ\'‘`]yicha|yil|ham|haqida|qilish)\b/iu', $text);

        return $en > $uz * 2 && $en >= 3 ? 'en' : 'uz';
    }

    /**
     * Перенос пакетами, как у импорта документов: сколько успевает за
     * `$budgetSeconds`, курсор возвращается вызывающему. Перенесённое
     * записывается в файл состояния после каждого поста — повтор продолжает,
     * а не дублирует.
     *
     * @return array{
     *   cursor:int,total:int,done:bool,dry:bool,planned:int,imported:int,skipped:int,
     *   exists:int,failed:int,missing:int,errors:list<string>,notes:list<string>
     * }
     */
    public static function run(string $inbox, bool $dryRun, int $offset, float $budgetSeconds, ?int $userId): array
    {
        $started = microtime(true);
        $exportPath = self::findExport($inbox);
        $data = self::parse(self::readExport($exportPath));
        $posts = self::select($data['posts'], self::loadSelection($inbox));
        if ($posts === []) {
            throw new \RuntimeException('В выгрузке нет постов с текстом' . (self::loadSelection($inbox) !== [] ? ' среди выбранных ссылками' : '') . '.');
        }
        $base = dirname($exportPath);
        $state = self::loadState($inbox);
        $total = count($posts);
        $result = [
            'cursor' => $offset, 'total' => $total, 'done' => false, 'dry' => $dryRun,
            'planned' => 0, 'imported' => 0, 'skipped' => 0, 'exists' => 0, 'failed' => 0, 'missing' => 0,
            'errors' => [], 'notes' => [],
        ];

        for ($i = $offset; $i < $total; $i++) {
            if ($i > $offset && microtime(true) - $started >= $budgetSeconds) {
                break;
            }
            $post = $posts[$i];
            $result['cursor'] = $i + 1;
            if (isset($state['posts'][(string) $post['id']])) {
                $result['skipped']++;
                continue;
            }
            $mapped = self::map($post);
            $lang = self::targetLang($mapped['lang']);
            if (self::existsOnSite($mapped['title'], $lang)) {
                $result['exists']++;
                continue;
            }
            $present = array_values(array_filter($post['photos'], static fn (string $p): bool => is_file($base . '/' . $p)));
            $lost = $post['missing'] + count($post['photos']) - count($present);
            $result['missing'] += $lost;
            if ($dryRun) {
                $result['planned']++;
                continue;
            }

            try {
                $newsId = self::create($post, $mapped, $lang, $base, $present, $userId);
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = 'Пост #' . $post['id'] . ' «' . $mapped['title'] . '»: ' . $e->getMessage();
                continue;
            }
            $state['posts'][(string) $post['id']] = $newsId;
            self::saveState($inbox, $state);
            $result['imported']++;
        }

        $result['done'] = $result['cursor'] >= $total;
        if ($result['done']) {
            if ($result['missing'] > 0) {
                $result['notes'][] = 'Фотографий нет в выгрузке: ' . $result['missing']
                    . '. В Telegram Desktop при экспорте должна стоять галочка «Фотографии».';
            }
            if (!$dryRun) {
                Cache::forgetPrefix('page:');
            }
        }

        return $result;
    }

    /**
     * Сводка до запуска: что нашлось в выгрузке. Разбор JSON без записи.
     *
     * `selected` — номера из ссылок, `not_found` — те из них, что новостью не
     * станут, с причиной: репост, пост без текста или номера нет в выгрузке
     * (выгружен не тот период).
     *
     * @return array{file:string,channel:string,posts:int,already:int,photos:int,videos:int,skipped:int,from:string,to:string,langs:array<string,int>,selected:list<int>,not_found:list<string>}
     */
    public static function summary(string $inbox): array
    {
        $path = self::findExport($inbox);
        $data = self::parse(self::readExport($path));
        $selected = self::loadSelection($inbox);
        $notFound = [];
        if ($selected !== []) {
            $posts = self::select($data['posts'], $selected);
            $reasons = array_column($data['skipped'], 'reason', 'id');
            $covered = array_merge([], ...array_column($posts, 'ids'));
            foreach ($selected as $id) {
                if (!in_array($id, $covered, true)) {
                    $notFound[] = '#' . $id . ' — ' . ($reasons[$id] ?? 'нет в выгрузке');
                }
            }
            $data['posts'] = $posts;
            $data['skipped'] = [];
        }
        $state = self::loadState($inbox);
        $langs = [];
        $photos = $videos = 0;
        foreach ($data['posts'] as $post) {
            $lang = self::targetLang(self::map($post)['lang']);
            $langs[$lang] = ($langs[$lang] ?? 0) + 1;
            $photos += count($post['photos']);
            $videos += $post['videos'];
        }
        $dates = array_filter(array_column($data['posts'], 'date'));

        return [
            'file' => substr($path, strlen(rtrim($inbox, '/')) + 1),
            'channel' => $data['channel'],
            'posts' => count($data['posts']),
            'already' => count($state['posts']),
            'photos' => $photos,
            'videos' => $videos,
            'skipped' => count($data['skipped']),
            'from' => $dates === [] ? '' : substr((string) min($dates), 0, 10),
            'to' => $dates === [] ? '' : substr((string) max($dates), 0, 10),
            'langs' => $langs,
            'selected' => $selected,
            'not_found' => $notFound,
        ];
    }

    /**
     * Номера постов из ссылок вида `https://t.me/<канал>/2933` (а также
     * `t.me/s/<канал>/…`, `t.me/c/<id>/…` и `?single`). Голое число тоже
     * принимается. Номер в ссылке — это `id` сообщения в выгрузке.
     *
     * @return list<int>
     */
    public static function parseLinks(string $text): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('#^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/(?:s/|c/)?[\w-]+/(\d+)(?:[/?\#].*)?$#i', $token, $m) === 1
                || preg_match('/^#?(\d+)$/', $token, $m) === 1) {
                $ids[(int) $m[1]] = true;
            }
        }
        $ids = array_keys(array_filter($ids, static fn (bool $v, int $id): bool => $id > 0, ARRAY_FILTER_USE_BOTH));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids пустой список снимает выбор */
    public static function saveSelection(string $inbox, array $ids): void
    {
        $path = rtrim($inbox, '/') . '/' . self::SELECTION_FILE;
        if ($ids === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        if (!is_dir($inbox) || file_put_contents($path, json_encode(array_values($ids), JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Не удалось сохранить выбор постов в ' . self::INBOX . '.');
        }
    }

    /** @return list<int> */
    public static function loadSelection(string $inbox): array
    {
        $path = rtrim($inbox, '/') . '/' . self::SELECTION_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? array_values(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0)) : [];
    }

    /**
     * Ссылка на альбом указывает на одно из его фото, не обязательно на
     * первое, поэтому пост выбирается по любому своему сообщению.
     *
     * @param list<Post> $posts
     * @param list<int> $selected
     * @return list<Post>
     */
    private static function select(array $posts, array $selected): array
    {
        if ($selected === []) {
            return $posts;
        }

        return array_values(array_filter($posts, static fn (array $post): bool => array_intersect($post['ids'], $selected) !== []));
    }

    public static function inbox(): string
    {
        return rtrim((string) APP_ROOT, '/') . '/' . self::INBOX;
    }

    /**
     * `result.json` в самой папке или на уровень глубже: Telegram Desktop
     * кладёт выгрузку в папку `ChatExport_<дата>`, и её загружают целиком.
     */
    public static function findExport(string $inbox): string
    {
        $inbox = rtrim($inbox, '/');
        $found = array_merge(glob($inbox . '/' . self::EXPORT_FILE) ?: [], glob($inbox . '/*/' . self::EXPORT_FILE) ?: []);
        if ($found === []) {
            throw new \RuntimeException('В каталоге ' . self::INBOX . ' нет файла ' . self::EXPORT_FILE . ' — выгрузки в формате JSON.');
        }
        if (count($found) > 1) {
            throw new \RuntimeException('В каталоге ' . self::INBOX . ' несколько выгрузок — оставьте одну.');
        }

        return $found[0];
    }

    /** @return array<string, mixed> */
    private static function readExport(string $path): array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['messages'])) {
            throw new \RuntimeException('Файл ' . self::EXPORT_FILE . ' не похож на выгрузку Telegram: нет списка сообщений. Экспорт нужен в формате JSON.');
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $current
     * @param list<Post> $posts
     * @param list<array{id:int,reason:string}> $skipped
     */
    private static function finish(array $current, array &$posts, array &$skipped): void
    {
        if (trim(implode('', array_column($current['runs'], 'text'))) === '') {
            $skipped[] = ['id' => (int) $current['id'], 'reason' => 'нет текста'];

            return;
        }
        /** @var Post $current */
        $posts[] = $current;
    }

    /**
     * Текст сообщения кусками с типом разметки. `text_entities` есть у
     * выгрузок последних лет; у старых текст лежит в `text` — строкой или
     * списком строк и кусков.
     *
     * @param array<string, mixed> $message
     * @return list<Run>
     */
    private static function runs(array $message): array
    {
        $source = $message['text_entities'] ?? $message['text'] ?? '';
        if (is_string($source)) {
            $source = [$source];
        }
        $runs = [];
        foreach ((array) $source as $part) {
            if (is_string($part)) {
                $runs[] = ['text' => $part, 'type' => 'plain', 'href' => ''];
            } elseif (is_array($part)) {
                $runs[] = [
                    'text' => (string) ($part['text'] ?? ''),
                    'type' => (string) ($part['type'] ?? 'plain'),
                    'href' => (string) ($part['href'] ?? ''),
                ];
            }
        }

        return array_values(array_filter($runs, static fn (array $r): bool => $r['text'] !== ''));
    }

    /**
     * Дата поста — местное время из поля `date`, как его видел автор канала:
     * отметка `date_unixtime`, переведённая поясом сервера, сдвинула бы новости
     * на часы, если пояс хостинга не ташкентский.
     *
     * @param array<string, mixed> $message
     */
    private static function dateOf(array $message, int $time): string
    {
        $local = (string) ($message['date'] ?? '');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $local, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $time > 0 ? date('Y-m-d H:i:s', $time) : '';
    }

    /**
     * Источник репоста или null. Пустое имя бывает у переслано из скрытого
     * аккаунта — это всё равно репост.
     *
     * @param array<string, mixed> $message
     */
    private static function forwardedFrom(array $message): ?string
    {
        if (!array_key_exists('forwarded_from', $message) && !isset($message['forwarded_from_id'])) {
            return null;
        }
        $name = trim((string) ($message['forwarded_from'] ?? ''));

        return $name !== '' ? $name : 'скрытый источник';
    }

    /**
     * @param array<string, mixed> $message
     * @return array{0:?string,1:int} путь фото относительно выгрузки и число не выгруженных
     */
    private static function photoOf(array $message): array
    {
        $photo = (string) ($message['photo'] ?? '');
        if ($photo === '' && ($message['mime_type'] ?? '') !== '' && str_starts_with((string) $message['mime_type'], 'image/')) {
            $photo = (string) ($message['file'] ?? '');
        }
        if ($photo === '') {
            return [null, 0];
        }
        if (str_starts_with($photo, self::NOT_EXPORTED) || str_contains($photo, '..')) {
            return [null, 1];
        }

        return [$photo, 0];
    }

    /**
     * Куски → строки HTML. Кусок с переводом строки внутри разрезается, и
     * каждая часть получает свою пару тегов — иначе тег открылся бы в одном
     * абзаце, а закрылся в другом.
     *
     * @param list<Run> $runs
     * @return list<array{html:string,plain:string}>
     */
    private static function lines(array $runs): array
    {
        $lines = [['html' => '', 'plain' => '']];
        foreach ($runs as $run) {
            foreach (explode("\n", $run['text']) as $n => $piece) {
                if ($n > 0) {
                    $lines[] = ['html' => '', 'plain' => ''];
                }
                if ($piece === '') {
                    continue;
                }
                $last = count($lines) - 1;
                $lines[$last]['html'] .= self::fragment($run, $piece);
                $lines[$last]['plain'] .= $piece;
            }
        }

        return $lines;
    }

    /** @param Run $run */
    private static function fragment(array $run, string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = match ($run['type']) {
            'text_link' => $run['href'],
            'link' => preg_match('#^https?://#i', $text) === 1 ? $text : 'https://' . $text,
            'mention' => 'https://t.me/' . ltrim($text, '@'),
            'email' => 'mailto:' . $text,
            'phone' => 'tel:' . preg_replace('/[^\d+]/', '', $text),
            default => '',
        };
        if ($href !== '') {
            return UrlGuard::isSafeLink($href)
                ? '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $safe . '</a>'
                : $safe;
        }

        return match ($run['type']) {
            'bold' => '<strong>' . $safe . '</strong>',
            'italic' => '<em>' . $safe . '</em>',
            'underline' => '<u>' . $safe . '</u>',
            'strikethrough' => '<s>' . $safe . '</s>',
            'code' => '<code>' . $safe . '</code>',
            default => $safe,
        };
    }

    private static function isTagLine(string $line): bool
    {
        $line = trim($line);

        return $line !== '' && preg_match('/^(#[\p{L}\p{N}_ʻ\'‘’]+[\s,.]*)+$/u', $line) === 1;
    }

    // Ведущие значки («📌», «⚡️», «❗️») и хвостовые эмодзи — оформление
    // поста, а не часть заголовка: в ленте, RSS и выдаче они читаются мусором.
    private static function cleanTitle(string $line): string
    {
        $line = (string) preg_replace('/^[^\p{L}\p{N}«"„(]+/u', '', trim($line));
        $line = (string) preg_replace('/[\s\p{So}\p{Sk}\x{FE0F}\x{200D}\x{20E3}]+$/u', '', $line);

        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }

    private static function shorten(string $text, int $max, bool $sentence): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        if ($sentence && preg_match('/^(.{40,}?[.!?])\s/u', $cut, $m) === 1) {
            return $m[1];
        }
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space !== false && $space > $max / 2 ? $space : $max), ' ,;:—-') . '…';
    }

    private static function targetLang(string $lang): string
    {
        try {
            return Language::isActive($lang) ? $lang : Language::defaultCode();
        } catch (\Throwable) {
            return $lang;
        }
    }

    private static function existsOnSite(string $title, string $lang): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM news WHERE lang = :lang AND deleted_at IS NULL AND LOWER(TRIM(title)) = LOWER(:title) LIMIT 1');
        $stmt->execute([':lang' => $lang, ':title' => $title]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param Post $post
     * @param Mapped $mapped
     * @param list<string> $photos
     */
    private static function create(array $post, array $mapped, string $lang, string $base, array $photos, ?int $userId): int
    {
        $urls = [];
        foreach ($photos as $photo) {
            $path = $base . '/' . $photo;
            $name = 'telegram-' . $post['id'] . '-' . (count($urls) + 1) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $urls[] = FileEntry::publicUrl(LegacyDocumentImporter::store($path, $name, $userId));
        }
        $slug = Slug::unique(
            $mapped['title'] !== '' ? $mapped['title'] : 'telegram-' . $post['id'],
            static fn (string $s): bool => News::slugExists($s, null, $lang)
        );

        $newsId = News::create([
            'title' => $mapped['title'],
            'slug' => $slug,
            'excerpt' => $mapped['excerpt'],
            'content' => $mapped['content'],
            'image' => $urls[0] ?? null,
            'hashtags' => $mapped['hashtags'],
            'status' => 'draft',
            'published_at' => $post['date'] !== '' ? $post['date'] : null,
            'author_id' => $userId,
            'lang' => $lang,
        ]);
        // Одно фото — это обложка; галерея из того же кадра повторила бы его
        // под заголовком второй раз.
        if (count($urls) > 1) {
            foreach ($urls as $i => $url) {
                NewsImage::create($newsId, $url, null, $i);
            }
        }

        return $newsId;
    }

    /** @return State */
    private static function loadState(string $inbox): array
    {
        $path = rtrim($inbox, '/') . '/' . self::STATE_FILE;
        if (!is_file($path)) {
            return ['posts' => []];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('Файл состояния импорта повреждён: ' . self::INBOX . '/' . self::STATE_FILE);
        }

        return ['posts' => array_map('intval', (array) ($raw['posts'] ?? []))];
    }

    /** @param State $state */
    private static function saveState(string $inbox, array $state): void
    {
        $path = rtrim($inbox, '/') . '/' . self::STATE_FILE;
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.tmp', $json) === false || !rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Не удалось записать состояние импорта в ' . self::INBOX . '.');
        }
    }
}
