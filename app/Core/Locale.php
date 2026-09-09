<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/**
 * Хранит активную локаль текущего запроса. Устанавливается роутером
 * (из префикса URL) и используется моделями контента и шаблонами сайта.
 */
final class Locale
{
    private static ?string $current = null;
    private static string $path = '/';
    private static ?array $contentLangs = null;

    /** @var array<string,string> */
    private static array $alternatePaths = [];

    public static function set(string $code): void
    {
        self::$current = $code;
    }

    /**
     * Путь текущего запроса БЕЗ языкового префикса (например, '/news/foo').
     * Используется переключателем языков для построения ссылок.
     */
    public static function setPath(string $path): void
    {
        self::$path = $path === '' ? '/' : $path;
    }

    public static function path(): string
    {
        return self::$path;
    }

    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = Language::defaultCode();
        }

        return self::$current;
    }

    public static function isDefault(): bool
    {
        return self::current() === Language::defaultCode();
    }

    /**
     * Префикс для ссылок текущего (или указанного) языка: '' для языка
     * по умолчанию, иначе '/{code}'.
     */
    public static function prefix(?string $code = null): string
    {
        $code = $code ?? self::current();

        return $code === Language::defaultCode() ? '' : '/' . $code;
    }

    /**
     * Языки, на которых реально существует контент текущей сущности
     * (страницы/новости). Устанавливается контроллером; null — маршрут не
     * привязан к сущности, переключатель и hreflang показывают все активные
     * языки. Язык по умолчанию присутствует всегда (fallback-контент).
     *
     * @param string[]|null $codes
     */
    public static function setContentLangs(?array $codes): void
    {
        self::$contentLangs = $codes === null ? null : array_values(array_unique($codes));
    }

    /** @return string[]|null */
    public static function contentLangs(): ?array
    {
        return self::$contentLangs;
    }

    /**
     * Собственные пути языковых версий записи (код → путь без префикса).
     * У связанных переводов свой slug, и без этой карты hreflang и
     * переключатель вели на чужой адрес, который отвечал редиректом:
     * поисковику это лишний шаг, читателю — лишний запрос.
     *
     * @param array<string,string> $paths
     */
    public static function setAlternatePaths(array $paths): void
    {
        $clean = [];
        foreach ($paths as $code => $path) {
            $code = trim((string) $code);
            $path = trim((string) $path);
            if ($code !== '' && $path !== '') {
                $clean[$code] = $path;
            }
        }
        self::$alternatePaths = $clean;
    }

    /** Путь языковой версии; при отсутствии — путь текущего запроса. */
    public static function alternatePath(string $code): string
    {
        return self::$alternatePaths[$code] ?? self::path();
    }

    /**
     * Строит URL сайта с учётом языкового префикса.
     */
    public static function url(string $path = '', ?string $code = null): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            $path = '';
        }

        $url = self::prefix($code) . $path;

        return $url === '' ? '/' : $url;
    }
}
