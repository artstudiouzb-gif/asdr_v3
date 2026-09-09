<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\News;
use App\Models\SocialPost;

/**
 * Настройки авто-публикации в соцсети и построение полезной нагрузки поста
 * из новости. Токены хранятся в таблице settings в зашифрованном виде
 * (доступ к управлению — только у супер-админа).
 */
final class SocialSettings
{
    /** Обязательные поля конфигурации по сетям. */
    private const REQUIRED = [
        'telegram' => ['token', 'chat_id'],
        'facebook' => ['token', 'page_id'],
        'linkedin' => ['token', 'author'],
        'instagram' => ['token', 'user_id'],
    ];

    /**
     * Ключи настроек по сетям (кроме флага *_enabled). signature — подпись
     * под постом; в Telegram допускает HTML-разметку (<b>, <a href>), в
     * остальных сетях API принимает только обычный текст (голые URL там
     * становятся кликабельными сами; в Instagram ссылки не кликабельны).
     */
    public const FIELDS = [
        'telegram' => ['token', 'chat_id', 'signature', 'format', 'silent', 'second_lang', 'buttons'],
        'facebook' => ['token', 'page_id', 'signature'],
        'linkedin' => ['token', 'author', 'signature'],
        'instagram' => ['token', 'user_id', 'signature'],
    ];

    /** Поля-подписи выводятся в админке многострочным полем. */
    public const TEXTAREA_FIELDS = ['signature'];

    public static function isEnabled(string $network): bool
    {
        return \App\Models\Setting::get('social_' . $network . '_enabled', '0') === '1';
    }

    /** @return array<string,string> */
    public static function configFor(string $network): array
    {
        $cfg = [];
        foreach (self::FIELDS[$network] ?? [] as $field) {
            $cfg[$field] = \App\Models\Setting::get('social_' . $network . '_' . $field, '');
        }

        // Один бот на всё: если отдельный токен для публикаций не задан, берём
        // основной токен бота из раздела «Telegram». Раньше два независимых
        // поля расходились, и публикация падала с «Not Found» при рабочем входе.
        if ($network === 'telegram' && trim((string) ($cfg['token'] ?? '')) === '') {
            $cfg['token'] = trim((string) \App\Models\Setting::get('telegram_bot_token', ''));
        }

        return $cfg;
    }

    /**
     * Нормализация одного поля конфигурации. null означает ошибку формата,
     * пустая строка допустима, пока сеть выключена.
     */
    public static function normalizeConfigField(string $network, string $field, string $value): ?string
    {
        if (!in_array($field, self::FIELDS[$network] ?? [], true)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if ($field === 'token') {
            return strlen($value) <= 10000 && preg_match('/[\x00-\x1F\x7F]/', $value) === 0
                ? $value
                : null;
        }
        if ($field === 'signature') {
            return mb_strlen($value) <= 2000
                && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 0
                    ? $value
                    : null;
        }
        if ($field === 'chat_id') {
            return self::normalizeTelegramChatId($value);
        }
        if ($field === 'format') {
            // auto — пробуем расширенный формат и откатываемся на обычный.
            return in_array($value, ['auto', 'rich', 'classic'], true) ? $value : 'auto';
        }
        if ($field === 'silent') {
            return $value === '1' ? '1' : '0';
        }
        if ($field === 'second_lang') {
            // Пустое значение — «подряд»: второй язык виден сразу, пост можно
            // дорабатывать руками, не разворачивая спойлер.
            return $value === 'details' ? 'details' : 'inline';
        }
        if ($field === 'buttons') {
            // По умолчанию кнопок нет: ссылка на каждую версию уже стоит в
            // тексте своего блока, а при двух языках кнопок становится две.
            // К альбому Telegram кнопку и вовсе не принимает, поэтому в
            // обычном формате они появлялись через раз.
            return $value === '1' ? '1' : '0';
        }
        if (in_array($field, ['page_id', 'user_id'], true)) {
            return $value === '' || preg_match('/^\d{3,30}$/', $value) === 1 ? $value : null;
        }
        // Ветка и запасной вариант ниже сохранены намеренно, хотя анализатор
        // видит, что сейчас все поля из FIELDS разобраны выше и сюда доходит
        // только 'author'. Убрать проверку — и новое поле получило бы разбор
        // ссылки LinkedIn; убрать запасной вариант — и оно не получило бы
        // вообще ничего. Оба нужны, чтобы добавленное поле попадало под
        // осторожное ограничение длины, а не под чужое правило.
        // @phpstan-ignore identical.alwaysTrue
        if ($field === 'author') {
            return $value === ''
                || preg_match('/^urn:li:(?:organization:\d{1,30}|person:[A-Za-z0-9_-]{3,100})$/', $value) === 1
                    ? $value
                    : null;
        }

        // @phpstan-ignore deadCode.unreachable
        return mb_strlen($value) <= 500 ? $value : null;
    }

    /**
     * @param array<string,string> $cfg
     * @return list<string>
     */
    public static function configurationErrors(string $network, array $cfg, bool $requireFields = true): array
    {
        $errors = [];
        foreach (self::FIELDS[$network] ?? [] as $field) {
            $value = (string) ($cfg[$field] ?? '');
            if (self::normalizeConfigField($network, $field, $value) === null) {
                $errors[] = match ($field) {
                    'token' => 'Access Token слишком длинный или содержит управляющие символы.',
                    'page_id' => 'Facebook Page ID должен состоять только из цифр.',
                    'user_id' => 'Instagram User ID должен состоять только из цифр.',
                    'author' => 'LinkedIn Author URN должен иметь вид urn:li:organization:123 или urn:li:person:ID.',
                    'signature' => 'Подпись должна быть не длиннее 2000 символов.',
                    default => 'Поле ' . $field . ' заполнено неверно.',
                };
            }
        }
        if ($network === 'telegram') {
            $signatureError = self::telegramSignatureError((string) ($cfg['signature'] ?? ''));
            if ($signatureError !== null) {
                $errors[] = $signatureError;
            }
        }
        if ($requireFields) {
            foreach (self::REQUIRED[$network] ?? [] as $field) {
                if (trim((string) ($cfg[$field] ?? '')) === '') {
                    $errors[] = 'Не заполнено обязательное поле ' . $field . '.';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** Сеть включена и все обязательные поля заполнены. */
    public static function isReady(string $network): bool
    {
        if (!self::isEnabled($network)) {
            return false;
        }
        $cfg = self::configFor($network);

        return self::configurationErrors($network, $cfg, true) === [];
    }

    /**
     * @username публичного канала или числовой id приватного канала -100….
     * Пустая строка означает, что канал ещё не настроен; null — ошибка формата.
     */
    public static function normalizeTelegramChatId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^@[A-Za-z0-9_]{5,32}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^-100\d{5,17}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    /**
     * Проверяет подпись канала до обращения к Bot API.
     *
     * @return string|null null — подпись допустима, иначе текст ошибки
     */
    public static function telegramSignatureError(string $signature): ?string
    {
        $visible = html_entity_decode(strip_tags($signature), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($signature) > 2000 || mb_strlen($visible) > 500) {
            return 'Подпись слишком длинная: максимум 500 видимых символов.';
        }

        preg_match_all('/<[^>]*>/u', $signature, $matches);
        $remainder = str_replace($matches[0], '', $signature);
        if (str_contains($remainder, '<') || str_contains($remainder, '>')) {
            return 'В подписи найден незавершённый HTML-тег.';
        }

        $stack = [];
        foreach ($matches[0] as $tag) {
            if (preg_match('/^<\\/(b|i|code|blockquote|tg-spoiler)>$/i', $tag, $m) === 1) {
                $name = strtolower($m[1]);
                if (array_pop($stack) !== $name) {
                    return 'HTML-теги в подписи закрыты в неверном порядке.';
                }
                continue;
            }
            if (preg_match('/^<(b|i|code|tg-spoiler)>$/i', $tag, $m) === 1) {
                $stack[] = strtolower($m[1]);
                continue;
            }
            if (preg_match('/^<blockquote(?:\\s+expandable)?>$/i', $tag) === 1) {
                $stack[] = 'blockquote';
                continue;
            }
            if (preg_match('/^<a\\s+href="(https?:\\/\\/[^"]+)">$/i', $tag, $m) === 1) {
                $host = parse_url($m[1], PHP_URL_HOST);
                if (!is_string($host) || $host === '' || !UrlGuard::isSafeLink($m[1])) {
                    return 'Ссылка в подписи имеет недопустимый адрес.';
                }
                $stack[] = 'a';
                continue;
            }
            if (preg_match('/^<\\/a>$/i', $tag) === 1) {
                if (array_pop($stack) !== 'a') {
                    return 'HTML-теги в подписи закрыты в неверном порядке.';
                }
                continue;
            }

            return 'Поддерживаются только теги b, i, code, blockquote, blockquote expandable, tg-spoiler и ссылки a href="https://…".';
        }
        if ($stack !== []) {
            return 'В подписи есть незакрытый HTML-тег.';
        }

        return null;
    }

    /** @return array<int,string> сети, готовые к публикации */
    public static function readyNetworks(): array
    {
        return array_values(array_filter(SocialPublisher::NETWORKS, [self::class, 'isReady']));
    }

    /**
     * Полезная нагрузка поста из строки новости. gallery — абсолютные ссылки
     * на дополнительные фото (для Telegram sendMediaGroup).
     * @param array<string,mixed> $news
     * @return array{message:string, link:string, image_url:string, title:string,
     *     hashtags:?string, category:string, date:string, gallery:list<string>,
     *     gallery_meta:array<string,array{caption:string,credit:string}>,
     *     langs:list<array<string,mixed>>}
     */
    public static function buildPost(array $news): array
    {
        $base = AppUrl::base();
        $link = $base . '/news/' . rawurlencode((string) $news['slug']);
        $abs = static fn (string $u): string => preg_match('#^https?://#', $u) ? $u : $base . '/' . ltrim($u, '/');

        $title = trim((string) ($news['title'] ?? ''));
        $excerpt = trim((string) ($news['excerpt'] ?? ''));
        $hashtags = News::cleanHashtags($news['hashtags'] ?? null);
        // Текст и хештеги храним раздельно. Публикатор добавляет единый
        // блок тегов после всех языковых ссылок — без склеивания и дублей.
        $message = $excerpt !== '' ? $title . "\n\n" . $excerpt : $title;
        $langs = self::languageBlocks($news, $base);
        // Хештеги переводятся вместе с новостью, а пост один — собираем их со
        // всех языковых версий, без повторов и в порядке появления.
        $hashtags = self::mergeHashtags($hashtags, $langs);

        $cover = News::getCoverImage($news) ?? '';
        if ($cover !== '') {
            $cover = $abs($cover);
        }

        $gallery = [];
        $galleryMeta = [];
        if (!empty($news['id'])) {
            foreach (\App\Models\NewsImage::forNews((int) $news['id']) as $img) {
                $url = $abs((string) $img['path']);
                $gallery[] = $url;
                // Подпись и автор снимка уходят в rich-пост Telegram
                // (<figcaption> с <cite>) — редактор вводит их один раз на сайте.
                $galleryMeta[$url] = [
                    'caption' => trim((string) ($img['caption'] ?? '')),
                    'credit' => trim((string) ($img['credit'] ?? '')),
                ];
            }
        }

        // Рубрика и дата публикации: строкой над заголовком в посте канала —
        // по ней видно, о чём новость, ещё до заголовка.
        $published = trim((string) ($news['published_at'] ?? ''));
        $date = $published !== '' ? DateFormatter::long($published, Language::defaultCode()) : '';

        return [
            'message' => $message,
            'link' => $link,
            'image_url' => $cover,
            'title' => $title,
            'hashtags' => $hashtags,
            // Рубрика поста — категория новости, а не метка: метка («Важно»)
            // говорит о срочности, а не о том, про что материал.
            'category' => self::categoryName($news, Language::defaultCode()),
            'date' => $date,
            'gallery' => $gallery,
            'gallery_meta' => $galleryMeta,
            'langs' => $langs,
        ];
    }

    /**
     * Блоки новости по языкам для двуязычного поста: сначала узбекский
     * (гос. язык), затем русский. Язык попадает в список только если у него
     * есть собственный заголовок — localize() при отсутствии перевода
     * возвращает базовую строку, и без этой проверки текст задвоился бы.
     *
     * @param array<string,mixed> $news
     * @return list<array{code:string,label:string,title:string,excerpt:string,lead_html:string,link:string,read_more:string}>
     */
    /**
     * Название категории новости на указанном языке. Пустая строка, если
     * рубрики нет — строка над заголовком тогда просто не выводится.
     */
    private static function categoryName(array $row, string $lang): string
    {
        $categoryId = (int) ($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return '';
        }

        return trim((string) (\App\Models\NewsCategory::namesForIds([$categoryId], $lang)[$categoryId] ?? ''));
    }

    private static function languageBlocks(array $news, string $base): array
    {
        $default = Language::defaultCode();
        // Метки языков и надпись «читать дальше» на соответствующем языке.
        $meta = [
            'uz' => ['label' => "O‘zbekcha", 'read_more' => "Saytda o‘qish →"],
            'ru' => ['label' => 'Русский', 'read_more' => 'Читать на сайте →'],
            'en' => ['label' => 'English', 'read_more' => 'Read on site →'],
        ];

        // Языковые версии — из единой точки доступа: она знает и про связанные
        // записи со своим slug, и про поля перевода. Своя логика по месту
        // здесь и приводила к тому, что вторая версия уходила отдельным постом.
        $rows = (!empty($news['id']) && Database::isConnected())
            ? Translations::rows('news', (int) $news['id'])
            : [];
        // Переданная строка — источник правды для своего языка: публикуют
        // именно её, в том числе из предпросмотра черновика.
        $ownLang = trim((string) ($news['lang'] ?? '')) ?: $default;
        $rows[$ownLang] = $news;

        $activeCodes = Database::isConnected() ? Language::activeCodes() : ['uz', 'ru', 'en'];
        // Порядок языков в посте: сначала основной (узбекский), затем остальные
        usort($activeCodes, static function (string $a, string $b) use ($default): int {
            if ($a === $default) return -1;
            if ($b === $default) return 1;
            return 0;
        });

        $blocks = [];
        foreach ($activeCodes as $code) {
            if (!isset($rows[$code])) {
                continue; // версии на этом языке нет
            }
            $row = $rows[$code];
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            // Рубрика и дата — на языке своего блока. Иначе под русским
            // заголовком стояло бы «1-avgust, 2026-yil».
            $published = trim((string) ($news['published_at'] ?? ''));
            $slug = (string) ($row['slug'] ?? $news['slug']);
            // Ссылка из Telegram — явный выбор языка, а не продолжение
            // предыдущего визита. Без _lang сохранённая cookie могла открыть
            // узбекскую версию даже по русской ссылке /ru/news/….
            $link = rtrim($base, '/') . Locale::url('news/' . rawurlencode($slug), $code)
                . '?' . http_build_query([LocalePreference::QUERY => $code], '', '&', PHP_QUERY_RFC3986);
            $blocks[] = [
                'code' => $code,
                'label' => $meta[$code]['label'] ?? mb_strtoupper($code),
                'title' => $title,
                'excerpt' => trim((string) ($row['excerpt'] ?? '')),
                'lead_html' => trim((string) ($row['lead_html'] ?? '')),
                // У связанной записи свой адрес — ведём на него, а не на
                // перевод базового slug.
                'link' => $link,
                'read_more' => $meta[$code]['read_more'] ?? ('Read (' . strtoupper($code) . ') →'),
                'category' => self::categoryName($row, $code),
                'date' => $published !== '' ? DateFormatter::long($published, $code) : '',
                'hashtags' => (string) (News::cleanHashtags($row['hashtags'] ?? null) ?? ''),
            ];
        }

        return $blocks;
    }

    /**
     * Публичные https-снимки поста: обложка и галерея, без повторов. Тот же
     * список, что уходит в Telegram, — нужен предпросмотру в админке.
     *
     * @param array<string,mixed> $post
     * @return list<string>
     */
    public static function telegramPhotoUrls(array $post): array
    {
        $photos = [];
        foreach (array_merge(
            !empty($post['image_url']) ? [(string) $post['image_url']] : [],
            array_map('strval', (array) ($post['gallery'] ?? []))
        ) as $url) {
            if (str_starts_with($url, 'https://') && !in_array($url, $photos, true)) {
                $photos[] = $url;
            }
        }

        return $photos;
    }

    /**
     * Хештеги поста: базовые плюс хештеги языковых версий. Пост один, а теги
     * у перевода свои — раньше в канал уходили только базовые.
     *
     * @param list<array<string,mixed>> $langs
     */
    private static function mergeHashtags(?string $base, array $langs): ?string
    {
        $tags = [];
        $add = static function (string $raw) use (&$tags): void {
            foreach (preg_split('/\s+/u', trim($raw)) ?: [] as $tag) {
                $tag = trim($tag);
                // Ключ без регистра: #Реформы и #реформы — один и тот же тег.
                if ($tag !== '' && !isset($tags[mb_strtolower($tag)])) {
                    $tags[mb_strtolower($tag)] = $tag;
                }
            }
        };

        $add((string) $base);
        foreach ($langs as $lang) {
            $add((string) ($lang['hashtags'] ?? ''));
        }

        return $tags === [] ? $base : implode(' ', array_values($tags));
    }

    /**
     * Языки сайта, версии на которых в пост не попадут: перевод не заполнен.
     * Считаем той же функцией, что собирает пост, — иначе подсказка редактору
     * рано или поздно разойдётся с тем, что уходит в канал.
     *
     * @param array<string,mixed> $news
     * @return list<string> коды языков
     */
    public static function missingPostLangs(array $news): array
    {
        if (!Database::isConnected() || empty($news['id'])) {
            return [];
        }
        $present = array_column(self::languageBlocks($news, AppUrl::base()), 'code');

        return array_values(array_filter(
            Language::activeCodes(),
            static fn (string $code): bool => !in_array($code, $present, true)
        ));
    }

    /**
     * Время отложенной отправки из формы админки в формат БД.
     * null — отправить при ближайшем запуске воркера. Прошедшее время тоже
     * даёт null: «опубликовать вчера» означает «опубликовать сейчас», а не
     * молча зависшую задачу.
     */
    public static function normalizeScheduleTime(string $raw, ?int $now = null): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // datetime-local отдаёт «2026-08-05T09:30», без секунд.
        $ts = strtotime(str_replace('T', ' ', $raw));
        $now = $now ?? time();
        if ($ts === false || $ts <= $now) {
            return null;
        }
        // Дальше года — почти наверняка опечатка в дате.
        if ($ts > $now + 31536000) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Ставит новость в очередь публикации в готовые сети. Если задан $only —
     * только в эту сеть (кнопка конкретной соцсети), иначе во все готовые.
     */
    public static function enqueueForNews(int $newsId, ?string $only = null, bool $force = false, ?string $scheduledAt = null): int
    {
        // Публикуем от имени записи основного языка: у связанных переводов
        // своя строка в news, и кнопка у русской версии ставила в очередь
        // вторую задачу — в канал уходило два поста вместо одного.
        if (Database::isConnected()) {
            $newsId = News::socialPrimaryId($newsId);
        }
        $count = 0;
        foreach (self::readyNetworks() as $network) {
            if ($only !== null && $network !== $only) {
                continue;
            }
            SocialPost::enqueue($newsId, $network, $force, $scheduledAt);
            $count++;
        }

        return $count;
    }

    /**
     * Отправляет одну строку очереди немедленно. Та же логика, что в воркере
     * (app/Console/social_worker.php) — единый источник правды. Обновляет
     * статус строки (sent/failed) и возвращает результат.
     *
     * @param array<string, mixed> $row
     * @return array{ok: bool, error: ?string}
     */
    public static function dispatchRow(array $row, ?SocialPublisher $publisher = null): array
    {
        $id = (int) $row['id'];
        $network = (string) $row['network'];
        $news = News::findById((int) $row['news_id']);

        if ($news === null || ($news['status'] ?? '') !== 'published' || !empty($news['deleted_at'])) {
            $err = 'Новость недоступна или не опубликована.';
            SocialPost::markFailed($id, $err);

            return ['ok' => false, 'error' => $err];
        }
        if (!self::isReady($network)) {
            $err = 'Сеть ' . $network . ' не настроена/выключена.';
            SocialPost::markFailed($id, $err);

            return ['ok' => false, 'error' => $err];
        }

        $publisher ??= new SocialPublisher();
        $result = $publisher->publish($network, self::configFor($network), self::buildPost($news));

        if ($result['ok']) {
            SocialPost::markSent($id, $result['remote_id']);

            return ['ok' => true, 'error' => null];
        }

        SocialPost::markFailed($id, (string) $result['error']);

        return ['ok' => false, 'error' => (string) $result['error']];
    }

    /**
     * Обрабатывает очередь публикаций — та же работа, что воркер по Cron:
     * забирает порцию pending и отправляет. Используется и кнопкой «запустить
     * сейчас» в админке, и CLI-воркером.
     *
     * @return array{sent: int, failed: int, taken: int, busy: bool}
     */
    public static function dispatchQueue(int $limit = 20): array
    {
        $lock = ProcessLock::acquire('social_worker');
        if ($lock === null) {
            return ['sent' => 0, 'failed' => 0, 'taken' => 0, 'busy' => true];
        }
        try {
            $batch = SocialPost::pendingBatch(max(1, min(50, $limit)));
            if ($batch === []) {
                return ['sent' => 0, 'failed' => 0, 'taken' => 0, 'busy' => false];
            }

            $publisher = new SocialPublisher();
            $sent = 0;
            $failed = 0;
            foreach ($batch as $row) {
                $res = self::dispatchRow($row, $publisher);
                if ($res['ok']) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            return [
                'sent' => $sent,
                'failed' => $failed,
                'taken' => count($batch),
                'busy' => false,
            ];
        } finally {
            ProcessLock::release($lock);
        }
    }

    /**
     * Немедленная отправка всех pending-публикаций новости (кнопка «в соцсети»).
     * Что не удалось — остаётся в очереди (status pending) и будет дослано
     * воркером по расписанию.
     *
     * @return array{sent: int, failed: int, errors: array<int, string>, busy: bool}
     */
    public static function dispatchPendingForNews(int $newsId, ?string $only = null): array
    {
        $lock = ProcessLock::acquire('social_worker');
        if ($lock === null) {
            return ['sent' => 0, 'failed' => 0, 'errors' => [], 'busy' => true];
        }
        $sent = 0;
        $failed = 0;
        $errors = [];
        $publisher = new SocialPublisher();

        try {
            foreach (SocialPost::forNews($newsId) as $row) {
                if ((string) ($row['status'] ?? '') !== 'pending') {
                    continue;
                }
                if ($only !== null && (string) ($row['network'] ?? '') !== $only) {
                    continue;
                }
                $res = self::dispatchRow($row, $publisher);
                if ($res['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = (string) ($row['network'] ?? '') . ': ' . (string) $res['error'];
                }
            }
        } finally {
            ProcessLock::release($lock);
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors, 'busy' => false];
    }
}
