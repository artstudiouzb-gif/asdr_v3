<?php

declare(strict_types=1);

namespace App\Core;

/** Единые правила для распространённых вариантов узбекского апострофа. */
final class UzbekText
{
    /** Апострофы, которыми в текстах записывают oʻ, gʻ и tutuq belgisi. */
    public const APOSTROPHES = ['ʻ', 'ʼ', 'ʹ', '′', '‘', '’', '`', "'", '´'];

    /** Приводит все варианты к буквенному апострофу U+02BB. */
    public static function normalizeApostrophes(string $text): string
    {
        return str_replace(self::APOSTROPHES, 'ʻ', $text);
    }
}
