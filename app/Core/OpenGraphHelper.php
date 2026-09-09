<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

final class OpenGraphHelper
{
    /** @var list<string> */
    private static array $additionalImages = [];

    /**
     * Добавляет дополнительные OG-изображения для текущей страницы.
     * Первый валидный URL остаётся приоритетным, остальные публикуются как
     * повторные og:image — по спецификации Open Graph это массив значений.
     *
     * @param list<string> $images
     */
    public static function setAdditionalImages(array $images): void
    {
        $normalized = [];
        foreach ($images as $image) {
            $image = trim((string) $image);
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        self::$additionalImages = $normalized;
    }

    /**
     * Генерирует Open Graph и Twitter Card метаданные.
     *
     * Локальные изображения выводятся только после проверки физического
     * файла в public/. Внешние HTTPS URL не запрашиваются во время рендера:
     * такая проверка замедляла бы каждый публичный ответ и создавала SSRF-риск.
     *
     * @param list<string> $alternateLangs языки, на которых страница есть
     */
    public static function render(
        string $appUrl,
        string $title,
        string $description,
        string $canonicalUrl,
        string $currentLang = 'ru',
        string $image = '',
        string $type = 'website',
        ?string $publishedTime = null,
        ?string $modifiedTime = null,
        array $alternateLangs = []
    ): string {
        $siteName = Setting::getLocalized(
            'site_name',
            Locale::current(),
            'Государственный портал Республики Узбекистан'
        );

        // Дополнительные изображения живут только в рамках одного рендера.
        // Это важно для long-running PHP-процессов и тестов, где static-состояние
        // иначе могло бы попасть на следующую страницу.
        $additionalImages = self::$additionalImages;
        self::$additionalImages = [];

        $resolvedImages = self::resolveImages($appUrl, [
            $image,
            ...$additionalImages,
        ]);

        // Дефолтные изображения — только fallback. Если у страницы уже есть
        // собственная обложка/галерея, логотип и общий OG в массив не подмешиваем.
        if ($resolvedImages === []) {
            $resolvedImages = self::resolveImages($appUrl, [
                Setting::get('default_og_image', ''),
                Setting::get('og_default_image', ''),
                Setting::get('logo_url', ''),
            ]);
        }

        $resolvedImage = $resolvedImages[0] ?? null;

        $locales = [
            'ru' => 'ru_RU',
            'uz' => 'uz_UZ',
            'en' => 'en_US',
        ];
        $locale = $locales[$currentLang] ?? 'ru_RU';

        $html = "\n<!-- Rich Open Graph & Social Cards -->\n";
        $html .= self::meta('property', 'og:site_name', $siteName);
        $html .= self::meta('property', 'og:locale', $locale);
        // Языковые версии страницы объявляются и здесь: hreflang читает поиск,
        // og:locale:alternate — соцсети и мессенджеры, которые собирают
        // карточку ссылки. Список приходит уже отфильтрованным по наличию
        // перевода, поэтому карточка не обещает страницу, которой нет.
        foreach ($alternateLangs as $alternateLang) {
            $alternateLang = (string) $alternateLang;
            if ($alternateLang === $currentLang || !isset($locales[$alternateLang])) {
                continue;
            }
            $html .= self::meta('property', 'og:locale:alternate', $locales[$alternateLang]);
        }
        $html .= self::meta('property', 'og:type', $type);
        $html .= self::meta('property', 'og:title', $title);

        if ($description !== '') {
            $html .= self::meta('property', 'og:description', $description);
        }

        $html .= self::meta('property', 'og:url', $canonicalUrl);

        foreach ($resolvedImages as $resolvedOgImage) {
            $html .= self::meta('property', 'og:image', $resolvedOgImage['url']);
            if (str_starts_with($resolvedOgImage['url'], 'https://')) {
                $html .= self::meta('property', 'og:image:secure_url', $resolvedOgImage['url']);
            }
            if ($resolvedOgImage['width'] !== null && $resolvedOgImage['height'] !== null) {
                $html .= self::meta('property', 'og:image:width', (string) $resolvedOgImage['width']);
                $html .= self::meta('property', 'og:image:height', (string) $resolvedOgImage['height']);
            }
            $html .= self::meta('property', 'og:image:alt', $title);
        }

        if ($type === 'article') {
            // ISO-8601 в ташкентском времени: date(strtotime()) считала бы в
            // таймзоне сервера, а неразобранная дата роняла бы страницу
            // (см. DateFormatter).
            $published = DateFormatter::format((string) $publishedTime, 'c');
            if ($published !== '') {
                $html .= self::meta('property', 'article:published_time', $published);
            }
            $modified = DateFormatter::format((string) $modifiedTime, 'c');
            if ($modified !== '' && $modified !== $published) {
                $html .= self::meta('property', 'article:modified_time', $modified);
            }
        }

        $html .= self::meta(
            'name',
            'twitter:card',
            $resolvedImage !== null ? 'summary_large_image' : 'summary'
        );
        $html .= self::meta('name', 'twitter:title', $title);
        if ($description !== '') {
            $html .= self::meta('name', 'twitter:description', $description);
        }
        if ($resolvedImage !== null) {
            $html .= self::meta('name', 'twitter:image', $resolvedImage['url']);
        }

        return $html;
    }

    private static function meta(string $attribute, string $name, string $content): string
    {
        return sprintf(
            '<meta %s="%s" content="%s">' . "\n",
            $attribute,
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($content, ENT_QUOTES)
        );
    }

    /**
     * @param list<string> $candidates
     * @return list<array{url:string,width:int|null,height:int|null}>
     */
    private static function resolveImages(string $appUrl, array $candidates): array
    {
        $resolvedImages = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $resolved = self::resolveImage($appUrl, trim((string) $candidate));
            if ($resolved === null || isset($seen[$resolved['url']])) {
                continue;
            }
            $seen[$resolved['url']] = true;
            $resolvedImages[] = $resolved;
        }

        return $resolvedImages;
    }

    /** @return array{url:string,width:int|null,height:int|null}|null */
    private static function resolveImage(string $appUrl, string $candidate): ?array
    {
        if ($candidate === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $candidateParts = parse_url($candidate);
            $appParts = parse_url($appUrl);
            if (!is_array($candidateParts) || !is_array($appParts)) {
                return null;
            }

            $candidateHost = strtolower((string) ($candidateParts['host'] ?? ''));
            $appHost = strtolower((string) ($appParts['host'] ?? ''));
            if ($candidateHost !== $appHost) {
                // Внешний URL считается явной настройкой редактора. Не делаем
                // сетевой HEAD-запрос при каждом открытии страницы.
                return [
                    'url' => $candidate,
                    'width' => null,
                    'height' => null,
                ];
            }

            $path = (string) ($candidateParts['path'] ?? '');
            $query = isset($candidateParts['query']) ? '?' . $candidateParts['query'] : '';
        } else {
            if (str_starts_with($candidate, '//')
                || preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate) === 1) {
                return null;
            }
            $relativeParts = parse_url('/' . ltrim($candidate, '/'));
            if (!is_array($relativeParts)) {
                return null;
            }
            $path = (string) ($relativeParts['path'] ?? '');
            $query = isset($relativeParts['query']) ? '?' . $relativeParts['query'] : '';
        }

        $appParts = parse_url($appUrl);
        if (!is_array($appParts)
            || !in_array(strtolower((string) ($appParts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($appParts['host'] ?? '')) === '') {
            return null;
        }

        $local = self::localImage($path);
        if ($local === null) {
            return null;
        }

        return [
            'url' => rtrim($appUrl, '/') . '/' . ltrim($path, '/') . $query,
            'width' => $local['width'],
            'height' => $local['height'],
        ];
    }

    /** @return array{width:int|null,height:int|null}|null */
    private static function localImage(string $urlPath): ?array
    {
        if (!defined('APP_ROOT') || $urlPath === '' || str_contains($urlPath, "\0")) {
            return null;
        }

        $decodedPath = rawurldecode($urlPath);
        $extension = strtolower(pathinfo($decodedPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            return null;
        }

        $publicRoot = realpath(APP_ROOT . '/public');
        $file = realpath(APP_ROOT . '/public/' . ltrim($decodedPath, '/'));
        if ($publicRoot === false
            || $file === false
            || !is_file($file)
            || !str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $size = @getimagesize($file);

        return [
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
        ];
    }
}
