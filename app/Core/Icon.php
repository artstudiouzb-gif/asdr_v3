<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Единая точка рендера и очистки ключей Tabler Icons.
 *
 * В базе и JSON хранится только короткий ключ (например, "home"). Геометрия
 * иконок берётся из локального SVG-спрайта, поэтому в шаблонах больше не нужны
 * собственные <path>, а сайт не зависит от CDN.
 */
final class Icon
{
    public const SPRITE_PATH = '/assets/vendor/tabler/tabler-sprite.svg';

    /**
     * Иконки, которые публичный JS вставляет в уже отрисованную страницу
     * (кнопки шаринга, копирования, навигация галереи). В исходном HTML их
     * нет, поэтому геометрию встраиваем заранее — иначе после клика
     * появлялась бы пустая рамка. Список сверяется тестом с frontend.js.
     */
    public const RUNTIME_ICONS = [
        'brand-telegram', 'check', 'chevron-left', 'chevron-right',
        'circle-check', 'circle-x', 'copy', 'download', 'x',
    ];

    /** @var array<string, array{0: int, 1: int}>|null индекс смещений спрайта */
    private static ?array $spriteIndex = null;
    public const CATALOG_PATH = '/assets/vendor/tabler/tabler-icons.json';

    /**
     * Совместимость со старыми короткими ключами AdminUI. Новые ключи можно
     * указывать напрямую по имени иконки на tabler.io/icons.
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'a11y' => 'accessible',
        'albums' => 'album',
        'audit' => 'clipboard-list',
        'block' => 'layout-grid',
        'button' => 'rectangle',
        'columns' => 'columns-3',
        'content_types' => 'stack-2',
        'copy' => 'copy',
        'dashboard' => 'layout-dashboard',
        'design' => 'palette',
        'divider' => 'separator-vertical',
        'document' => 'file-text',
        'edit' => 'edit',
        'email' => 'mail',
        'external' => 'external-link',
        'facebook' => 'brand-facebook',
        'files' => 'photo',
        'forms' => 'forms',
        'grid' => 'layout-grid',
        'grip' => 'grip-vertical',
        'header' => 'layout-navbar',
        'footer' => 'layout-bottombar',
        'image' => 'photo',
        'instagram' => 'brand-instagram',
        'info' => 'info-circle',
        'languages' => 'language',
        'layout' => 'layout-dashboard',
        'linkedin' => 'brand-linkedin',
        'logo' => 'box',
        'media' => 'photo',
        'news' => 'news',
        'pages' => 'files',
        'panel-left' => 'layout-sidebar-left-collapse',
        'performance' => 'bolt',
        'pause' => 'player-pause',
        'projects' => 'briefcase',
        'profile' => 'user-circle',
        'redirects' => 'route-alt-right',
        'repository' => 'database',
        'reset' => 'refresh',
        'save' => 'device-floppy',
        'send' => 'send',
        'seo' => 'world-search',
        'sliders' => 'adjustments-horizontal',
        'snippet' => 'source-code',
        'social' => 'share-3',
        'space' => 'spacing-horizontal',
        'spacer' => 'arrows-horizontal',
        'stats' => 'chart-bar',
        'subscribers' => 'address-book',
        'telegram' => 'brand-telegram',
        'tender' => 'building-bank',
        'theme' => 'moon',
        'trash' => 'trash',
        'type' => 'typography',
        'videos' => 'video',
        'warning' => 'alert-triangle',
        'webhooks' => 'webhook',
        'widgets' => 'layout-grid',
    ];

    /** @var array<string,true>|null */
    private static ?array $available = null;

    public static function cleanName(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $name = strtolower(trim((string) $value));
        if ($name === '' || str_contains($name, '<') || strlen($name) > 80) {
            return '';
        }
        if (str_starts_with($name, 'tabler-')) {
            $name = substr($name, 7);
        }

        // Подчёркивание допустимо: короткие ключи разделов админки пишутся в
        // snake_case («content_types»), а прежний набор символов отбраковывал
        // такое имя ещё до обращения к ALIASES — псевдоним был недостижим, и
        // раздел оставался без иконки. Итоговое имя всё равно сверяется с
        // каталогом Tabler ниже, поэтому расширение набора безопасно.
        if (preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $name) !== 1) {
            return '';
        }

        $resolved = self::ALIASES[$name] ?? $name;

        return self::isAvailable($resolved) ? $name : '';
    }

    public static function resolvedName(mixed $value): string
    {
        $name = self::cleanName($value);

        return self::ALIASES[$name] ?? $name;
    }

    public static function isAvailable(mixed $value): bool
    {
        $name = strtolower(trim((string) $value));
        if ($name === '') {
            return false;
        }
        if (self::$available === null) {
            $path = dirname(__DIR__, 2) . '/public' . self::CATALOG_PATH;
            $decoded = is_file($path)
                ? json_decode((string) file_get_contents($path), true)
                : null;
            $names = is_array($decoded) && is_array($decoded['icons'] ?? null)
                ? $decoded['icons']
                : [];
            self::$available = array_fill_keys(
                array_values(array_filter($names, 'is_string')),
                true
            );
        }

        return isset(self::$available[$name]);
    }

    public static function render(
        mixed $name,
        int $size = 16,
        string $class = 'ui-icon',
        float $strokeWidth = 2.0
    ): string {
        $resolved = self::resolvedName($name);
        if ($resolved === '') {
            return '';
        }

        $size = max(8, min(160, $size));
        $strokeWidth = max(1.0, min(3.0, $strokeWidth));
        $stroke = rtrim(rtrim(number_format($strokeWidth, 2, '.', ''), '0'), '.');
        $class = trim($class);
        $classes = trim('icon icon-tabler ' . $class);
        // Ссылка на символ внутри документа: нужные <symbol> встраивает
        // Icon::injectSprite(), поэтому спрайт целиком страница не грузит.
        $href = '#tabler-' . $resolved;

        return '<svg class="' . htmlspecialchars($classes, ENT_QUOTES) . '" width="' . $size
            . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
            . $stroke . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<use href="' . htmlspecialchars($href, ENT_QUOTES) . '"></use></svg>';
    }

    /**
     * Шаблон для безопасного создания той же иконки в JavaScript.
     */
    public static function browserConfigHtml(): string
    {
        return '<meta name="asdr-icon-sprite" content="'
            . htmlspecialchars(self::SPRITE_PATH, ENT_QUOTES) . '">'
            . '<meta name="asdr-icon-catalog" content="'
            . htmlspecialchars(self::CATALOG_PATH, ENT_QUOTES) . '">';
    }

    /**
     * Встраивает в HTML геометрию только тех иконок, что в нём встретились.
     *
     * Ключи берём из готовой разметки, а не из счётчика вызовов render():
     * блоки страниц отдаются из кэша, и при попадании в кэш render() уже не
     * вызывается — иконки остались бы без геометрии.
     */
    public static function injectSprite(string $html): string
    {
        if (!str_contains($html, 'tabler-')) {
            return $html;
        }
        $bodyEnd = self::bodyTagEnd($html);
        if ($bodyEnd === null) {
            return $html;
        }

        preg_match_all('/href="#tabler-([a-z0-9-]+)"/', $html, $matches);
        $names = array_values(array_unique(array_merge($matches[1], self::RUNTIME_ICONS)));
        if ($names === []) {
            return $html;
        }

        $symbols = self::symbols($names);
        if ($symbols === []) {
            return $html;
        }

        // Символы разложены по строкам: спрайт — самый крупный кусок страницы,
        // и одной строкой он превращал «Исходный код» в нечитаемую простыню.
        // Пробел между <symbol> в SVG ничего не значит.
        $sprite = '<svg class="tabler-sprite" aria-hidden="true" focusable="false" width="0" height="0"><defs>'
            . "\n    " . implode("\n    ", $symbols) . "\n  </defs></svg>";

        return substr($html, 0, $bodyEnd) . $sprite . substr($html, $bodyEnd);
    }

    /**
     * Позиция сразу за открывающим тегом <body …>.
     */
    private static function bodyTagEnd(string $html): ?int
    {
        $bodyPos = stripos($html, '<body');
        if ($bodyPos === false) {
            return null;
        }
        $close = strpos($html, '>', $bodyPos);

        return $close === false ? null : $close + 1;
    }

    /**
     * Вырезает <symbol> из спрайта по индексу смещений (без разбора файла).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function symbols(array $names): array
    {
        $index = self::spriteIndex();
        if ($index === []) {
            return [];
        }
        $file = \dirname(__DIR__, 2) . '/public' . self::SPRITE_PATH;
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $out = [];
        foreach ($names as $name) {
            if (!isset($index[$name])) {
                continue;
            }
            [$offset, $length] = $index[$name];
            // Длина приходит из сгенерированного индекса. Нулевая или
            // отрицательная означала бы, что индекс пересобран не тем
            // спрайтом; `fread()` на такой длине бросает ValueError, то есть
            // одна испорченная запись уронила бы страницу целиком вместо
            // единственной ненарисованной иконки.
            if ($length < 1 || fseek($handle, $offset) !== 0) {
                continue;
            }
            $chunk = fread($handle, $length);
            if (is_string($chunk)) {
                $out[] = $chunk;
            }
        }
        fclose($handle);

        return $out;
    }

    /** @return array<string, array{0: int, 1: int}> */
    private static function spriteIndex(): array
    {
        if (self::$spriteIndex !== null) {
            return self::$spriteIndex;
        }
        $file = __DIR__ . '/data/tabler-sprite-index.php';
        $index = is_file($file) ? require $file : [];
        self::$spriteIndex = is_array($index) ? $index : [];

        return self::$spriteIndex;
    }
}
