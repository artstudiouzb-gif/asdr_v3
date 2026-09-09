<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Обрабатывает постраничные поля «произвольный CSS/JS».
 *
 * В HTML страницы не попадает ни одной строки инлайн-кода: внешние адреса
 * отдаются как есть, собственный код публикуется отдельными файлами
 * (GeneratedCss/GeneratedJs) и подключается тегом <link>/<script src>.
 * Адреса проходят те же проверки схемы, что и остальные ссылки в конструкторе
 * (UrlGuard): javascript:, data: и прочие «схемы-исполнители» в href/src
 * не попадают.
 */
final class CustomAssetHelper
{
    /** Ограничение поля в форме страницы: колонка pages.custom_* — TEXT (64 КБ). */
    public const MAX_FIELD_BYTES = 60000;

    /**
     * Разбирает поле «CSS страницы» в список внешних адресов стилей.
     *
     * @return list<string>
     */
    public static function resolveCssUrls(?string $input, int $pageId): array
    {
        [$urls, $code] = self::split($input, '.css', '/<link\b[^>]*\bhref=["\']([^"\']+)["\']/i', '/<\/?style\b[^>]*>/i');

        if ($code !== '') {
            $published = GeneratedCss::publish($code, 'page-' . $pageId);
            if ($published !== null) {
                $urls[] = $published;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Разбирает поле «JS страницы» в список внешних адресов скриптов.
     *
     * @return list<string>
     */
    public static function resolveJsUrls(?string $input, int $pageId): array
    {
        [$urls, $code] = self::split($input, '.js', '/<script\b[^>]*\bsrc=["\']([^"\']+)["\']/i', '/<\/?script\b[^>]*>/i');

        if ($code !== '') {
            $published = GeneratedJs::publish($code, 'page-' . $pageId);
            if ($published !== null) {
                $urls[] = $published;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Origin'ы внешних адресов для CSP. Свои файлы (пути с «/») отдаются с
     * того же домена и в директиве не нуждаются.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public static function originsOf(array $urls): array
    {
        $origins = [];
        foreach ($urls as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            $port = parse_url($url, PHP_URL_PORT);
            $origins[] = $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
        }

        return array_values(array_unique($origins));
    }

    /**
     * Делит ввод на внешние адреса и собственный код. Строка считается
     * адресом, только если она проходит проверку схемы: иначе «ссылка» вроде
     * javascript:… ушла бы в href/src готовой страницы.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function split(?string $input, string $extension, string $tagPattern, string $stripPattern): array
    {
        if ($input === null || trim($input) === '') {
            return [[], ''];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($input)) ?: [];
        $urls = [];
        $codeLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Готовый тег <link href="..."> / <script src="...">.
            if (preg_match($tagPattern, $trimmed, $m)) {
                $url = self::safeAssetUrl($m[1]);
                if ($url !== null) {
                    $urls[] = $url;
                }
                continue;
            }

            // Отдельно стоящий адрес: https://, http://, // или путь с нужным
            // расширением. Строки с синтаксисом кода адресом не считаются.
            if (self::looksLikeUrl($trimmed, $extension)) {
                $url = self::safeAssetUrl($trimmed);
                if ($url !== null) {
                    $urls[] = $url;
                    continue;
                }
            }

            $cleanLine = preg_replace($stripPattern, '', $trimmed);
            if (trim((string) $cleanLine) !== '') {
                $codeLines[] = (string) $cleanLine;
            }
        }

        return [$urls, implode("\n", $codeLines)];
    }

    /**
     * Нормализует и проверяет адрес ассета. Протокол-относительный «//host/x»
     * приводится к https: на HTTPS-сайте другой вариант всё равно не грузится.
     */
    private static function safeAssetUrl(string $url): ?string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        return UrlGuard::isSafeMedia($url) ? $url : null;
    }

    private static function looksLikeUrl(string $text, string $extension): bool
    {
        // Скобки и знак равенства — синтаксис кода, а не адрес: строка вида
        // «var u = a.js» должна остаться кодом.
        if (preg_match('/[<{;=()]/', $text) === 1 || preg_match('/\s/u', $text) === 1) {
            return false;
        }

        if (preg_match('#^(https?:)?//#i', $text)) {
            return true;
        }

        if (!str_ends_with(strtolower($text), $extension)) {
            return false;
        }

        // Путь от корня сайта — адрес; «.hero.css» и подобное — селектор.
        return str_starts_with($text, '/');
    }
}
