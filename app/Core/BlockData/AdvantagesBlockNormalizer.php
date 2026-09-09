<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;

/**
 * Нормализатор блока «Преимущества»: собирает только репитер. Скалярные поля
 * описаны схемой (`BlockFieldSchema`) — второй их список здесь и был тем
 * дублем, из-за которого значения расходились с формой.
 */
final class AdvantagesBlockNormalizer
{
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

            $itemTitle = trim((string) ($item['title'] ?? ''));
            $itemText = trim((string) ($item['text'] ?? ''));
            if ($itemTitle === '' && $itemText === '') {
                continue;
            }

            $iconSvg = Icon::cleanName($item['icon_svg'] ?? '');

            $items[] = [
                'icon_svg' => $iconSvg,
                'title' => BlockDataInput::plain($item, 'title', $locale),
                'text' => BlockDataInput::plain($item, 'text', $locale),
                'url' => BlockDataInput::safeLink($item['url'] ?? ''),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('advantages', $input, $locale),
            ['items' => $items]
        );
    }
}
