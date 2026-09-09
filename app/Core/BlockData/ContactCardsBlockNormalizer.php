<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;

final class ContactCardsBlockNormalizer
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
            $lines = trim((string) ($item['lines'] ?? ''));
            if ($itemTitle === '' && $lines === '') {
                continue;
            }

            $iconSvg = Icon::cleanName($item['icon_svg'] ?? '');

            $items[] = [
                'icon_svg' => $iconSvg,
                // Своя картинка (SVG или PNG из медиабиблиотеки) важнее ключа
                // Tabler — та же логика, что у кнопок обложки.
                'icon_image' => BlockDataInput::safeMedia($item['icon_image'] ?? ''),
                'title' => BlockDataInput::plain($item, 'title', $locale),
                'lines' => $lines,
                'link_url' => BlockDataInput::safeLink($item['link_url'] ?? ''),
                'link_text' => trim((string) ($item['link_text'] ?? '')),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('contact_cards', $input, $locale),
            ['items' => $items]
        );
    }
}
