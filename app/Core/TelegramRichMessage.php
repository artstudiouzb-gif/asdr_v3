<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сборка поста для Telegram Rich Messages (Bot API 10.1+, метод
 * `sendRichMessage`). Формат — HTML документа: заголовки, абзацы, разделители,
 * сворачиваемые блоки, изображения с подписями, слайд-шоу, сноска.
 *
 * Зачем это вместо обычной подписи: у подписи к фото жёсткий лимит в 1024
 * символа, и двуязычный пост резался пополам — по ~400 знаков на язык. У
 * rich-сообщения предел 32768 символов и до 50 медиа, поэтому текст уходит
 * целиком, а вторая языковая версия прячется под «развернуть».
 *
 * Класс ничего не отправляет: отдаёт готовый HTML, а транспорт и откат на
 * старый формат — забота SocialPublisher.
 */
final class TelegramRichMessage
{
    /** Пределы rich-сообщения: текст без тегов, число медиа, число блоков. */
    public const TEXT_LIMIT = 32768;
    public const MEDIA_LIMIT = 50;

    /**
     * Собирает пост. Возвращает html документа и список медиа: в разметке
     * снимки указываются ссылками `tg://photo?id=…`, а сами файлы уходят
     * отдельным полем `media` — так требует InputRichMessage.
     *
     * @param list<array{code:string,label:string,title:string,excerpt:string,lead_html?:string,link:string,read_more:string}> $langs
     * @param list<string> $photos абсолютные https-адреса
     * @param array<string, array{caption:string, credit:string}> $photoMeta подписи по адресу снимка
     * @return array{html:string, media:list<array{id:string,media:array{type:string,media:string}}>}
     */
    public static function build(
        array $langs,
        array $photos,
        string $signature = '',
        string $category = '',
        string $date = '',
        string $hashtags = '',
        array $photoMeta = [],
        string $secondLang = 'inline'
    ): array {
        if ($langs === []) {
            return ['html' => '', 'media' => []];
        }

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $photos = array_slice(array_values(array_filter($photos, static fn (string $u): bool => str_starts_with($u, 'https://'))), 0, self::MEDIA_LIMIT);

        $first = $langs[0];
        $rest = array_slice($langs, 1);
        $html = '';

        $media = [];
        foreach ($photos as $i => $url) {
            // Идентификатор — только A-Z, a-z, 0-9, _ и -, как требует API.
            $media[] = [
                'id' => 'p' . ($i + 1),
                'media' => ['type' => 'photo', 'media' => $url],
                'caption' => trim((string) ($photoMeta[$url]['caption'] ?? '')),
                'credit' => trim((string) ($photoMeta[$url]['credit'] ?? '')),
            ];
        }

        // Фото / слайдер — первым элементом поста по стандарту каналов Telegram.
        $html .= self::media($media, $esc);

        // Рубрика и дата — служебной строкой перед заголовком новости.
        $metaLine = static function (array $lang) use ($esc, $category, $date): string {
            $parts = array_values(array_filter([
                trim((string) ($lang['category'] ?? $category)),
                trim((string) ($lang['date'] ?? $date)),
            ], static fn (string $s): bool => $s !== ''));

            return $parts === [] ? '' : '<p><sub>' . $esc(implode(' · ', $parts)) . '</sub></p>';
        };
        $html .= $metaLine($first);

        // Заголовок компактного размера (h2)
        $html .= '<h2>' . $esc($first['title']) . '</h2>';
        $html .= self::body($first, $esc);

        // Остальные языки — тем же текстом следом за разделителем. Под
        // «развернуть» (`details`) пост компактнее, но редактору, который
        // дорабатывает пост в канале руками, спойлер только мешает.
        foreach ($rest as $lang) {
            $html .= '<hr/>';
            // Содержимое языковой версии собирается один раз. Режим меняет
            // только оболочку, поэтому inline и details получают одинаковые
            // заголовок, метаданные, лид и ссылку.
            $section = self::languageSection($lang, $esc, $metaLine);
            if ($secondLang === 'details') {
                $label = trim((string) ($lang['label'] ?? '')) ?: mb_strtoupper((string) ($lang['code'] ?? ''));
                $section = '<details><summary>' . $esc($label) . '</summary>' . $section . '</details>';
            }
            $html .= $section;
        }

        // Хештеги — отдельные выделенные строки по языкам. <mark>
        // поддерживается Rich HTML, а автоматическое распознавание hashtag
        // entities остаётся включённым, поэтому теги сохраняют кликабельность.
        $hashtagLines = self::hashtagLines($langs, $hashtags);
        if ($hashtagLines !== []) {
            $html .= '<hr/>';
            foreach ($hashtagLines as $line) {
                $html .= '<p><mark>' . $esc($line) . '</mark></p>';
            }
        }
        // Подпись редактор задаёт с HTML-тегами Telegram — не экранируем её.
        // Своим блоком, а не внутри абзаца: в подписи бывает и цитата, а
        // <footer> принимает только строчное содержимое.
        if (trim($signature) !== '') {
            $html .= '<footer>' . trim($signature) . '</footer>';
        }

        return [
            'html' => $html,
            'media' => array_map(static fn (array $m): array => ['id' => $m['id'], 'media' => $m['media']], $media),
        ];
    }

    /**
     * Одно фото — картинкой, несколько — слайд-шоу: альбом из десяти снимков
     * занимает в ленте целый экран, а слайд-шоу листается на месте.
     *
     * @param list<array{id:string,media:array{type:string,media:string}}> $media
     */
    private static function media(array $media, callable $esc): string
    {
        if ($media === []) {
            return '';
        }
        // Подпись и автор снимка — документированный <figcaption> с <cite>.
        $img = static function (array $item) use ($esc): string {
            $tag = '<img src="tg://photo?id=' . $esc($item['id']) . '"/>';
            if ($item['caption'] === '' && $item['credit'] === '') {
                return $tag;
            }

            return '<figure>' . $tag . '<figcaption>' . $esc($item['caption'])
                . ($item['credit'] !== '' ? '<cite>' . $esc(Media::photoCredit($item['credit'])) . '</cite>' : '')
                . '</figcaption></figure>';
        };
        if (count($media) === 1) {
            return $img($media[0]);
        }

        $items = '';
        foreach ($media as $item) {
            $items .= $img($item);
        }

        return '<tg-slideshow>' . $items . '</tg-slideshow>';
    }

    /**
     * Тело языкового блока: анонс абзацами и ссылка на страницу новости.
     *
     * @param array{excerpt:string,lead_html?:string,link:string,read_more:string} $lang
     */
    private static function body(array $lang, callable $esc): string
    {
        // NewsLead повторно очищает HTML и оставляет только разметку, которую
        // одинаково безопасно показывать на сайте и отправлять в rich message.
        // Старые записи без lead_html автоматически превращаются в абзацы.
        $html = NewsLead::html(
            (string) ($lang['lead_html'] ?? ''),
            (string) $lang['excerpt']
        );
        if (trim((string) $lang['link']) !== '') {
            // Ссылка — самостоятельная часть публикации. Разделитель не даёт
            // ей визуально сливаться с последним абзацем анонса.
            if ($html !== '') {
                $html .= '<hr/>';
            }
            $html .= '<p><a href="' . $esc((string) $lang['link']) . '"><b>' . $esc((string) $lang['read_more']) . '</b></a></p>';
        }

        return $html;
    }

    /**
     * Полный языковой раздел. Открытый и сворачиваемый режимы используют
     * один результат и отличаются только внешней оболочкой <details>.
     *
     * @param array<string,mixed> $lang
     * @param callable(array):string $metaLine
     */
    private static function languageSection(array $lang, callable $esc, callable $metaLine): string
    {
        return $metaLine($lang)
            . '<h2>' . $esc((string) ($lang['title'] ?? '')) . '</h2>'
            . self::body($lang, $esc);
    }

    /**
     * Группирует хештеги по языковым версиям. Общая строка используется как
     * резерв для старых вызовов и добавляет только теги, которых ещё не было.
     *
     * @param list<array<string,mixed>> $langs
     * @return list<string>
     */
    public static function hashtagLines(array $langs, string $hashtags): array
    {
        $seen = [];
        $lines = [];
        $take = static function (string $raw) use (&$seen): string {
            $normalized = self::normalizeHashtags($raw);
            $line = [];
            foreach (preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
                $key = mb_strtolower($tag);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $line[] = $tag;
                }
            }

            return implode(' ', $line);
        };

        foreach ($langs as $lang) {
            $line = $take((string) ($lang['hashtags'] ?? ''));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $fallback = $take($hashtags);
        if ($fallback !== '') {
            $lines[] = $fallback;
        }

        return $lines;
    }

    /**
     * Делает узбекские хештеги цельными для Telegram. Кавычки U+2018/U+2019
     * и ASCII-апостроф считаются пунктуацией и завершают hashtag entity.
     * U+02BB — буквенный узбекский апостроф (категория Unicode Letter), поэтому
     * сохраняет правильное написание Oʻzbekiston и не разрывает хештег.
     */
    public static function normalizeHashtags(string $hashtags): string
    {
        $safe = [];
        foreach (preg_split('/[\s,]+/u', trim($hashtags), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            $tag = ltrim(trim($tag), '#');
            $tag = UzbekText::normalizeApostrophes($tag);
            $tag = (string) preg_replace('/[^\p{L}\p{N}_]+/u', '', $tag);
            if ($tag !== '') {
                $safe[] = $tag;
            }
        }

        return \App\Models\News::cleanHashtags(implode(' ', $safe)) ?? '';
    }

    /** Видимая длина текста без тегов — по ней Telegram считает лимит. */
    public static function textLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public static function fits(string $html): bool
    {
        return self::textLength($html) <= self::TEXT_LIMIT;
    }
}
