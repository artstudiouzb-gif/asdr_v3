<?php

declare(strict_types=1);

namespace App\Core\BlockData;

/**
 * Нормализатор блока «Отзывы»: собирает только репитер, скалярные поля описаны
 * схемой (`BlockFieldSchema`).
 */
final class TestimonialsBlockNormalizer
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

            $quote = trim((string) ($item['quote'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($quote === '' && $name === '') {
                continue;
            }

            $items[] = [
                'quote' => BlockDataInput::plain($item, 'quote', $locale),
                'name' => BlockDataInput::plain($item, 'name', $locale),
                'role' => BlockDataInput::plain($item, 'role', $locale),
                'company' => BlockDataInput::plain($item, 'company', $locale),
                'photo' => BlockDataInput::safeLink($item['photo'] ?? ''),
                // 0 — оценка не указана и звёзды не выводятся вовсе.
                'rating' => BlockDataInput::int($item, 'rating', 0, 5, 0),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('testimonials', $input, $locale),
            ['items' => $items]
        );
    }
}
