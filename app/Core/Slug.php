<?php

declare(strict_types=1);

namespace App\Core;

final class Slug
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'x', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Узбекская кириллица (қ, ғ, ҳ, ў):
        'қ' => 'q',
        'ғ' => 'g',
        'ҳ' => 'h',
        'ў' => 'o',
    ];

    public static function make(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Апострофы являются частью узбекской орфографии, но не должны
        // разрывать слово в URL: O‘zbekiston -> ozbekiston, g‘alaba -> galaba.
        $text = str_replace(["'", '’', '‘', 'ʻ', 'ʼ', '`', '´'], '', $text);
        $transliterated = strtr($text, self::TRANSLIT);
        $ascii = preg_replace('/[^a-z0-9]+/u', '-', $transliterated) ?? '';
        $ascii = trim($ascii, '-');
        $ascii = preg_replace('/-+/', '-', $ascii) ?? '';

        return $ascii !== '' ? $ascii : 'item-' . bin2hex(random_bytes(3));
    }

    /**
     * Создает уникальный slug, проверяя существование коллизий и добавляя суффикс -2, -3 при совпадении.
     *
     * @param callable(string, ?int): bool $existsCheck callback, возвращающий true если slug занят
     */
    public static function unique(string $input, callable $existsCheck, ?int $excludeId = null): string
    {
        $baseSlug = self::make($input);
        $slug = $baseSlug;
        $counter = 2;

        while ($existsCheck($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
