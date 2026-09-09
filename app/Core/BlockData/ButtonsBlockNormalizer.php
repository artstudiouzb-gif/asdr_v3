<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;

/**
 * Нормализатор блока «Кнопки»: собирает только репитер, скалярные поля описаны
 * схемой.
 *
 * Кнопок не больше трёх. Четвёртая — это уже не «что сделать дальше», а меню:
 * ряд перестаёт читаться, и главное действие теряется среди равных.
 */
final class ButtonsBlockNormalizer
{
    public const MAX_BUTTONS = 3;

    /** @var list<string> */
    public const STYLES = ['primary', 'outline', 'link'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item) || count($items) >= self::MAX_BUTTONS) {
                continue;
            }

            $text = BlockDataInput::plain($item, 'text', $locale);
            $url = BlockDataInput::safeLink($item['url'] ?? '');
            // Кнопка без подписи или без адреса на сайте не появится: первая
            // безымянна, вторая никуда не ведёт.
            if ($text === '' || $url === '') {
                continue;
            }

            $items[] = [
                'text' => mb_substr($text, 0, 60),
                'url' => $url,
                'style' => BlockDataInput::enum($item, 'style', self::STYLES, 'primary'),
                'icon_svg' => Icon::cleanName($item['icon_svg'] ?? ''),
                'new_tab' => !empty($item['new_tab']),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('buttons', $input, $locale),
            ['items' => $items]
        );
    }
}
