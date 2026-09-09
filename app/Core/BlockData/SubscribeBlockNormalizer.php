<?php

declare(strict_types=1);

namespace App\Core\BlockData;

final class SubscribeBlockNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $data = BlockFieldSchema::normalize('subscribe', $input, $locale);
        // Вариант «на фоне» без картинки читался бы белым по белому: без
        // изображения он равнозначен обычной полосе. Это зависимость одного
        // поля от другого, поэтому она здесь, а не в схеме.
        if ($data['variant'] === 'image' && $data['image'] === '') {
            $data['variant'] = 'band';
        }

        return $data;
    }
}
