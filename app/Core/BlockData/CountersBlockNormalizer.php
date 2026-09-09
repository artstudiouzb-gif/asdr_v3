<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;

final class CountersBlockNormalizer
{
    /**
     * Пробел-разделитель разрядов делаем неразрывным.
     *
     * «1 200» — одно число, и переносить строку внутри него нельзя. Запрещать
     * перенос всей ячейке (white-space: nowrap) — плохой размен: тогда длинное
     * значение вылезает за край карточки вместо того, чтобы перенестись после
     * приставки. Неразрывный пробел решает ровно ту задачу, которая есть.
     */
    private static function groupedNumber(string $value): string
    {
        return preg_match('/^\d[\d\s]*\d$/u', $value) === 1
            ? (string) preg_replace('/\s+/u', "\u{00A0}", $value)
            : $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = BlockDataInput::trimmed($item, 'value');
            $label = BlockDataInput::trimmed($item, 'label');
            if ($value === '' && $label === '') {
                continue;
            }

            $iconSvg = Icon::cleanName($item['icon_svg'] ?? '');

            // Значение — строка, а не целое. Целым нельзя записать «1 200»,
            // «24/7», «№1» и «4,5», а показатель бывает именно таким. Анимацию
            // это не отменяет: шаблон включает её, когда в значении одни цифры.
            $items[] = [
                'value' => self::groupedNumber(mb_substr($value, 0, 24)),
                'prefix' => mb_substr(BlockDataInput::trimmed($item, 'prefix'), 0, 12),
                'suffix' => BlockDataInput::trimmed($item, 'suffix'),
                'label' => BlockDataInput::plain($item, 'label', $locale),
                'note' => mb_substr(BlockDataInput::plain($item, 'note', $locale), 0, 120),
                'link' => BlockDataInput::safeLink($item['link'] ?? ''),
                'icon_svg' => $iconSvg,
                'icon_image' => BlockDataInput::safeMedia($item['icon_image'] ?? ''),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('counters', $input, $locale),
            ['items' => $items]
        );
    }
}
