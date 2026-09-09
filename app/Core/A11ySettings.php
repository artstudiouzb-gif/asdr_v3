<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Настройки отображения («для слабовидящих»): размер текста, контраст,
 * шрифт, интервалы, картинки, анимации, подчёркивание ссылок, режим чтения.
 *
 * Хранятся в cookie одной строкой вида «size=140&contrast=mono» — тот же
 * формат читает и пишет JS (URLSearchParams). Состояние применяется на
 * сервере атрибутами на <html>, поэтому страница сразу приходит в нужном
 * виде, без мигания «обычная → крупная».
 */
final class A11ySettings
{
    public const COOKIE = 'a11y';

    /** Размер текста в процентах: шаг 10, отдельное CSS-правило на значение. */
    public const SIZE_MIN = 100;
    public const SIZE_MAX = 200;
    public const SIZE_STEP = 10;

    // Тёмный фон — это тёмная тема сайта, она живёт в своём переключателе
    // (data-theme + localStorage), поэтому здесь её нет.
    public const CONTRASTS = ['normal', 'mono', 'warm'];
    public const FONTS = ['default', 'readable', 'serif'];
    public const SPACINGS = ['normal', 'wide', 'wider'];

    /** Письменность узбекского текста: латиница (как в базе) или кириллица. */
    public const SCRIPTS = ['latn', 'cyrl'];

    public const DEFAULTS = [
        'size' => 100,
        'contrast' => 'normal',
        'font' => 'default',
        'spacing' => 'normal',
        'images' => 'on',
        'motion' => 'on',
        'links' => 'normal',
        'reading' => 'off',
        'script' => 'latn',
    ];

    /** @return list<int> */
    public static function sizes(): array
    {
        $sizes = [];
        for ($size = self::SIZE_MIN; $size <= self::SIZE_MAX; $size += self::SIZE_STEP) {
            $sizes[] = $size;
        }

        return $sizes;
    }

    public static function fromCookie(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return self::DEFAULTS;
        }

        // Cookie прежней версии: «cw:l:on» — переносим, чтобы у посетителя не
        // сбросилось то, что он уже включил.
        if (!str_contains($raw, '=')) {
            return self::fromLegacy($raw);
        }

        parse_str($raw, $parsed);

        return self::normalize($parsed);
    }

    public static function normalize(array $values): array
    {
        $result = self::DEFAULTS;

        $size = (int) round(((int) ($values['size'] ?? self::DEFAULTS['size'])) / self::SIZE_STEP) * self::SIZE_STEP;
        $result['size'] = max(self::SIZE_MIN, min(self::SIZE_MAX, $size));

        foreach (['contrast' => self::CONTRASTS, 'font' => self::FONTS, 'spacing' => self::SPACINGS] as $key => $allowed) {
            $value = (string) ($values[$key] ?? '');
            $result[$key] = in_array($value, $allowed, true) ? $value : self::DEFAULTS[$key];
        }

        $result['images'] = ($values['images'] ?? '') === 'off' ? 'off' : 'on';
        $result['motion'] = ($values['motion'] ?? '') === 'off' ? 'off' : 'on';
        $result['links'] = ($values['links'] ?? '') === 'underline' ? 'underline' : 'normal';
        $result['reading'] = ($values['reading'] ?? '') === 'on' ? 'on' : 'off';
        $result['script'] = ($values['script'] ?? '') === 'cyrl' ? 'cyrl' : 'latn';

        return $result;
    }

    /** Хоть что-то отличается от обычного вида страницы. */
    public static function isActive(array $settings): bool
    {
        return self::normalize($settings) !== self::DEFAULTS;
    }

    /**
     * Атрибуты для <html>: значения по умолчанию не печатаем, чтобы обычная
     * страница оставалась обычной.
     */
    public static function htmlAttributes(array $settings): string
    {
        $settings = self::normalize($settings);
        if ($settings === self::DEFAULTS) {
            return '';
        }

        $attributes = ['data-a11y="1"'];
        foreach ($settings as $key => $value) {
            if ($value === self::DEFAULTS[$key]) {
                continue;
            }
            $attributes[] = 'data-a11y-' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }

        return implode(' ', $attributes);
    }

    /** Строка для cookie: тот же формат, что читает JS. */
    public static function toCookie(array $settings): string
    {
        return http_build_query(self::normalize($settings));
    }

    /** Запоминает письменность, не трогая остальные настройки отображения. */
    public static function rememberScript(string $script): void
    {
        $settings = self::fromCookie($_COOKIE[self::COOKIE] ?? null);
        $settings['script'] = in_array($script, self::SCRIPTS, true) ? $script : 'latn';

        $value = self::toCookie($settings);
        $_COOKIE[self::COOKIE] = $value;
        if (PHP_SAPI === 'cli') {
            return;
        }

        setcookie(self::COOKIE, $value, [
            'expires' => time() + 31536000,
            'path' => '/',
            'domain' => '',
            'secure' => RequestUrl::isHttps(),
            // Настройку читает и меняет скрипт панели, поэтому не httponly.
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private static function fromLegacy(string $raw): array
    {
        $parts = explode(':', $raw);
        $scheme = $parts[0] ?? '';
        if (!in_array($scheme, ['cw', 'wc', 'bb'], true)) {
            return self::DEFAULTS;
        }

        return self::normalize([
            // Прежние схемы: чёрным по белому и белым по чёрному — это
            // максимальный контраст, «тёмно-синим по голубому» — тёплый фон.
            'contrast' => $scheme === 'bb' ? 'warm' : 'mono',
            'size' => ['m' => 130, 'l' => 150, 'xl' => 180][$parts[1] ?? 'l'] ?? 150,
            'images' => ($parts[2] ?? '') === 'off' ? 'off' : 'on',
            'spacing' => 'wide',
            'links' => 'underline',
        ]);
    }
}
