<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/**
 * Лёгкий словарь переводов интерфейса сайта (не контент — контент хранится
 * в БД по языкам). Ключом служит исходная строка на языке по умолчанию (RU):
 * так шаблоны остаются читаемыми, а переводить нужно только неосн's языки.
 *
 * t('Читать далее') -> RU: «Читать далее», UZ: значение из lang/uz.php,
 * либо сам ключ, если перевода нет.
 */
final class Lang
{
    /** @var array<string, array<string,string>> кэш словарей по коду языка */
    private static array $dict = [];

    public static function t(string $key, ?string $lang = null): string
    {
        $lang = $lang ?? Locale::current();

        // Язык по умолчанию — исходный (RU): ключ и есть перевод.
        if ($lang === 'ru') {
            return $key;
        }

        $table = self::table($lang);
        if (isset($table[$key]) && $table[$key] !== '') {
            return $table[$key];
        }

        return $key;
    }

    /**
     * @return array<string,string>
     */
    private static function table(string $lang): array
    {
        $lang = preg_replace('/[^a-z]/', '', strtolower($lang)) ?? '';
        if ($lang === '') {
            return [];
        }
        if (!array_key_exists($lang, self::$dict)) {
            $file = __DIR__ . '/lang/' . $lang . '.php';
            $data = is_file($file) ? require $file : [];
            self::$dict[$lang] = is_array($data) ? $data : [];
        }

        return self::$dict[$lang];
    }
}
