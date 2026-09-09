<?php

declare(strict_types=1);

namespace App\Core;

/** Нормализация, RU/UZ-транслитерация и ранжирование поискового запроса. */
final class SearchQuery
{
    /** @return list<list<string>> варианты каждого логического слова */
    public static function groups(string $query): array
    {
        $normalized = self::normalize($query);
        $words = array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $word): bool => mb_strlen(str_replace("'", '', $word)) >= 2
        ));
        if ($words === [] && $normalized !== '') {
            $words = [$normalized];
        }

        return array_map([self::class, 'variants'], array_slice($words, 0, 6));
    }

    /** @return list<string> */
    public static function variants(string $word): array
    {
        $word = self::normalize($word);
        $variants = [$word];
        if (preg_match('/\p{Cyrillic}/u', $word) === 1) {
            $variants[] = self::cyrillicToLatin($word);
        } else {
            $variants[] = self::latinToCyrillic($word);
        }
        foreach ($variants as $variant) {
            if (str_contains($variant, "'")) {
                $variants[] = str_replace("'", '', $variant);
            }
        }

        return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== '')));
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim(strip_tags($value)));
        $value = str_replace(['ё', '’', '‘', '`', 'ʻ', 'ʼ', 'ʹ', '´'], ['е', "'", "'", "'", "'", "'", "'", "'"], $value);
        $value = preg_replace("/[^\p{L}\p{N}']+/u", ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** Чем выше число, тем релевантнее результат. */
    public static function score(string $query, string $title, string $body = '', string $slug = ''): int
    {
        $needle = self::normalize($query);
        $title = self::normalize($title);
        $body = self::normalize($body);
        $slug = self::normalize($slug);
        $score = $needle !== '' && str_contains($title, $needle) ? 120 : 0;

        foreach (self::groups($query) as $group) {
            $titleHit = $bodyHit = $slugHit = false;
            foreach ($group as $variant) {
                $titleHit = $titleHit || str_contains($title, $variant);
                $bodyHit = $bodyHit || str_contains($body, $variant);
                $slugHit = $slugHit || str_contains($slug, $variant);
            }
            $score += $titleHit ? 35 : 0;
            $score += $slugHit ? 15 : 0;
            $score += $bodyHit ? 6 : 0;
        }

        return $score;
    }

    private static function latinToCyrillic(string $value): string
    {
        // Один источник правил для видимого текста и поиска. normalize()
        // сводит «ё» к «е», как и индексируемый текст поисковой выдачи.
        return self::normalize(UzCyrillic::text($value));
    }

    private static function cyrillicToLatin(string $value): string
    {
        return strtr($value, [
            'ў' => "o'", 'ғ' => "g'", 'қ' => 'q', 'ҳ' => 'h', 'ш' => 'sh',
            'ч' => 'ch', 'ж' => 'j', 'ю' => 'yu', 'я' => 'ya', 'ц' => 'ts',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
            'х' => 'x', 'ъ' => '', 'ь' => '', 'ы' => 'i', 'э' => 'e',
        ]);
    }
}
