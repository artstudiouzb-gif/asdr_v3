<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Локальный кэш выбранных в конструкторе Google Fonts.
 *
 * Браузер посетителя никогда не обращается к Google: при сохранении дизайна
 * сервер один раз скачивает только выбранные семейства и дальше отдаёт CSS и
 * WOFF2 со своего домена.
 *
 * Часть семейств входит в дистрибутив (BUNDLED) — ими набран сайт сразу после
 * установки, до того как администратор впервые открыл раздел «Дизайн».
 */
final class LocalGoogleFonts
{
    private const PUBLIC_DIR = '/public/uploads/public/google-fonts';
    private const PUBLIC_URL = '/uploads/public/google-fonts';
    private const REQUIRED_SUBSETS = ['cyrillic-ext', 'cyrillic', 'latin'];
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';
    private const MAX_FONT_BYTES = 3_145_728;

    /**
     * Семейства из поставки: slug => файл CSS в /assets/css. Скачивать их не
     * нужно, файлы уже лежат рядом (npm run sync:fonts).
     */
    private const BUNDLED = [
        'noto-sans' => 'noto-sans',
        'noto-serif' => 'noto-serif',
    ];

    /** @param list<string> $slugs @return array{ok:bool,error:string} */
    public static function installSelected(array $slugs): array
    {
        // Каталогов два (текстовые семейства и рукописные), а установка одна:
        // отсюда виден общий список, роль слуга здесь уже не важна.
        $catalog = DesignSettings::fontCatalog();
        foreach (array_values(array_unique($slugs)) as $slug) {
            if ($slug === '' || !isset($catalog[$slug])) {
                continue;
            }
            if (isset(self::BUNDLED[$slug])) {
                if (!self::bundledReady($slug)) {
                    return [
                        'ok' => false,
                        'error' => 'Локальные файлы «' . $catalog[$slug][0] . '» отсутствуют.',
                    ];
                }
                continue;
            }

            $error = self::install($slug);
            if ($error !== null) {
                Logger::warning('Не удалось локализовать Google Font.', ['slug' => $slug, 'error' => $error]);
                return [
                    'ok' => false,
                    'error' => 'Не удалось сохранить шрифт «'
                        . $catalog[$slug][0]
                        . '» локально: ' . $error,
                ];
            }
        }

        return ['ok' => true, 'error' => ''];
    }

    /** @return list<string> */
    public static function stylesheetsForSelected(): array
    {
        $urls = [];
        $stacks = SiteThemeCss::fontStacks();
        foreach (['body' => $stacks['body'], 'heading' => $stacks['heading']] as $role => $stack) {
            $slug = (string) Setting::get('design_font_google_' . $role, '');
            if ($slug === '' || !isset(DesignSettings::GOOGLE_FONTS[$slug])) {
                // Слуг пуст на свежей установке, а стек уже просит семейство из
                // поставки. Без этой ветки страница объявляла шрифт, для
                // которого не подключён ни один @font-face: браузер молча
                // рисовал системным, а preload всё равно качал файл.
                $slug = self::bundledSlugForStack($stack);
            }
            if ($slug === '' || !isset(DesignSettings::GOOGLE_FONTS[$slug])) {
                continue;
            }
            if (isset(self::BUNDLED[$slug])) {
                if (self::bundledReady($slug)) {
                    $urls[$slug] = Asset::url('/assets/css/' . self::BUNDLED[$slug] . '.css');
                }
                continue;
            }
            if (self::installedReady($slug)) {
                $urls[$slug] = Asset::url(self::PUBLIC_URL . '/' . $slug . '/font.css');
            }
        }

        // Рукописный шрифт подключается только когда он выбран: у роли нет
        // умолчания и нет семейства в поставке. Не выбран — нет и запроса.
        $script = (string) Setting::get('design_font_script', '');
        if ($script !== '' && isset(DesignSettings::SCRIPT_FONTS[$script]) && self::installedReady($script)) {
            $urls[$script] = Asset::url(self::PUBLIC_URL . '/' . $script . '/font.css');
        }

        return array_values($urls);
    }

    /**
     * Семейство из поставки, которым начинается стек, — или пустая строка.
     * Сравнение по началу: «Inter Tight» содержит «Inter», поэтому длинные
     * имена проверяются первыми.
     */
    private static function bundledSlugForStack(string $stack): string
    {
        $first = trim(explode(',', $stack)[0] ?? '', " '\"");
        if ($first === '') {
            return '';
        }
        foreach (self::BUNDLED as $slug => $name) {
            if (strcasecmp($first, DesignSettings::GOOGLE_FONTS[$slug][0]) === 0) {
                return $slug;
            }
            // В каталоге подпись может отличаться от имени семейства
            // («Noto Serif (антиква)»), поэтому сверяем и с самим стеком.
            if (stripos(DesignSettings::GOOGLE_FONTS[$slug][1], "'" . $first . "'") === 0) {
                return $slug;
            }
        }

        return '';
    }

    private static function install(string $slug): ?string
    {
        if (self::installedReady($slug)) {
            return null;
        }

        $catalog = DesignSettings::fontCatalog();

        $root = self::root();
        $directory = $root . self::PUBLIC_DIR . '/' . $slug;
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return 'сервер не может создать каталог шрифта';
        }

        $lock = @fopen($directory . '/.install.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return 'не удалось заблокировать одновременную установку';
        }

        try {
            if (self::installedReady($slug)) {
                return null;
            }

            [, , $query] = $catalog[$slug];
            $cssUrl = 'https://fonts.googleapis.com/css2?family=' . $query . '&display=swap';
            $response = Http::get($cssUrl, [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: text/css,*/*;q=0.1',
            ], 25);
            if ($response['status'] !== 200 || $response['body'] === '') {
                return 'Google Fonts недоступен (' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']) . ')';
            }

            $faces = self::parseFaces($response['body']);
            $coverageError = self::coverageError($slug, $faces);
            if ($coverageError !== null) {
                return $coverageError;
            }

            $localFiles = [];
            foreach ($faces as $face) {
                $url = $face['url'];
                $parts = parse_url($url);
                if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'fonts.gstatic.com') {
                    return 'получен неожиданный адрес файла шрифта';
                }
                $filename = 'font-' . substr(hash('sha256', $url), 0, 20) . '.woff2';
                $localFiles[$url] = $filename;
                $target = $directory . '/' . $filename;
                if (self::validWoff2($target)) {
                    continue;
                }

                $fontResponse = Http::get($url, ['User-Agent: ' . self::USER_AGENT], 25);
                $body = $fontResponse['body'];
                if ($fontResponse['status'] !== 200
                    || strlen($body) < 4
                    || strlen($body) > self::MAX_FONT_BYTES
                    || substr($body, 0, 4) !== 'wOF2') {
                    return 'получен повреждённый или слишком большой WOFF2-файл';
                }
                if (!self::atomicWrite($target, $body)) {
                    return 'сервер не может записать WOFF2-файл';
                }
            }

            $blocks = [];
            foreach ($faces as $face) {
                $family = str_replace(['\\', "'"], ['\\\\', "\\'"], $face['family']);
                $blocks[] = '/* ' . $face['subset'] . " */\n@font-face {\n"
                    . "    font-family: '{$family}';\n"
                    . '    font-style: ' . $face['style'] . ";\n"
                    . '    font-weight: ' . $face['weight'] . ";\n"
                    . "    font-display: swap;\n"
                    . "    src: url('" . $localFiles[$face['url']] . "') format('woff2');\n"
                    . '    unicode-range: ' . $face['unicode_range'] . ";\n}";
            }
            $css = "/* Локальная копия выбранного шрифта; внешних запросов нет. */\n\n"
                . implode("\n\n", $blocks) . "\n";
            if (!self::atomicWrite($directory . '/font.css', $css)) {
                return 'сервер не может записать локальный CSS шрифта';
            }

            return self::installedReady($slug) ? null : 'локальная копия не прошла итоговую проверку';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array{subset:string,family:string,style:string,weight:string,url:string,unicode_range:string}> */
    private static function parseFaces(string $css): array
    {
        preg_match_all('/\/\*\s*([^*]+?)\s*\*\/\s*(@font-face\s*\{[^}]+\})/s', $css, $matches, PREG_SET_ORDER);
        $faces = [];
        foreach ($matches as $match) {
            $subset = trim($match[1]);
            if (!in_array($subset, self::REQUIRED_SUBSETS, true)) {
                continue;
            }
            $block = $match[2];
            $property = static function (string $name) use ($block): string {
                return preg_match('/' . preg_quote($name, '/') . '\s*:\s*([^;]+);/i', $block, $value)
                    ? trim($value[1]) : '';
            };
            preg_match('/url\(([\'\"]?)([^)\'\"]+)\1\)/i', $block, $url);
            $faces[] = [
                'subset' => $subset,
                'family' => trim($property('font-family'), "'\""),
                'style' => $property('font-style'),
                'weight' => $property('font-weight'),
                'url' => $url[2] ?? '',
                'unicode_range' => $property('unicode-range'),
            ];
        }

        return $faces;
    }

    /** @param list<array{subset:string,family:string,style:string,weight:string,url:string,unicode_range:string}> $faces */
    /**
     * Лицо переменного шрифта объявляет диапазон («400 700»), а не одно число:
     * файлы Noto в поставке переменные, одна woff2 покрывает всю ось. Прежде
     * сравнение шло строкой, и такой файл не засчитывался ни одному весу.
     */
    private static function weightCovered(string $declared, string $weight): bool
    {
        $parts = preg_split('/\s+/', trim($declared)) ?: [];
        if ($parts === [] || count($parts) > 2) {
            return false;
        }
        $min = (int) $parts[0];
        $max = (int) ($parts[1] ?? $parts[0]);

        return (int) $weight >= $min && (int) $weight <= $max;
    }

    private static function coverageError(string $slug, array $faces): ?string
    {
        $catalog = DesignSettings::fontCatalog();
        [, , $query] = $catalog[$slug];
        if (!preg_match('/^([^:]+):wght@([0-9;]+)$/', $query, $queryParts)) {
            return 'неверное описание семейства в каталоге';
        }
        $family = str_replace('+', ' ', $queryParts[1]);
        $weights = explode(';', $queryParts[2]);
        foreach (self::REQUIRED_SUBSETS as $subset) {
            foreach ($weights as $weight) {
                $found = false;
                foreach ($faces as $face) {
                    if ($face['subset'] === $subset
                        && $face['family'] === $family
                        && $face['style'] === 'normal'
                        && self::weightCovered($face['weight'], $weight)
                        && $face['url'] !== ''
                        && $face['unicode_range'] !== '') {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return "у семейства нет подмножества {$subset} для веса {$weight}";
                }
            }
        }

        return null;
    }

    /**
     * Готова ли локальная копия шрифта на диске.
     *
     * Ответ меняется между вызовами: проверка стоит до блокировки, повторно
     * под блокировкой (пока мы её ждали, копию мог установить другой процесс)
     * и ещё раз после записи файлов. Без пометки статический анализ считает
     * вызов детерминированным и объявляет две последние проверки мёртвыми —
     * а это ровно двойная проверка блокировки и сверка результата записи.
     *
     * @phpstan-impure
     */
    private static function installedReady(string $slug): bool
    {
        $directory = self::root() . self::PUBLIC_DIR . '/' . $slug;
        $cssPath = $directory . '/font.css';
        if (!is_file($cssPath)) {
            return false;
        }
        $css = (string) @file_get_contents($cssPath);
        if ($css === '' || preg_match('/https?:\/\//i', $css)) {
            return false;
        }
        preg_match_all("/url\\('([a-z0-9-]+\\.woff2)'\\)/", $css, $files);
        if ($files[1] === []) {
            return false;
        }
        foreach (array_unique($files[1]) as $filename) {
            if (!self::validWoff2($directory . '/' . $filename)) {
                return false;
            }
        }

        return self::coverageError($slug, self::parseFaces($css)) === null;
    }

    private static function bundledReady(string $slug): bool
    {
        if (!isset(self::BUNDLED[$slug])) {
            return false;
        }
        $name = self::BUNDLED[$slug];
        $root = self::root();
        $cssPath = $root . '/public/assets/css/' . $name . '.css';
        $css = (string) @file_get_contents($cssPath);
        if ($css === '' || preg_match('/https?:\/\//i', $css)) {
            return false;
        }
        if (self::coverageError($slug, self::parseFaces($css)) !== null) {
            return false;
        }
        preg_match_all("/url\\('\.\.\/fonts\/" . preg_quote($name, '/') . "\/([a-z0-9-]+\\.woff2)'\\)/", $css, $files);
        if ($files[1] === []) {
            return false;
        }
        foreach (array_unique($files[1]) as $filename) {
            if (!self::validWoff2($root . '/public/assets/fonts/' . $name . '/' . $filename)) {
                return false;
            }
        }

        return true;
    }

    private static function validWoff2(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === 'wOF2';
    }

    private static function atomicWrite(string $path, string $contents): bool
    {
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
        @chmod($path, 0644);

        return true;
    }

    private static function root(): string
    {
        return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    }
}
