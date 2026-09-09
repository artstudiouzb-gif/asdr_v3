<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Единая точка выставления заголовков безопасности для ВСЕХ HTTP-ответов
 * (включая 404/500/503 и брендированный fail-safe в bootstrap). Вызывается
 * максимально рано, до какого-либо вывода.
 *
 * CSP включена и на публичной части, и в панели. Инлайн-скрипты разрешаются
 * только по одноразовому nonce (self::nonce()), 'unsafe-inline' для скриптов
 * не используется нигде. Для стилей 'unsafe-inline' оставлен осознанно:
 * тема-билдер и блоки используют style-атрибуты, которые nonce не покрывает.
 */
final class SecurityHeaders
{
    private static ?string $nonce = null;

    /** @var list<string> внешние источники стилей текущей страницы */
    private static array $pageStyleOrigins = [];

    /** @var list<string> внешние источники скриптов текущей страницы */
    private static array $pageScriptOrigins = [];

    public static function send(?string $path = null): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $path ??= parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');

        // HSTS только по HTTPS (иначе браузер проигнорирует, а на HTTP это
        // может «залочить» разработку на localhost). preload — осознанная
        // опция config: security.hsts_preload (включать после месяца
        // стабильного HTTPS, см. hstspreload.org).
        if (self::isHttps()) {
            header('Strict-Transport-Security: ' . self::hstsValue());
        }

        $isAdminPath = str_starts_with($path, '/admin');
        // Без session/gateway-cookie неизвестный посетитель получает тот же
        // публичный CSP, что и обычная 404. Иначе набор заголовков сам выдавал
        // бы существование скрываемой административной поверхности.
        $hasAdminContext = Session::hasCookie() || AdminEntryGate::hasPresentedCookie();
        $isAdmin = ($isAdminPath && $hasAdminContext) || str_starts_with($path, '/install');
        header('Content-Security-Policy: ' . ($isAdmin
            ? self::adminCsp(self::nonce())
            : self::publicCsp(self::nonce(), self::publicCspOptions())));
    }

    /**
     * Одноразовый nonce текущего запроса для инлайн-скриптов
     * (<script nonce="...">). Генерируется лениво один раз на запрос.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** Значение Strict-Transport-Security с учётом опции preload. */
    public static function hstsValue(?bool $preload = null): string
    {
        $preload ??= (bool) Config::get('security.hsts_preload', false);

        // Требования hstspreload.org: max-age ≥ 1 год, includeSubDomains, preload.
        return $preload
            ? 'max-age=63072000; includeSubDomains; preload'
            : 'max-age=31536000; includeSubDomains';
    }

    /**
     * CSP панели управления и установщика: инлайн-скрипты только по nonce.
     */
    public static function adminCsp(string $nonce): string
    {
        // TinyMCE самохостится (vendor/tinymce) — внешние CDN в CSP не нужны.
        // 'unsafe-inline' для style остаётся: редактор проставляет инлайн-стили.
        return "default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'nonce-{$nonce}'; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'";
    }

    /**
     * CSP публичной части. Инлайн-скрипты (анти-FOUC, счётчики, глобальный JS)
     * — только по nonce; шрифты самохостятся, внешние хосты добавляются лишь
     * для фактически включённых счётчиков.
     *
     * @param array{ga?: bool, ym?: bool, counter_scripts?: list<string>, extra_style?: list<string>, extra_script?: list<string>} $opts
     */
    public static function publicCsp(string $nonce, array $opts = []): string
    {
        // IFrame API загружается лениво только после клика по YouTube-видео.
        // Он нужен, чтобы заменить экран рекомендаций собственной обложкой.
        $script = ["'self'", "'nonce-{$nonce}'", 'https://www.youtube.com', 'https://telegram.org'];
        $connect = ["'self'"];
        $style = ["'self'", "'unsafe-inline'"];
        $font = ["'self'", 'data:'];

        if (!empty($opts['ga'])) {
            $script[] = 'https://www.googletagmanager.com';
            $connect[] = 'https://*.google-analytics.com';
            $connect[] = 'https://*.analytics.google.com';
            $connect[] = 'https://www.googletagmanager.com';
        }
        if (!empty($opts['ym'])) {
            $script[] = 'https://mc.yandex.ru';
            $connect[] = 'https://mc.yandex.ru';
        }
        $allowedCounterScripts = [
            'https://mc.yandex.ru',
            'https://top.mail.ru',
            'https://top-fwz1.mail.ru',
            'https://counter.yadro.ru',
        ];
        foreach ((array) ($opts['counter_scripts'] ?? []) as $source) {
            if (in_array($source, $allowedCounterScripts, true)) {
                $script[] = $source;
                $connect[] = $source;
            }
        }

        // Постраничные CSS/JS с внешнего домена (поля страницы, супер-админ).
        // Без этого браузер молча блокировал бы <link>/<script src>, а редактор
        // видел бы «файл подключён, но не работает».
        foreach (self::normalizeOrigins($opts['extra_style'] ?? []) as $origin) {
            $style[] = $origin;
        }
        foreach (self::normalizeOrigins($opts['extra_script'] ?? []) as $origin) {
            $script[] = $origin;
        }
        $style = array_values(array_unique($style));
        $script = array_values(array_unique($script));

        return "default-src 'self'; "
            // Блоки могут ссылаться на внешние картинки (URL проверяется UrlGuard);
            // пиксели счётчиков тоже сюда попадают.
            . "img-src 'self' data: https:; "
            . 'style-src ' . implode(' ', $style) . '; '
            . 'script-src ' . implode(' ', $script) . '; '
            . 'font-src ' . implode(' ', $font) . '; '
            . 'connect-src ' . implode(' ', $connect) . '; '
            // Видео-hero может указывать на внешний файл.
            . "media-src 'self' https:; "
            // YouTube-эмбеды новостей и карты блока map_point (URL задаёт админ).
            . "frame-src https:; "
            // Service worker webpush-уведомлений.
            . "worker-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'";
    }

    /**
     * Оставляет только пригодные для CSP источники: схема + хост, без пути,
     * подстановок и портов-мусора. Всё остальное молча отбрасывается.
     *
     * @param mixed $origins
     * @return list<string>
     */
    private static function normalizeOrigins(mixed $origins): array
    {
        $result = [];
        foreach ((array) $origins as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '' || preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', $origin) !== 1) {
                continue;
            }
            $result[] = $origin;
        }

        return array_values(array_unique($result));
    }

    /**
     * Дополняет CSP текущего ответа источниками постраничных CSS/JS.
     *
     * Заголовок в bootstrap выставляется до маршрутизации, когда страница ещё
     * неизвестна. Вьюхи рендерятся в буфер (View::render), поэтому здесь ещё
     * можно переслать заголовок — header() заменяет прежнее значение.
     *
     * @param list<string> $styleOrigins
     * @param list<string> $scriptOrigins
     */
    public static function allowPageAssets(array $styleOrigins, array $scriptOrigins): void
    {
        $style = self::normalizeOrigins($styleOrigins);
        $script = self::normalizeOrigins($scriptOrigins);
        if ($style === [] && $script === []) {
            return;
        }

        self::$pageStyleOrigins = array_values(array_unique([...self::$pageStyleOrigins, ...$style]));
        self::$pageScriptOrigins = array_values(array_unique([...self::$pageScriptOrigins, ...$script]));

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        // Предпросмотр страницы рендерит те же шаблоны под /admin, где действует
        // более строгая CSP панели. Подменять её публичной нельзя — внешний
        // ассет там просто не покажется, и это правильнее ослабления админки.
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/install')) {
            return;
        }

        $opts = self::publicCspOptions();
        $opts['extra_style'] = self::$pageStyleOrigins;
        $opts['extra_script'] = self::$pageScriptOrigins;
        header('Content-Security-Policy: ' . self::publicCsp(self::nonce(), $opts));
    }

    /**
     * Опции публичной CSP из настроек. БД может быть недоступна (503 в
     * bootstrap) — тогда консервативный набор без внешних хостов.
     *
     * @return array{ga: bool, ym: bool, counter_scripts: list<string>}
     */
    private static function publicCspOptions(): array
    {
        try {
            return [
                'ga' => Analytics::gaId() !== '',
                'ym' => Analytics::ymId() !== '',
                'counter_scripts' => self::footerCounterScriptSources(),
            ];
        } catch (\Throwable) {
            return ['ga' => false, 'ym' => false, 'counter_scripts' => []];
        }
    }

    /** @return list<string> */
    private static function footerCounterScriptSources(): array
    {
        $code = (string) \App\Models\Setting::get('footer_counters', '');
        $sources = [
            'https://mc.yandex.ru',
            'https://top.mail.ru',
            'https://top-fwz1.mail.ru',
            'https://counter.yadro.ru',
        ];

        return array_values(array_filter(
            $sources,
            static fn (string $source): bool => str_contains($code, substr($source, 8))
        ));
    }

    /**
     * Добавляет nonce всем <script>-тегам без него в готовом доверенном HTML.
     * Нужен для закэшированных виджетов и legacy-фрагментов: HTML в кэше
     * общий, а nonce — на запрос. Обычные блоки script не пропускают.
     */
    public static function injectScriptNonce(string $html, ?string $nonce = null): string
    {
        if (stripos($html, '<script') === false) {
            return $html;
        }
        $nonce ??= self::nonce();

        return (string) preg_replace(
            '/<script\b(?![^>]*\bnonce=)/i',
            '<script nonce="' . $nonce . '"',
            $html
        );
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }
}
