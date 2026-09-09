<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Публикация новости в соцсети через их официальные API нативным HTTP-клиентом
 * (без сторонних библиотек). Поддержаны Facebook (Page feed), LinkedIn
 * (organization/person share), Instagram (Graph, двухшаговая публикация) и
 * Telegram-канал (Bot API: sendMessage / sendPhoto / sendMediaGroup для
 * галерей).
 *
 * HTTP-транспорт инжектируется (callable), что делает адаптеры тестируемыми
 * без реальных запросов к сетям.
 *
 * @phpstan-type Post array{message:string, link:string, image_url?:string, title?:string, gallery?:list<string>}
 * @phpstan-type Result array{ok:bool, remote_id:?string, error:?string}
 */
final class SocialPublisher
{
    public const NETWORKS = ['telegram', 'facebook', 'linkedin', 'instagram'];
    private const GRAPH = 'https://graph.facebook.com/v19.0';
    private const TG_API = 'https://api.telegram.org';

    /** Лимиты Telegram: подпись к медиа — 1024 символа, сообщение — 4096. */
    private const TG_CAPTION_LIMIT = 1024;
    private const TG_TEXT_LIMIT = 4096;

    /** @var callable(string,string,string,array):array */
    private $http;

    /** @param callable|null $http fn(method,url,body,headers):array{status,body,error} */
    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? static fn (string $m, string $u, string $b, array $h) => Http::request($m, $u, $b, $h);
    }

    /**
     * @param array<string,string> $cfg
     * @param array{message:string, link:string, image_url?:string} $post
     * @return array{ok:bool, remote_id:?string, error:?string}
     */
    public function publish(string $network, array $cfg, array $post): array
    {
        return match ($network) {
            'telegram' => $this->telegram($cfg, $post),
            'facebook' => $this->facebook($cfg, $post),
            'linkedin' => $this->linkedin($cfg, $post),
            'instagram' => $this->instagram($cfg, $post),
            default => ['ok' => false, 'remote_id' => null, 'error' => 'Неизвестная сеть: ' . $network],
        };
    }

    /**
     * Telegram-канал: галерея — sendMediaGroup (до 10 фото, подпись у первого),
     * одно фото — sendPhoto, без фото — sendMessage. Подпись: жирный заголовок,
     * анонс и ссылка «Читать на сайте» (HTML-разметка).
     */
    private function telegram(array $cfg, array $post): array
    {
        if (empty($cfg['token']) || empty($cfg['chat_id'])) {
            return self::err('Не заданы токен бота или chat_id канала Telegram.');
        }

        // Расширенный формат (Bot API 10.1+): текст до 32768 символов вместо
        // 1024 у подписи, вторая языковая версия — под «развернуть». Если метод
        // не поддержан или формат не принят, публикация не срывается: ниже
        // отрабатывает прежний путь sendPhoto/sendMessage.
        $format = (string) ($cfg['format'] ?? 'auto');
        if ($format !== 'classic') {
            $rich = $this->telegramRich($cfg, $post);
            if ($rich !== null && ($rich['ok'] || $format === 'rich')) {
                return $rich;
            }
        }

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Публичные https-изображения: обложка + галерея, максимум 10 (лимит API).
        $photos = array_slice($this->telegramPhotos($post), 0, 10);

        // Полный текст без урезания. Если он не влезает в подпись к фото,
        // снимок уходит отдельным сообщением, а текст — обычным: у сообщения
        // лимит 4096 против 1024 у подписи, и анонсы перестают резаться.
        $full = self::telegramCaption($post, (string) ($cfg['signature'] ?? ''), self::TG_TEXT_LIMIT, $esc);
        $splitText = $photos !== [] && mb_strlen(strip_tags($full)) > self::TG_CAPTION_LIMIT;
        $caption = $splitText
            ? ''
            : ($photos !== []
                ? self::telegramCaption($post, (string) ($cfg['signature'] ?? ''), self::TG_CAPTION_LIMIT, $esc)
                : $full);
        $buttons = self::telegramButtons($post, $cfg);

        // Токен используется в URL как есть: rawurlencode ломал бы двоеточие
        // (12345:AAH… → 12345%3AAAH…), и Bot API отвечал бы 404 «Not Found».
        // Токен — доверенная настройка суперадмина; лишь срезаем случайные
        // пробелы/переводы строк от копипаста.
        $api = self::TG_API . '/bot' . trim((string) $cfg['token']);
        $headers = ['Content-Type: application/json'];

        if (count($photos) >= 2) {
            $media = [];
            foreach ($photos as $i => $url) {
                $item = ['type' => 'photo', 'media' => $url];
                if ($i === 0) {
                    $item['caption'] = $caption;
                    $item['parse_mode'] = 'HTML';
                    if (!empty($cfg['show_caption_above_media']) || !empty($post['show_caption_above_media'])) {
                        $item['show_caption_above_media'] = true;
                    }
                }
                $media[] = $item;
            }
            $payload = ['chat_id' => $cfg['chat_id'], 'media' => $media];
            if (!empty($cfg['silent'])) {
                $payload['disable_notification'] = true;
            }
            // Тело поста собрано из заголовка и анонса новости, то есть из
            // текста редактора. Приведение `(string)` отправило бы в Telegram
            // пустую строку вместо публикации, и ошибка пришла бы уже от их
            // API — про «неверный запрос», а не про негодный байт у нас.
            $res = ($this->http)('POST', $api . '/sendMediaGroup', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            $group = $this->interpretTelegram($res, true);
            // К альбому кнопку прикрепить нельзя — она уходит с текстом следом.
            if ($group['ok'] && $splitText) {
                $this->telegramText($cfg, $api, $headers, $full, $buttons, $post);
            }

            return $group;
        }

        if (count($photos) === 1) {
            $payload = [
                'chat_id' => $cfg['chat_id'],
                'photo' => $photos[0],
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ];
            if (!empty($cfg['show_caption_above_media']) || !empty($post['show_caption_above_media'])) {
                $payload['show_caption_above_media'] = true;
            }
            if (!$splitText && $buttons !== []) {
                $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
            }
            if (!empty($cfg['silent'])) {
                $payload['disable_notification'] = true;
            }
            $res = ($this->http)('POST', $api . '/sendPhoto', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            $photoRes = $this->interpretTelegram($res);
            if ($photoRes['ok'] && $splitText) {
                $this->telegramText($cfg, $api, $headers, $full, $buttons, $post);
            }

            return $photoRes;
        }

        return $this->telegramText($cfg, $api, $headers, $caption, $buttons, $post);
    }

    /**
     * Произвольное сообщение в канал (не привязанное к новости): итоги недели
     * и подобные служебные посты. Разметка — HTML Telegram; обложка
     * необязательна и никогда не отменяет сам пост.
     *
     * @param array<string,string> $cfg
     * @return array{ok:bool, remote_id:?string, error:?string}
     */
    public function sendChannelMessage(array $cfg, string $html, string $photoUrl = ''): array
    {
        if (empty($cfg['token']) || empty($cfg['chat_id'])) {
            return self::err('Не заданы токен бота или chat_id канала Telegram.');
        }
        if (trim($html) === '') {
            return self::err('Пустое сообщение не отправляем.');
        }

        $api = self::TG_API . '/bot' . trim((string) $cfg['token']);
        $headers = ['Content-Type: application/json'];
        $photoUrl = str_starts_with(trim($photoUrl), 'https://') ? trim($photoUrl) : '';

        // Список из десятка ссылок легко перерастает лимит подписи к фото
        // (1024 против 4096 у сообщения). Тогда обложка уходит первой, текст —
        // следом: обрезать список ради картинки бессмысленно.
        $fitsCaption = mb_strlen(strip_tags($html)) <= self::TG_CAPTION_LIMIT;
        if ($photoUrl !== '' && $fitsCaption) {
            $payload = [
                'chat_id' => $cfg['chat_id'],
                'photo' => $photoUrl,
                'caption' => $html,
                'parse_mode' => 'HTML',
            ];
            if (!empty($cfg['silent'])) {
                $payload['disable_notification'] = true;
            }
            $res = ($this->http)('POST', $api . '/sendPhoto', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            $photoRes = $this->interpretTelegram($res);
            if ($photoRes['ok']) {
                return $photoRes;
            }
            // Картинка не принята (недоступна, слишком большая) — пост важнее
            // обложки, отправляем текстом.
        } elseif ($photoUrl !== '') {
            $payload = ['chat_id' => $cfg['chat_id'], 'photo' => $photoUrl];
            if (!empty($cfg['silent'])) {
                $payload['disable_notification'] = true;
            }
            $res = ($this->http)('POST', $api . '/sendPhoto', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            $this->interpretTelegram($res);
        }

        return $this->telegramText($cfg, $api, $headers, $html, [], []);
    }

    /**
     * Обычное текстовое сообщение с кнопками. Вынесено отдельно: тем же путём
     * уходит текст, не поместившийся в подпись к фото.
     *
     * @param array<string,string> $cfg
     * @param list<string> $headers
     * @param list<array{text:string,url:string}> $buttons
     * @param array<string,mixed> $post
     * @return array{ok:bool, remote_id:?string, error:?string}
     */
    private function telegramText(array $cfg, string $api, array $headers, string $text, array $buttons, array $post): array
    {
        $payload = [
            'chat_id' => $cfg['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }
        if (!empty($cfg['silent'])) {
            $payload['disable_notification'] = true;
        }
        if (!empty($cfg['link_preview_options']) && is_array($cfg['link_preview_options'])) {
            $payload['link_preview_options'] = $cfg['link_preview_options'];
        } elseif (!empty($post['link_preview_options']) && is_array($post['link_preview_options'])) {
            $payload['link_preview_options'] = $post['link_preview_options'];
        }
        $res = ($this->http)('POST', $api . '/sendMessage', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);

        return $this->interpretTelegram($res);
    }

    /**
     * Кнопки под постом — по одной на язык. Ссылка в теле поста остаётся:
     * при пересылке кнопки не всегда переносятся вместе с сообщением.
     *
     * @param array<string,mixed> $post
     * @return list<array{text:string,url:string}>
     */
    private static function telegramButtons(array $post, array $cfg = []): array
    {
        // Ссылка на каждую версию уже стоит в тексте своего языкового блока,
        // поэтому по умолчанию кнопок нет: в посте, который редактор потом
        // дорабатывает руками, они лишний ряд. Включаются галочкой в настройках.
        if ((string) ($cfg['buttons'] ?? '') !== '1') {
            return [];
        }

        $buttons = [];
        foreach ((array) ($post['langs'] ?? []) as $lang) {
            $url = trim((string) ($lang['link'] ?? ''));
            $text = trim((string) ($lang['read_more'] ?? ''));
            if ($url === '' || !str_starts_with($url, 'https://')) {
                continue;
            }
            // Стрелка в подписи кнопки лишняя: кнопка и так ведёт наружу.
            $buttons[] = ['text' => rtrim(str_replace('→', '', $text)) ?: 'Открыть', 'url' => $url];
        }

        return array_slice($buttons, 0, 3);
    }

    /**
     * Публикация расширенным форматом (`sendRichMessage`, Bot API 10.1+).
     * Возвращает null, если формат к этому посту неприменим — тогда работает
     * прежний путь.
     *
     * @param array<string,string> $cfg
     * @param array<string,mixed> $post
     * @return array{ok:bool, remote_id:?string, error:?string}|null
     */
    private function telegramRich(array $cfg, array $post): ?array
    {
        $langs = (array) ($post['langs'] ?? []);
        if ($langs === []) {
            return null;
        }

        $doc = TelegramRichMessage::build(
            $langs,
            $this->telegramPhotos($post),
            (string) ($cfg['signature'] ?? ''),
            (string) ($post['category'] ?? ''),
            (string) ($post['date'] ?? ''),
            (string) ($post['hashtags'] ?? ''),
            (array) ($post['gallery_meta'] ?? []),
            (string) ($cfg['second_lang'] ?? '') === 'details' ? 'details' : 'inline'
        );
        if ($doc['html'] === '' || !TelegramRichMessage::fits($doc['html'])) {
            return null;
        }

        // Снимки в разметке — ссылками tg://photo?id=…, сами файлы уходят
        // полем media: прямой https-адрес в <img> API не принимает.
        $rich = ['html' => $doc['html']];
        if ($doc['media'] !== []) {
            $rich['media'] = $doc['media'];
        }
        $payload = [
            'chat_id' => $cfg['chat_id'],
            'rich_message' => $rich,
        ];
        $buttons = self::telegramButtons($post, $cfg);
        if ($buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }
        if (!empty($cfg['silent'])) {
            $payload['disable_notification'] = true;
        }
        $res = ($this->http)(
            'POST',
            self::TG_API . '/bot' . trim((string) $cfg['token']) . '/sendRichMessage',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ['Content-Type: application/json']
        );

        return $this->interpretTelegram($res);
    }

    /**
     * Публичные https-изображения поста: обложка и галерея, без повторов.
     *
     * @param array<string,mixed> $post
     * @return list<string>
     */
    private function telegramPhotos(array $post): array
    {
        // Один источник правды: тем же списком пользуется предпросмотр в
        // админке, и расхождение здесь означало бы «в превью одно, в канале
        // другое».
        return SocialSettings::telegramPhotoUrls($post);
    }

    /**
     * Проверка подключения к Telegram без публикации: токен (getMe), канал
     * (getChat) и права бота в нём (getChatMember). Диагностика по шагам —
     * иначе единственное «Not Found» в журнале ничего не объясняет.
     *
     * @param array<string,string> $cfg
     * @return array{ok:bool, steps:list<array{name:string, ok:bool, text:string}>}
     */
    public function checkTelegram(array $cfg): array
    {
        $token = trim((string) ($cfg['token'] ?? ''));
        $chatId = trim((string) ($cfg['chat_id'] ?? ''));
        $steps = [];

        if ($token === '' || $chatId === '') {
            $steps[] = ['name' => 'Настройки', 'ok' => false, 'text' => 'Не заполнены токен бота или chat_id канала.'];

            return ['ok' => false, 'steps' => $steps];
        }

        $api = self::TG_API . '/bot' . $token;
        // Транспортную ошибку (нет сети, закрытый исходящий доступ на хостинге)
        // важно не смешивать с ответом API: иначе «нет интернета» выглядело бы
        // как «неверный токен», и чинили бы не то.
        $call = function (string $method, array $payload = []) use ($api): array {
            $res = ($this->http)(
                'POST',
                $api . '/' . $method,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ['Content-Type: application/json']
            );
            $data = json_decode((string) ($res['body'] ?? ''), true);
            if (is_array($data)) {
                return $data;
            }

            $transport = trim((string) ($res['error'] ?? ''));

            return ['ok' => false, 'description' => $transport !== ''
                ? 'Нет связи с api.telegram.org: ' . $transport
                : 'Пустой ответ от api.telegram.org (HTTP ' . (int) ($res['status'] ?? 0) . '). '
                    . 'Возможно, хостинг блокирует исходящие запросы.'];
        };

        // 1. Токен. Bot API отдаёт 404 «Not Found» именно на неверный токен:
        // /bot<токен>/ — часть адреса, у несуществующего бота нет такого пути.
        $me = $call('getMe');
        if (empty($me['ok'])) {
            $steps[] = [
                'name' => 'Токен бота',
                'ok' => false,
                'text' => self::telegramHint((string) ($me['description'] ?? 'нет ответа')),
            ];

            return ['ok' => false, 'steps' => $steps];
        }
        $botId = (int) ($me['result']['id'] ?? 0);
        $botName = (string) ($me['result']['username'] ?? '');
        $steps[] = ['name' => 'Токен бота', 'ok' => true, 'text' => 'Бот найден: @' . $botName];

        // 2. Канал.
        $chat = $call('getChat', ['chat_id' => $chatId]);
        if (empty($chat['ok'])) {
            $steps[] = [
                'name' => 'Канал',
                'ok' => false,
                'text' => self::telegramHint((string) ($chat['description'] ?? 'нет ответа')),
            ];

            return ['ok' => false, 'steps' => $steps];
        }
        $steps[] = [
            'name' => 'Канал',
            'ok' => true,
            'text' => 'Канал найден: ' . (string) ($chat['result']['title'] ?? $chatId),
        ];

        // 3. Права: писать в канал может только администратор.
        $member = $call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
        $status = (string) ($member['result']['status'] ?? '');
        if (empty($member['ok']) || !in_array($status, ['administrator', 'creator'], true)) {
            $steps[] = [
                'name' => 'Права бота',
                'ok' => false,
                'text' => 'Бот @' . $botName . ' не администратор канала'
                    . ($status !== '' ? ' (статус: ' . $status . ')' : '')
                    . '. Откройте канал → «Администраторы» → добавьте бота с правом публикации сообщений.',
            ];

            return ['ok' => false, 'steps' => $steps];
        }
        $steps[] = ['name' => 'Права бота', 'ok' => true, 'text' => 'Бот — администратор канала, публикация разрешена.'];

        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * Перевод ответа Bot API в понятное действие. Сухие описания Telegram
     * («Not Found», «chat not found») ничего не говорят редактору о том, что
     * именно чинить.
     */
    public static function telegramHint(string $description): string
    {
        $d = mb_strtolower($description);

        if (str_contains($d, 'not found') && !str_contains($d, 'chat')) {
            return 'Токен бота неверен или отозван (Telegram отвечает «Not Found»). '
                . 'Проверьте токен у @BotFather: он выглядит как 1234567890:AA… — целиком, без слова «bot» и пробелов.';
        }
        if (str_contains($d, 'chat not found')) {
            return 'Канал не найден: проверьте chat_id. Для публичного канала — @имя_канала, '
                . 'для приватного — числовой id вида -100…, и бот должен быть добавлен в канал.';
        }
        if (str_contains($d, 'not enough rights') || str_contains($d, 'not a member') || str_contains($d, 'chat_write_forbidden')) {
            return 'У бота нет прав на публикацию: добавьте его администратором канала с правом отправки сообщений.';
        }
        if (str_contains($d, 'unauthorized')) {
            return 'Telegram отклонил токен (401 Unauthorized). Выпустите новый токен у @BotFather и сохраните здесь.';
        }
        if (str_contains($d, "can't parse entities") || str_contains($d, 'can\'t parse')) {
            return 'Telegram не принял разметку сообщения: проверьте HTML в подписи (допустимы <b>, <i>, <a href>).';
        }

        return $description;
    }

    /** Разбор ответа Bot API; для sendMediaGroup result — массив сообщений. */
    private function interpretTelegram(array $res, bool $group = false): array
    {
        $data = json_decode($res['body'] ?? '', true);
        if (is_array($data) && !empty($data['ok'])) {
            $msg = $group ? ($data['result'][0] ?? []) : ($data['result'] ?? []);
            $remoteId = isset($msg['message_id']) ? (string) $msg['message_id'] : null;

            return ['ok' => true, 'remote_id' => $remoteId, 'error' => null];
        }
        if (is_array($data) && isset($data['description'])) {
            // В журнал очереди пишем и подсказку, и исходный ответ Telegram:
            // первое нужно редактору, второе — при разборе нетипичных случаев.
            $raw = (string) $data['description'];
            $hint = self::telegramHint($raw);

            return self::err($hint === $raw ? $raw : $hint . ' (ответ Telegram: ' . $raw . ')');
        }
        $error = !empty($res['error']) ? (string) $res['error'] : 'HTTP ' . (int) ($res['status'] ?? 0);

        return self::err($error);
    }

    /**
     * Подпись поста Telegram: блоки языков (узбекский, затем русский),
     * ссылки на обе версии и подпись из настроек (HTML). Лимит жёсткий —
     * 1024 символа с фото, поэтому фиксированная часть (заголовки, ссылки,
     * подпись) резервируется, а остаток делится поровну между анонсами.
     *
     * @param array<string,mixed> $post
     * @param callable(string):string $esc
     */
    private static function telegramCaption(array $post, string $signature, int $limit, callable $esc): string
    {
        $langs = (array) ($post['langs'] ?? []);
        if ($langs === []) {
            // Запасной вариант для старых вызовов без языковых блоков.
            $langs = [[
                'title' => (string) ($post['title'] ?? ''),
                'excerpt' => trim((string) ($post['message'] ?? '')),
                'link' => (string) ($post['link'] ?? ''),
                'read_more' => 'Читать на сайте →',
            ]];
        }

        $sep = "\n\n———\n\n";
        // Ссылка идёт сразу за своим языковым блоком: сваленные в конец ссылки
        // читателю приходилось сопоставлять с языком по догадке.
        $linkFor = static function (array $l) use ($esc): string {
            return ($l['link'] ?? '') !== ''
                ? "\n\n" . '<a href="' . $esc((string) $l['link']) . '"><b>' . $esc((string) $l['read_more']) . '</b></a>'
                : '';
        };
        // Рубрика и дата — служебной строкой над заголовком своего языка:
        // они переводятся, и под русским заголовком не должно стоять
        // «1-avgust, 2026-yil». Хештеги — в самом низу, они общие.
        $metaFor = static function (array $l) use ($esc, $post): string {
            $meta = array_values(array_filter([
                trim((string) ($l['category'] ?? $post['category'] ?? '')),
                trim((string) ($l['date'] ?? $post['date'] ?? '')),
            ], static fn (string $v): bool => $v !== ''));

            return $meta === [] ? '' : '<b>' . $esc(implode(' · ', $meta)) . '</b>' . "\n\n";
        };
        $hashtagLines = TelegramRichMessage::hashtagLines(
            $langs,
            trim((string) ($post['hashtags'] ?? ''))
        );
        $hashtagHtml = implode("\n", array_map(
            static fn (string $line): string => '<b>' . $esc($line) . '</b>',
            $hashtagLines
        ));
        // Classic HTML не поддерживает <mark>, поэтому используем жирное
        // выделение. Языковые наборы остаются отдельными строками.
        $tail = ($hashtagHtml !== '' ? "\n\n\n" . $hashtagHtml : '')
            . ($signature !== '' ? "\n\n" . $signature : '');

        // Считаем фиксированную часть: заголовки, ссылки, разделители, подпись.
        $fixed = mb_strlen(strip_tags($tail))
            + (count($langs) - 1) * mb_strlen(strip_tags($sep));
        foreach ($langs as $l) {
            $fixed += mb_strlen((string) $l['title']) + 2; // +2 — перенос строки после заголовка
            $fixed += mb_strlen(strip_tags($linkFor($l)));
            $fixed += mb_strlen(strip_tags($metaFor($l)));
        }
        $available = max(0, $limit - $fixed - 4);
        $perLang = count($langs) > 0 ? (int) floor($available / count($langs)) : 0;

        $parts = [];
        foreach ($langs as $l) {
            $title = trim((string) $l['title']);
            $excerpt = trim((string) ($l['excerpt'] ?? ''));
            if ($perLang > 0 && mb_strlen($excerpt) > $perLang) {
                $excerpt = rtrim(mb_substr($excerpt, 0, max(0, $perLang - 1))) . '…';
            } elseif ($perLang <= 0) {
                $excerpt = '';
            }
            $parts[] = $metaFor($l)
                . ($title !== '' ? '<b>' . $esc($title) . '</b>' : '')
                . ($excerpt !== '' ? "\n\n" . $esc($excerpt) : '')
                . $linkFor($l);
        }

        return implode($sep, $parts) . $tail;
    }

    /**
     * Текст поста для сетей без разметки (Facebook/LinkedIn/Instagram):
     * блоки языков подряд и голые URL — платформы линкуют их сами
     * (в Instagram ссылки некликабельны, там подпись обычно с хештегами).
     *
     * @param array<string,mixed> $post
     */
    private static function plainMessage(array $post, string $signature, int $limit = 0): string
    {
        $langs = (array) ($post['langs'] ?? []);
        if ($langs === []) {
            $text = trim((string) ($post['message'] ?? '')) . "\n\n" . (string) ($post['link'] ?? '');
        } else {
            $parts = [];
            foreach ($langs as $l) {
                $title = trim((string) $l['title']);
                $excerpt = trim((string) ($l['excerpt'] ?? ''));
                $parts[] = $title . ($excerpt !== '' ? "\n\n" . $excerpt : '') . "\n" . (string) $l['link'];
            }
            $text = implode("\n\n———\n\n", $parts);
        }
        // Facebook, LinkedIn и Instagram используют тот же объединённый
        // блок тегов после всех языковых ссылок, что и Telegram.
        $hashtags = TelegramRichMessage::normalizeHashtags((string) ($post['hashtags'] ?? ''));
        $tail = ($hashtags !== '' ? "\n\n\n" . $hashtags : '')
            . ($signature !== '' ? "\n\n" . $signature : '');
        $text = trim($text);
        if ($limit > 0 && mb_strlen($text . $tail) > $limit) {
            $available = max(0, $limit - mb_strlen($tail));
            $text = $available > 1
                ? rtrim(mb_substr($text, 0, $available - 1)) . '…'
                : '';
        }

        return trim($text . $tail);
    }

    private function facebook(array $cfg, array $post): array
    {
        if (empty($cfg['token']) || empty($cfg['page_id'])) {
            return self::err('Не заданы токен или ID страницы Facebook.');
        }
        $url = self::GRAPH . '/' . rawurlencode($cfg['page_id']) . '/feed';
        $body = http_build_query([
            'message' => self::plainMessage($post, (string) ($cfg['signature'] ?? '')),
            'link' => $post['link'],
            'access_token' => $cfg['token'],
        ]);
        $res = ($this->http)('POST', $url, $body, ['Content-Type: application/x-www-form-urlencoded']);

        return $this->interpretGraph($res);
    }

    private function linkedin(array $cfg, array $post): array
    {
        if (empty($cfg['token']) || empty($cfg['author'])) {
            return self::err('Не заданы токен или автор (URN) LinkedIn.');
        }
        $payload = [
            'author' => $cfg['author'],
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    // LinkedIn: лимит текста поста — 3000 символов.
                    'shareCommentary' => ['text' => self::plainMessage($post, (string) ($cfg['signature'] ?? ''), 3000)],
                    'shareMediaCategory' => 'ARTICLE',
                    'media' => [[
                        'status' => 'READY',
                        'originalUrl' => $post['link'],
                    ]],
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];
        $res = ($this->http)(
            'POST',
            'https://api.linkedin.com/v2/ugcPosts',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            [
                'Authorization: Bearer ' . $cfg['token'],
                'X-Restli-Protocol-Version: 2.0.0',
                'Content-Type: application/json',
            ]
        );

        $data = json_decode($res['body'] ?? '', true);
        if (($res['status'] === 200 || $res['status'] === 201) && !empty($data['id'])) {
            return ['ok' => true, 'remote_id' => (string) $data['id'], 'error' => null];
        }

        return self::err(self::extractError($res, $data));
    }

    private function instagram(array $cfg, array $post): array
    {
        if (empty($cfg['token']) || empty($cfg['user_id'])) {
            return self::err('Не заданы токен или IG user ID.');
        }
        if (empty($post['image_url']) || !preg_match('#^https?://#', (string) $post['image_url'])) {
            return self::err('Для Instagram нужна публичная ссылка на изображение (обложка новости).');
        }

        // Шаг 1: создаём медиа-контейнер.
        $createUrl = self::GRAPH . '/' . rawurlencode($cfg['user_id']) . '/media';
        $createBody = http_build_query([
            'image_url' => $post['image_url'],
            // Instagram: лимит подписи — 2200 символов.
            'caption' => self::plainMessage($post, (string) ($cfg['signature'] ?? ''), 2200),
            'access_token' => $cfg['token'],
        ]);
        $c = ($this->http)('POST', $createUrl, $createBody, ['Content-Type: application/x-www-form-urlencoded']);
        $cData = json_decode($c['body'] ?? '', true);
        if (empty($cData['id'])) {
            return self::err('IG: не удалось создать контейнер. ' . self::extractError($c, $cData));
        }

        // Шаг 2: публикуем контейнер.
        $pubUrl = self::GRAPH . '/' . rawurlencode($cfg['user_id']) . '/media_publish';
        $pubBody = http_build_query([
            'creation_id' => (string) $cData['id'],
            'access_token' => $cfg['token'],
        ]);
        $p = ($this->http)('POST', $pubUrl, $pubBody, ['Content-Type: application/x-www-form-urlencoded']);

        return $this->interpretGraph($p);
    }

    /** Общий разбор ответа Graph API (Facebook/Instagram publish). */
    private function interpretGraph(array $res): array
    {
        $data = json_decode($res['body'] ?? '', true);
        if (($res['status'] === 200 || $res['status'] === 201) && !empty($data['id'])) {
            return ['ok' => true, 'remote_id' => (string) $data['id'], 'error' => null];
        }

        return self::err(self::extractError($res, $data));
    }

    private static function extractError(array $res, mixed $data): string
    {
        if (is_array($data) && isset($data['error']['message'])) {
            return (string) $data['error']['message'];
        }
        if (!empty($res['error'])) {
            return (string) $res['error'];
        }

        return 'HTTP ' . (int) ($res['status'] ?? 0);
    }

    private static function err(string $message): array
    {
        return ['ok' => false, 'remote_id' => null, 'error' => $message];
    }
}
