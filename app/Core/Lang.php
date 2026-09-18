<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\InterfaceTranslation;
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
     * Итоговый словарь: файлы релиза + значения, изменённые редактором.
     *
     * @return array<string,string>
     */
    private static function table(string $lang): array
    {
        $lang = self::cleanLang($lang);
        if ($lang === '') {
            return [];
        }
        if (!array_key_exists($lang, self::$dict)) {
            self::$dict[$lang] = array_replace(
                self::baseTable($lang),
                InterfaceTranslation::forLanguage($lang)
            );
        }

        return self::$dict[$lang];
    }

    /**
     * Базовый словарь из репозитория. Публичен для редактора переводов:
     * админка показывает штатное значение рядом с переопределением.
     *
     * @return array<string,string>
     */
    public static function baseTable(string $lang): array
    {
        $lang = self::cleanLang($lang);
        if ($lang === '') {
            return [];
        }
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $data = is_file($file) ? require $file : [];

        return is_array($data) ? array_filter(
            $data,
            static fn ($value, $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH
        ) : [];
    }

    /**
     * Все системные ключи для таблицы админки. Помимо словарей читаем
     * строковые литералы t('...') из публичного кода: новая подпись после
     * обновления сразу появляется как «не переведено», даже если разработчик
     * ещё не добавил её в lang-файл.
     *
     * @return list<string>
     */
    public static function sourceKeys(): array
    {
        $keys = [];
        foreach (Language::activeCodes() as $code) {
            foreach (array_keys(self::baseTable($code)) as $key) {
                $keys[(string) $key] = true;
            }
        }

        foreach (['app/Views/site', 'app/Views/errors', 'app/Controllers/Site', 'app/Core', 'templates'] as $dir) {
            $root = APP_ROOT . '/' . $dir;
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.php') || str_contains($path, '/lang/')) {
                    continue;
                }
                $tokens = token_get_all((string) file_get_contents($path));
                $count = count($tokens);
                for ($i = 0; $i < $count - 2; $i++) {
                    $token = $tokens[$i];
                    if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 't') {
                        continue;
                    }
                    $j = $i + 1;
                    while ($j < $count && is_array($tokens[$j])
                        && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $j++;
                    }
                    if (($tokens[$j] ?? null) !== '(') {
                        continue;
                    }
                    $j++;
                    while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    $arg = $tokens[$j] ?? null;
                    if (!is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }
                    $key = stripcslashes(substr($arg[1], 1, -1));
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
            }
        }

        $result = array_keys($keys);
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    public static function flush(): void
    {
        self::$dict = [];
    }

    private static function cleanLang(string $lang): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($lang)) ?? '';
    }
}
