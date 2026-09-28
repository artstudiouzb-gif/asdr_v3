<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\CollageLayout;
use App\Core\Icon;

/**
 * Нормализатор блока «Коллаж».
 *
 * Элементы разнотипны: набор полей зависит от значения соседнего поля «Тип
 * элемента», и схема такую связь не выражает — поэтому репитер собирается
 * здесь, а полотно (колонки, строки, пропорция, промежуток) остаётся в схеме.
 *
 * Размещение хранится номерами ячеек, а не координатами. Свободные X/Y на
 * адаптивном сайте нечем сложить в столбец: композиция, красивая на 1440px,
 * на телефоне наезжает сама на себя, и проверить её нечем. Номер ячейки же
 * проверяется арифметикой и на узком экране просто отменяется.
 */
final class CollageBlockNormalizer
{
    /** @var list<string> */
    public const TYPES = ['photo', 'video', 'stat', 'quote', 'info', 'badge', 'pattern'];

    /**
     * Файлы ролика из медиатеки. Остальное — ссылка на YouTube: другие
     * источники браузер в лайтбоксе не проиграет, а произвольный iframe —
     * чужой код на странице (см. EmbedSource).
     */
    public const VIDEO_FILES = ['mp4', 'webm', 'm4v'];

    /** Строк у справки: больше не помещается ни в вырез, ни в ячейку. */
    public const INFO_MAX_ROWS = 6;

    /** @var list<string> */
    public const SHAPES = ['rounded', 'circle', 'square'];

    /**
     * Кадрирование кадра в ячейке. «auto» отдаёт решение медиатеке: у снимка
     * там уже может быть своя точка фокуса, и подменять её молча нельзя —
     * настройка блока обязана либо не вмешиваться, либо вмешиваться явно.
     *
     * @var list<string>
     */
    public const FOCUS = ['auto', 'center', 'top', 'bottom', 'left', 'right'];

    /**
     * Узоры берутся из общего списка фонов секции: свой набор здесь молча
     * разъехался бы с тем при первой правке.
     *
     * @var list<string>
     */
    public const PATTERNS = \App\Core\BlockBackground::PATTERNS;

    /**
     * Свой узор — картинка-плитка из медиатеки. Отдельным значением, а не
     * пунктом общего набора: фон секции для этого давно умеет «плитку» своим
     * режимом, и второй способ того же в наборе узоров был бы дублем.
     */
    public const CUSTOM_PATTERN = 'image';

    /** Шаг узора: у встроенного это размер клетки, у своего — ширина плитки. */
    public const PATTERN_SIZES = ['small' => 18, 'medium' => 28, 'large' => 48];

    /**
     * Что стоит в центре печати: эмблема сайта (из «Дизайна»), значок из
     * набора, своя картинка (логотип) или ничего — одна надпись по кругу.
     *
     * @var list<string>
     */
    public const BADGE_CENTERS = ['emblem', 'icon', 'image', 'none'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $canvas = BlockFieldSchema::normalize('collage', $input, $locale);
        $layout = (string) $canvas['layout'];
        $columns = (int) $canvas['columns'];
        $rows = (int) $canvas['rows'];

        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = BlockDataInput::enum($item, 'type', self::TYPES, 'photo');
            $normalized = self::place($item, $columns, $rows) + [
                'type' => $type,
                'shape' => BlockDataInput::enum($item, 'shape', self::SHAPES, 'rounded'),
            ];

            // Пустой элемент занимал бы ячейки и ничем их не заполнял: дыра в
            // композиции читается как поломка вёрстки, а не как замысел.
            $filled = match ($type) {
                'photo' => self::photo($item, $normalized),
                'video' => self::video($item, $normalized, $locale),
                'stat' => self::stat($item, $normalized, $locale),
                'quote' => self::quote($item, $normalized, $locale),
                'info' => self::info($item, $normalized, $locale),
                'badge' => self::badge($item, $normalized, $locale),
                default => self::pattern($item, $normalized),
            };
            if ($filled !== null) {
                $items[] = $filled;
            }
        }

        // У готовой сборки места считаются по числу элементов — и считаются
        // ПОСЛЕ отбора: пустой элемент из композиции выпадает, и раскладка,
        // посчитанная до него, оставила бы в полотне дыру ровно там, где он
        // был. Вместе с местами приходит и сетка: сколько колонок и строк
        // нужно этой композиции, знает сама раскладка, а не редактор.
        // Композиция по ролям ячеек не знает вовсе: места у неё задаёт
        // раскладка в CSS, а роль выводится из типа элемента при выводе.
        if (CollageLayout::isPreset($layout)) {
            $placed = CollageLayout::place($layout, count($items));
            $canvas['columns'] = $placed['columns'];
            $canvas['rows'] = $placed['rows'];
            foreach ($placed['cells'] as $i => $cell) {
                $items[$i] = $cell + $items[$i];
            }
        }

        return array_merge($canvas, ['items' => $items]);
    }

    /**
     * Ячейка и размер. Элемент, выходящий за правый или нижний край, обрезается
     * до края, а не переносится: перенос сдвинул бы соседей и развалил бы всю
     * композицию ради одного элемента.
     *
     * @param array<string, mixed> $item
     * @return array{col: int, col_span: int, row: int, row_span: int}
     */
    private static function place(array $item, int $columns, int $rows): array
    {
        $col = BlockDataInput::int($item, 'col', 1, $columns, 1);
        $row = BlockDataInput::int($item, 'row', 1, $rows, 1);

        return [
            'col' => $col,
            'col_span' => min(BlockDataInput::int($item, 'col_span', 1, $columns, 1), $columns - $col + 1),
            'row' => $row,
            'row_span' => min(BlockDataInput::int($item, 'row_span', 1, $rows, 1), $rows - $row + 1),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function photo(array $item, array $base): ?array
    {
        $image = BlockDataInput::safeMedia($item['image'] ?? '');
        if ($image === '') {
            return null;
        }

        return $base + [
            'image' => $image,
            'alt' => BlockDataInput::trimmed($item, 'alt'),
            'focus' => BlockDataInput::enum($item, 'focus', self::FOCUS, 'auto'),
            'link' => BlockDataInput::safeLink($item['link'] ?? ''),
        ];
    }

    /**
     * Ролик: ссылка на YouTube или файл из медиатеки, кадр-обложка и подпись.
     *
     * Ролик не встраивается в страницу, а открывается по нажатию в общем
     * лайтбоксе: iframe в каждой плитке коллажа — это запросы к YouTube на
     * загрузке страницы и плеер со своими кнопками поверх композиции. Плитка
     * остаётся кадром с кнопкой, а без скрипта — ссылкой на сам ролик.
     *
     * Ссылка YouTube приводится к одному виду (`watch?v=`): Shorts, youtu.be и
     * embed — один и тот же ролик, и лайтбокс узнаёт его по id.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function video(array $item, array $base, string $locale): ?array
    {
        $source = BlockDataInput::trimmed($item, 'video');
        $youtube = \App\Core\Video::youtubeId($source);
        if ($youtube !== null) {
            $video = 'https://www.youtube.com/watch?v=' . $youtube;
        } else {
            $video = BlockDataInput::safeMedia($source);
            $extension = strtolower(pathinfo((string) parse_url($video, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($extension, self::VIDEO_FILES, true)) {
                $video = '';
            }
        }
        // Плитка без ролика — это кадр без смысла: кнопка «смотреть» вела бы
        // в никуда.
        if ($video === '') {
            return null;
        }

        return $base + [
            'video' => $video,
            'poster' => BlockDataInput::safeMedia($item['poster'] ?? ''),
            'video_title' => mb_substr(BlockDataInput::plain($item, 'video_title', $locale), 0, 80),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function stat(array $item, array $base, string $locale): ?array
    {
        $value = BlockDataInput::trimmed($item, 'value');
        $label = BlockDataInput::plain($item, 'label', $locale);
        if ($value === '' && $label === '') {
            return null;
        }

        return $base + [
            'icon_svg' => Icon::cleanName($item['icon_svg'] ?? ''),
            // Приставка отделена от значения по той же причине, что в блоке
            // «Показатели»: «более» перед числом — это слово, а не часть
            // числа, и набранное одной строкой оно ломает отсчёт при
            // появлении и перенос длинного значения.
            'prefix' => mb_substr(BlockDataInput::plain($item, 'prefix', $locale), 0, 16),
            'value' => mb_substr($value, 0, 24),
            'label' => $label,
            'bg' => BlockDataInput::optionalColor($item, 'bg'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
            'link' => BlockDataInput::safeLink($item['link'] ?? ''),
        ];
    }

    /**
     * Цитата в композиции: короткая прямая речь с подписью.
     *
     * Своё оформление знака кавычки здесь не заводится, в отличие от варианта
     * «Акцентная цитата» блока «Текст»: там цитата стоит рядом с колонкой
     * текста и держит на себе весь блок, а тут она — один элемент среди
     * четырёх, и третий набор настроек цвета и кегля спорил бы с соседями.
     * Цвета берутся общие для элемента, как у плитки с числом.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function quote(array $item, array $base, string $locale): ?array
    {
        // Ключ свой, а не общий `text`: его занимает надпись круглой печати, и
        // в форме два поля с одним именем затирали бы друг друга — при
        // отправке побеждало бы последнее.
        $text = BlockDataInput::plain($item, 'quote_text', $locale);
        if ($text === '') {
            // Подпись без самой цитаты — это имя в пустой ячейке: элемент без
            // содержимого занимал бы место в композиции и ничем его не
            // заполнял.
            return null;
        }

        return $base + [
            // Предел длины — не вкус: в ячейке коллажа цитата стоит рядом с
            // фотографией, и абзац в ней набирается кеглем подписи, то есть
            // читается хуже, чем тот же текст блоком «Текст».
            'quote_text' => mb_substr($text, 0, 220),
            'author' => mb_substr(BlockDataInput::plain($item, 'author', $locale), 0, 60),
            'role' => mb_substr(BlockDataInput::plain($item, 'role', $locale), 0, 80),
            'bg' => BlockDataInput::optionalColor($item, 'bg'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
        ];
    }

    /**
     * Справка: заголовок и строки «Подпись | Значение» — часы приёма,
     * телефон, ближайший срок. Строки набираются построчно, как у «Таблицы»:
     * репитер из пар полей ради трёх строк был бы тяжелее самой справки, а
     * так её можно вставить из документа и править как текст.
     *
     * Хранится очищенным текстом, а не массивом пар: форма показывает его
     * обратно в том же поле, и разбирать его на выводе дешевле, чем держать
     * второе представление тех же данных.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function info(array $item, array $base, string $locale): ?array
    {
        $title = mb_substr(BlockDataInput::plain($item, 'info_title', $locale), 0, 60);
        $lines = [];
        foreach (self::infoRows(BlockDataInput::trimmed($item, 'info_rows')) as [$label, $value]) {
            $label = \App\Core\TextProcessor::typographPlain($label, $locale);
            $value = \App\Core\TextProcessor::typographPlain($value, $locale);
            $lines[] = $value === '' ? $label : $label . ' | ' . $value;
        }
        if ($title === '' && $lines === []) {
            return null;
        }

        return $base + [
            'info_title' => $title,
            'info_rows' => implode("\n", $lines),
            'bg' => BlockDataInput::optionalColor($item, 'bg'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
        ];
    }

    /**
     * Строки справки: «Подпись | Значение», пустые строки пропускаются, лишние
     * сверх предела отбрасываются. Черта после первой — часть значения: в
     * значении бывает «9:00 | 18:00», и резать его молча нельзя.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function infoRows(string $source): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $line = trim(strip_tags($line));
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            $rows[] = [mb_substr(trim($parts[0]), 0, 40), mb_substr(trim($parts[1] ?? ''), 0, 40)];
            if (count($rows) >= self::INFO_MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function badge(array $item, array $base, string $locale): ?array
    {
        $text = BlockDataInput::plain($item, 'text', $locale);
        $icon = Icon::cleanName($item['icon_svg'] ?? '');
        $image = BlockDataInput::safeMedia($item['center_image'] ?? '');
        // Печати, собранные до появления выбора, знали только значок: их
        // центр выводится из того, что в них уже лежит, иначе вид собранных
        // страниц поменялся бы молча.
        $center = array_key_exists('center', $item)
            ? BlockDataInput::enum($item, 'center', self::BADGE_CENTERS, 'emblem')
            : ($icon !== '' ? 'icon' : 'none');
        // Выбранный центр без содержимого — пустой кружок посреди печати.
        if (($center === 'icon' && $icon === '') || ($center === 'image' && $image === '')) {
            $center = 'none';
        }
        if ($text === '' && $center === 'none') {
            return null;
        }

        return $base + [
            // Надпись идёт по кругу и повторяется дважды, поэтому длинная
            // строка сливается сама с собой — предел жёсткий.
            'text' => mb_substr($text, 0, 40),
            'center' => $center,
            'icon_svg' => $icon,
            'center_image' => $image,
            'bg' => BlockDataInput::optionalColor($item, 'bg'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
            'link' => BlockDataInput::safeLink($item['link'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function pattern(array $item, array $base): array
    {
        $pattern = BlockDataInput::enum($item, 'pattern', [...self::PATTERNS, self::CUSTOM_PATTERN], 'dots');
        $image = BlockDataInput::safeMedia($item['pattern_image'] ?? '');
        // «Свой узор» без картинки нарисовать нечем: элемент остаётся, но
        // точками, а не пустым местом.
        if ($pattern === self::CUSTOM_PATTERN && $image === '') {
            $pattern = 'dots';
        }

        return $base + [
            'pattern' => $pattern,
            'pattern_image' => $pattern === self::CUSTOM_PATTERN ? $image : '',
            'pattern_size' => BlockDataInput::enum($item, 'pattern_size', array_keys(self::PATTERN_SIZES), 'medium'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
        ];
    }
}
