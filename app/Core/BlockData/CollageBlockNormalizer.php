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
    public const TYPES = ['photo', 'stat', 'quote', 'badge', 'pattern'];

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
                'stat' => self::stat($item, $normalized, $locale),
                'quote' => self::quote($item, $normalized, $locale),
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
     * @param array<string, mixed> $item
     * @param array<string, mixed> $base
     * @return array<string, mixed>|null
     */
    private static function badge(array $item, array $base, string $locale): ?array
    {
        $text = BlockDataInput::plain($item, 'text', $locale);
        $icon = Icon::cleanName($item['icon_svg'] ?? '');
        if ($text === '' && $icon === '') {
            return null;
        }

        return $base + [
            // Надпись идёт по кругу и повторяется дважды, поэтому длинная
            // строка сливается сама с собой — предел жёсткий.
            'text' => mb_substr($text, 0, 40),
            'icon_svg' => $icon,
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
        return $base + [
            'pattern' => BlockDataInput::enum($item, 'pattern', self::PATTERNS, 'dots'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
        ];
    }
}
