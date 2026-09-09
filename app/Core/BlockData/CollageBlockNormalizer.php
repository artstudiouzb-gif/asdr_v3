<?php

declare(strict_types=1);

namespace App\Core\BlockData;

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
    public const TYPES = ['photo', 'stat', 'badge', 'pattern'];

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
                'badge' => self::badge($item, $normalized, $locale),
                default => self::pattern($item, $normalized),
            };
            if ($filled !== null) {
                $items[] = $filled;
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
            'value' => mb_substr($value, 0, 24),
            'label' => $label,
            'bg' => BlockDataInput::optionalColor($item, 'bg'),
            'fg' => BlockDataInput::optionalColor($item, 'fg'),
            'link' => BlockDataInput::safeLink($item['link'] ?? ''),
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
