<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Проверка файла фирменной эмблемы. Эмблема работает CSS-маской
 * (`--gov-emblem`), а маска берёт от файла только форму, поэтому «сохранилось»
 * и «видно на сайте» — разные вещи: SVG без viewBox нечем масштабировать, а
 * файл, не разобравшийся при загрузке, лежит на диске пустой заглушкой. Раньше
 * оба случая выглядели одинаково — знак просто пропадал, без единого слова.
 */
final class Emblem
{
    /** Путь к файлу эмблемы на диске или null, если адрес не наш. */
    public static function pathFor(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, '/') || !UrlGuard::isSafeMedia($url)) {
            return null;
        }
        $url = (string) parse_url($url, PHP_URL_PATH);
        if ($url === '' || str_contains($url, '..')) {
            return null;
        }

        $uploadsUrl = rtrim((string) Config::get('paths.public_uploads_url'), '/');
        if ($uploadsUrl !== '' && str_starts_with($url, $uploadsUrl . '/')) {
            $base = rtrim((string) Config::get('paths.public_uploads'), '/');

            return $base . '/' . basename($url);
        }

        return dirname(__DIR__, 2) . '/public' . $url;
    }

    /**
     * Годится ли файл как трафарет. Пустой адрес — это встроенная эмблема,
     * то есть «всё в порядке».
     *
     * @return array{ok:bool,error:string}
     */
    public static function check(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => true, 'error' => ''];
        }
        if (!str_starts_with($url, '/') || !UrlGuard::isSafeMedia($url)) {
            return ['ok' => false, 'error' => 'Эмблема принимается только файлом этого сайта: загрузите SVG в медиабиблиотеку.'];
        }

        $path = self::pathFor($url);
        if ($path === null || !is_file($path)) {
            return ['ok' => false, 'error' => 'Файл эмблемы не найден на сервере: ' . $url];
        }
        if ((int) filesize($path) < 1) {
            return ['ok' => false, 'error' => 'Файл эмблемы пустой — загрузите его заново.'];
        }
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'svg') {
            return ['ok' => true, 'error' => ''];
        }

        return self::checkSvg((string) file_get_contents($path));
    }

    /** @return array{ok:bool,error:string} */
    public static function checkSvg(string $svg): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $dom->documentElement : null;
        if ($root === null || strtolower($root->localName) !== 'svg') {
            return ['ok' => false, 'error' => 'Файл не разобрался как SVG. Пересохраните его из редактора («Сохранить как → Обычный SVG») и загрузите снова.'];
        }

        $viewBox = trim($root->getAttribute('viewBox'));
        $width = self::length($root->getAttribute('width'));
        $height = self::length($root->getAttribute('height'));

        // Заглушка, которой Uploader заменяет неразобранный файл.
        if ($viewBox === '' && $width === 1.0 && $height === 1.0 && !$root->hasChildNodes()) {
            return ['ok' => false, 'error' => 'Загруженный SVG не удалось разобрать, и на сервер попал пустой файл. Пересохраните эмблему из редактора и загрузите заново.'];
        }
        if ($viewBox === '' && ($width === null || $height === null || $width <= 0.0 || $height <= 0.0)) {
            return ['ok' => false, 'error' => 'У SVG нет ни viewBox, ни размеров — как трафарет (CSS-маска) он не отрисуется. Добавьте viewBox при экспорте и загрузите файл снова.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Адрес для CSS `url("…")`. Кавычки, скобки и пробелы кодируются: из-за
     * них знак у файла с «неудобным» именем либо пропадал, либо мог разорвать
     * объявление.
     */
    public static function cssUrl(string $url): string
    {
        return str_replace(
            [' ', '"', "'", '(', ')', '\\'],
            ['%20', '%22', '%27', '%28', '%29', '%5C'],
            trim($url)
        );
    }

    /** Число из атрибута длины SVG: «24», «24px», «24.5pt». Проценты не в счёт. */
    public static function length(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(-?\d+(?:\.\d+)?)\s*(px|pt|pc|mm|cm|in)?$/i', $value, $m)) {
            return null;
        }

        return (float) $m[1];
    }
}
