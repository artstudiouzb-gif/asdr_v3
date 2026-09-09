<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * White-label панели управления: название, логотип и акцентный цвет админки
 * задаются в «Настройках» и применяются в topbar, <title> и на страницах
 * входа. Пустые значения означают стандартный брендинг ArtStudio.
 */
final class AdminBrand
{
    public const DEFAULT_NAME = 'ASDR';
    public const DEFAULT_ACCENT = '#2271b1';

    /** Название панели для topbar и <title>. */
    public static function name(): string
    {
        $name = trim(self::setting('admin_brand_name'));
        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** URL логотипа панели; null — рисуем буквенный бейдж. */
    public static function logo(): ?string
    {
        $logo = trim(self::setting('admin_brand_logo'));
        return $logo !== '' ? $logo : null;
    }

    /** Первая буква названия для бейджа-заглушки. */
    public static function letter(): string
    {
        return mb_strtoupper(mb_substr(self::name(), 0, 1));
    }

    /** Акцентный цвет панели (#rrggbb). */
    public static function accent(): string
    {
        return SettingsValidator::hexColor(self::setting('admin_brand_accent'), self::DEFAULT_ACCENT);
    }

    /**
     * Head-разметка админки: CSS-переменные бренда и внешние клиенты панели.
     * Имя метода сохраняется для обратной совместимости layout.
     */
    public static function styleTag(): string
    {
        $html = '';
        $accent = self::accent();
        if ($accent !== self::DEFAULT_ACCENT) {
            // Theme selectors in admin.css use html[data-admin-theme] and are
            // more specific than a plain :root rule. Match that specificity so
            // the configured white-label accent is not silently overwritten.
            $html .= '<style>:root[data-admin-theme]{'
                . '--admin-accent:' . $accent . ';'
                . '--admin-accent-hover:' . self::mix($accent, [0, 0, 0], 0.14) . ';'
                . '--admin-accent-soft:' . self::mix($accent, [255, 255, 255], 0.90) . ';'
                . '--admin-accent-2:' . self::mix($accent, [255, 255, 255], 0.12) . ';'
                . '--admin-primary:' . $accent . ';'
                . '--admin-primary-dark:' . self::mix($accent, [0, 0, 0], 0.14) . ';'
                . '}</style>';
        }

        $html .= '<link rel="stylesheet" data-admin-notifications-css="1" href="'
            . htmlspecialchars(Asset::url('/assets/css/admin-notifications.css'), ENT_QUOTES)
            . '">';
        $html .= '<link rel="stylesheet" data-admin-shell-stability-css="1" href="'
            . htmlspecialchars(Asset::url('/assets/css/admin-shell-stability.css'), ENT_QUOTES)
            . '">';
        $html .= '<link rel="stylesheet" data-admin-hero-slide-editor-css="1" href="'
            . htmlspecialchars(Asset::url('/assets/css/admin-hero-slide-editor.css'), ENT_QUOTES)
            . '">';
        $html .= '<link rel="stylesheet" data-admin-hero-slide-editor-reset-css="1" href="'
            . htmlspecialchars(Asset::url('/assets/css/admin-hero-slide-editor-legacy-reset.css'), ENT_QUOTES)
            . '">';
        $html .= '<script nonce="' . htmlspecialchars(SecurityHeaders::nonce(), ENT_QUOTES)
            . '" src="' . htmlspecialchars(Asset::url('/assets/js/admin-notifications.js'), ENT_QUOTES)
            . '" defer></script>';

        return $html;
    }

    /** HTML-теги фавиконки для страниц админ-панели и авторизации. */
    public static function faviconHtml(): string
    {
        $html = '';
        foreach (Favicon::staticIcons() as $tag) {
            $html .= $tag . "\n";
        }

        $settingTag = Favicon::settingTag(self::setting('favicon_url'));
        if ($settingTag !== '') {
            $html .= $settingTag . "\n";
        }

        return $html;
    }

    /** Разметка бренда для topbar и карточек входа. */
    public static function badgeHtml(string $imgClass = 'admin-topbar__logoimg', string $letterClass = 'admin-topbar__logo'): string
    {
        $logo = self::logo();
        if ($logo !== null) {
            return '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="" class="' . $imgClass . '">';
        }
        return '<span class="' . $letterClass . '">' . htmlspecialchars(self::letter(), ENT_QUOTES) . '</span>';
    }

    /** Смешивание цвета с RGB-точкой (weight — доля второй точки). */
    private static function mix(string $hex, array $with, float $weight): string
    {
        $r = (int) hexdec(substr($hex, 1, 2));
        $g = (int) hexdec(substr($hex, 3, 2));
        $b = (int) hexdec(substr($hex, 5, 2));
        $mix = static fn (int $a, int $b2): string => str_pad(
            dechex(max(0, min(255, (int) round($a * (1 - $weight) + $b2 * $weight)))),
            2,
            '0',
            STR_PAD_LEFT
        );

        return '#' . $mix($r, (int) $with[0]) . $mix($g, (int) $with[1]) . $mix($b, (int) $with[2]);
    }

    /** Настройка с защитой от отсутствующей БД (страницы входа при сбое). */
    private static function setting(string $key): string
    {
        try {
            return Setting::get($key);
        } catch (\Throwable) {
            return '';
        }
    }
}
