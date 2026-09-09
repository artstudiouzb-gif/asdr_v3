<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Единая точка выбора публичных CSS/JS.
 *
 * В production используются минифицированные бандлы. Если manifest или
 * бандлы отсутствуют/повреждены, сайт без фатальной ошибки возвращается к
 * исходным файлам. Полная проверка исходников выполняется только в админке.
 */
final class FrontendAssets
{
    private const CSS_SOURCES = [
        // Файлы самих шрифтов подключает LocalGoogleFonts отдельными ссылками
        // (только выбранное семейство). Здесь — лишь метрики запасных
        // начертаний, они нужны на каждой странице.
        '/assets/css/gov-fonts.css',
        '/assets/css/frontend.css',
        // Стили детальной новости живут в blocks/news-detail.css и в общий
        // бандл не входят: см. AssetCollector::THEME_PART_MAP.
        '/assets/css/gov-theme.css',
        '/assets/css/rich-content.css',
        '/assets/css/a11y.css',
        '/assets/css/public-layout-polish.css',
        '/assets/css/public-editorial-pages.css',
        '/assets/css/public-home.css',
    ];

    private const JS_SOURCES = [
        '/assets/js/a11y.js',
        '/assets/js/frontend.js',
        '/assets/js/forms.js',
    ];

    private const CSS_BUNDLE = '/assets/css/public.min.css';
    private const JS_BUNDLE = '/assets/js/public.min.js';
    private const MANIFEST = '/public/assets/asset-manifest.json';
    private const CONTENT_MODES_CSS = '/assets/css/public-content-modes.css';

    /** @return array<int, string> */
    public static function styles(): array
    {
        $styles = self::enabled() ? [self::CSS_BUNDLE] : self::CSS_SOURCES;
        // Печатный и читательский режимы загружаются после темы и generated
        // CSS. Так правила печати не зависят от актуальности asset bundle.
        $styles[] = self::CONTENT_MODES_CSS;

        return $styles;
    }

    /** @return array<int, string> */
    public static function scripts(): array
    {
        return self::enabled() ? [self::JS_BUNDLE] : self::JS_SOURCES;
    }

    /**
     * Путь к ассету блока с учётом сборки: при включённых бандлах отдаёт
     * минифицированную версию из манифеста, иначе — исходник. Ассеты блоков
     * не входят в общий бандл (подключаются только на своих страницах), но
     * минифицируются тем же `npm run build:assets`.
     */
    public static function blockAsset(string $path): string
    {
        if (!self::enabled()) {
            return $path;
        }

        $manifest = self::manifest();
        $entry = is_array($manifest['blocks'] ?? null) ? ($manifest['blocks'][$path] ?? null) : null;
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            return $path;
        }

        // Файл мог не доехать при выкладке — тогда лучше исходник, чем 404.
        return is_file(APP_ROOT . '/public' . $entry['path']) ? $entry['path'] : $path;
    }

    public static function enabled(): bool
    {
        return Setting::get('perf_asset_bundle', '1') === '1' && self::bundleReady();
    }

    /** Быстрая production-проверка без чтения содержимого всех исходников. */
    private static function bundleReady(): bool
    {
        $manifest = self::manifest();
        if ($manifest === null) {
            return false;
        }

        foreach (['css', 'js'] as $type) {
            $entry = $manifest[$type] ?? null;
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_numeric($entry['raw'] ?? null)) {
                return false;
            }
            $file = APP_ROOT . '/public' . $entry['path'];
            if (!is_file($file) || filesize($file) === 0) {
                return false;
            }
            $diff = abs((int) filesize($file) - (int) $entry['raw']);
            if ($diff > max(2000, (int) ($entry['raw'] * 0.05))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $manifest = self::manifest();
        $ready = self::bundleReady();
        $current = $ready && $manifest !== null
            && self::entryCurrent($manifest['css'] ?? null)
            && self::entryCurrent($manifest['js'] ?? null);
        $htaccess = @file_get_contents(APP_ROOT . '/public/.htaccess');

        return [
            'configured' => Setting::get('perf_asset_bundle', '1') === '1',
            'enabled' => self::enabled(),
            'ready' => $ready,
            'current' => $current,
            'css' => is_array($manifest['css'] ?? null) ? $manifest['css'] : [],
            'js' => is_array($manifest['js'] ?? null) ? $manifest['js'] : [],
            'brotliConfigured' => is_string($htaccess) && str_contains($htaccess, 'BROTLI_COMPRESS'),
            'gzipConfigured' => is_string($htaccess) && str_contains($htaccess, 'DEFLATE'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function manifest(): ?array
    {
        $raw = @file_get_contents(APP_ROOT . self::MANIFEST);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && ($data['version'] ?? null) === 1 ? $data : null;
    }

    private static function entryCurrent(mixed $entry): bool
    {
        if (!is_array($entry)
            || !is_string($entry['path'] ?? null)
            || !is_string($entry['sha256'] ?? null)
            || !is_string($entry['sourceSha256'] ?? null)
            || !is_array($entry['sources'] ?? null)) {
            return false;
        }

        $output = APP_ROOT . '/public' . $entry['path'];
        $outputContent = @file_get_contents($output);
        if (!is_string($outputContent)) {
            return false;
        }
        $outputNormalized = str_replace(["\r\n", "\r"], "\n", $outputContent);
        $outputSha = hash('sha256', $outputNormalized);
        $directSha = hash('sha256', $outputContent);
        if (!hash_equals($entry['sha256'], $outputSha) && !hash_equals($entry['sha256'], $directSha)) {
            return false;
        }

        $hash = hash_init('sha256');
        foreach ($entry['sources'] as $source) {
            if (!is_string($source)) {
                return false;
            }
            $file = APP_ROOT . '/public' . $source;
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                return false;
            }
            $content = str_replace(["\r\n", "\r"], "\n", $content);
            hash_update($hash, 'public' . $source);
            hash_update($hash, "\0");
            hash_update($hash, $content);
            hash_update($hash, "\0");
        }

        return hash_equals($entry['sourceSha256'], hash_final($hash));
    }
}
