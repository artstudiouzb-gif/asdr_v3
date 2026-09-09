<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Собирает зависимости (JS/CSS) блоков страницы и выводит каждую строго один
 * раз внизу страницы. Если на странице несколько одинаковых блоков (например,
 * три слайдера), их общий скрипт подключается однократно.
 */
final class AssetCollector
{
    /** @var array<string, bool> */
    private static array $js = [];

    /** @var array<string, string> ключ ассета -> путь к файлу */
    private static array $css = [];

    /** Известные ассеты блоков: ключ -> путь к файлу. */
    private const JS_MAP = [
        'slider' => '/assets/js/blocks/slider.js',
        'anchor_nav' => '/assets/js/blocks/anchor_nav.js',
        'news' => '/assets/js/news.js',
        'news_share_gallery' => '/assets/js/news-share-gallery.js',
        'news_gallery_thumbnails' => '/assets/js/news-gallery-thumbnails.js',
        'news_feature' => '/assets/js/blocks/news_feature.js',
        'leader_card' => '/assets/js/blocks/leader_card.js',
        'tabs' => '/assets/js/blocks/tabs.js',
        'org_structure' => '/assets/js/blocks/org_structure.js',
        'hero_slides' => '/assets/js/blocks/hero.js',
    ];

    /**
     * Части темы, вынесенные из общего бандла ради страниц, которым они не
     * нужны. Они подключаются сразу после общего бандла и ДО дизайн-CSS из
     * админки, поэтому настройки раздела «Дизайн» продолжают иметь приоритет.
     *
     * Ключ — либо тип блока конструктора, либо имя раздела для страниц вне
     * конструктора. PageController прогоняет типы страницы через requireJs(),
     * а тот автоматически регистрирует соответствующую часть темы.
     */
    private const THEME_PART_MAP = [
        'news_detail' => '/assets/css/blocks/news-detail.css',
        // Блок новостей в варианте «карточки» построен на тех же .relnews-card,
        // что и лента: стили лежат в этой же части темы.
        'news_feature' => '/assets/css/blocks/news-detail.css',
        'org_structure' => '/assets/css/blocks/org-structure.css',
        'leader_card' => '/assets/css/blocks/leader-card.css',
        'media_gallery' => '/assets/css/blocks/media-gallery.css',
        'tabs' => '/assets/css/blocks/tabs.css',
        'hero_slides' => '/assets/css/blocks/hero.css',
        // Каталоги живут на своих адресах (`/catalog/{type}`, а у типа со снятым
        // префиксом — `/{type}`) — на обычных страницах их стили были мёртвым грузом.
        'catalog' => '/assets/css/blocks/catalog.css',
        // Ключ совпадает с типом блока — подключение происходит само,
        // когда блок есть на странице (BlockRenderer::renderPage).
        'counters' => '/assets/css/blocks/counters.css',
    ];

    /**
     * Дополнительные стили конкретного блока. В отличие от частей темы они
     * выводятся через renderStyles() и могут точечно переопределить базовую
     * композицию, не раздувая общий публичный CSS-бандл.
     */
    private const BLOCK_CSS_MAP = [
        'collage' => '/assets/css/blocks/collage.css',
        'table' => '/assets/css/blocks/table.css',
        'image' => '/assets/css/blocks/image.css',
        'embed' => '/assets/css/blocks/embed.css',
        'chart' => '/assets/css/blocks/chart.css',
        'divider' => '/assets/css/blocks/divider.css',
        'buttons' => '/assets/css/blocks/buttons.css',
        'news_feature' => '/assets/css/blocks/news-feature.css',
        'hero_slides' => '/assets/css/blocks/hero-art-layout.css',
    ];

    /** @var array<string, bool> */
    private static array $themeParts = [];

    public static function requireJs(string $key): void
    {
        if (isset(self::JS_MAP[$key])) {
            self::$js[$key] = true;
        }
        self::requireThemePart($key);
    }

    /** Для редких дополнительных стилей, которые регистрируются вручную. */
    public static function requireCss(string $key, string $href): void
    {
        self::$css[$key] = $href;
    }

    /** Подключает вынесенную часть темы (см. THEME_PART_MAP). */
    public static function requireThemePart(string $key): void
    {
        if (isset(self::THEME_PART_MAP[$key])) {
            self::$themeParts[$key] = true;
        }
        if (isset(self::BLOCK_CSS_MAP[$key])) {
            self::requireCss('block-' . $key, self::BLOCK_CSS_MAP[$key]);
        }
    }

    public static function reset(): void
    {
        self::$js = [];
        self::$css = [];
        self::$themeParts = [];
    }

    /** Части темы: выводятся сразу после общего бандла, до дизайн-CSS. */
    public static function renderThemeStyles(): string
    {
        $html = '';
        foreach (array_keys(self::$themeParts) as $key) {
            $url = Asset::url(FrontendAssets::blockAsset(self::THEME_PART_MAP[$key]));
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES) . '" data-theme-part>' . "\n";
        }

        return $html;
    }

    public static function renderScripts(): string
    {
        $html = '';
        foreach (array_keys(self::$js) as $key) {
            $src = Asset::url(FrontendAssets::blockAsset(self::JS_MAP[$key]));
            $html .= '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" defer></script>' . "\n";
        }

        return $html;
    }

    public static function renderStyles(): string
    {
        $html = '';
        foreach (self::$css as $href) {
            $url = Asset::url(FrontendAssets::blockAsset((string) $href));
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES) . '" data-block-css>' . "\n";
        }

        return $html;
    }
}
