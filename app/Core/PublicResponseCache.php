<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\Setting;

/** HTTP/CDN-кеширование только общих публичных ответов без сессии. */
final class PublicResponseCache
{
    /** @var list<string> */
    private const PRIVATE_PATHS = [
        '/admin', '/repo', '/install', '/search', '/captcha.png', '/push',
        '/unsubscribe', '/health', '/opendata', '/download.php', '/_vitals',
    ];

    /** @var list<string> */
    private const CONTENT_ADMIN_PATHS = [
        '/admin/pages', '/admin/blocks', '/admin/snippets', '/admin/news',
        '/admin/news-categories',
        '/admin/projects', '/admin/team', '/admin/albums', '/admin/videos',
        '/admin/content', '/admin/content-types', '/admin/menu', '/admin/header',
        '/admin/footer', '/admin/widgets', '/admin/design', '/admin/settings',
        '/admin/performance', '/admin/trash', '/admin/bulk',
        '/admin/settings/demo-content', '/admin/redirects', '/admin/languages',
    ];

    /**
     * Решение apply(): ответ годится в общий кеш. Нужно sendConditional(),
     * чтобы не выдавать ETag там, где ответ приватный или некэшируемый.
     */
    private static bool $cacheable = false;

    /**
     * Годится ли ответ в аварийный снимок. Условие строже, чем у HTTP-кеша:
     * снимок отдаётся ЛЮБОМУ посетителю при лежащей базе, поэтому в него не
     * должны попадать ни персонализированные ответы (настройки отображения,
     * узбекская кириллица — они уходят как `private`), ни адреса с
     * параметрами: ключей стало бы столько же, сколько запросов от сканеров.
     */
    private static bool $snapshotable = false;

    public static function apply(string $template): void
    {
        self::$cacheable = false;
        self::$snapshotable = false;
        if (!str_starts_with($template, 'site/')) {
            return;
        }

        // Ответ, устанавливающий языковое cookie, не должен попасть в общий CDN-кеш.
        if (LocalePreference::changedThisRequest()) {
            header('Cache-Control: private, no-store');
            return;
        }

        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $status = http_response_code();
        $status = is_int($status) && $status > 0 ? $status : 200;
        $sessionActive = session_status() === PHP_SESSION_ACTIVE;
        $hasAuthorization = isset($_SERVER['HTTP_AUTHORIZATION']);
        if (!self::isCacheableRequest(
            $path,
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $sessionActive,
            $status,
            $hasAuthorization,
            Language::activeCodes()
        )) {
            if ($sessionActive || $hasAuthorization) {
                header('Cache-Control: private, no-store');
            }
            return;
        }

        $browserTtl = max(0, min(3600, (int) Setting::get('perf_public_cache_ttl', '60')));
        $sharedTtl = max(0, min(86400, (int) Setting::get('perf_shared_cache_ttl', '300')));
        if ($browserTtl === 0 && $sharedTtl === 0) {
            return;
        }

        // Настройки отображения печатает сервер — атрибутами data-a11y-* на
        // <html> (чтобы страница не мигала «обычная → крупная»). Значит ответ
        // посетителя с высоким контрастом отличается от обычного, и в общем
        // кеше ему не место: следующий посетитель получил бы чужой набор.
        // На практике это видно только без JS (a11y.js переставляет атрибуты
        // из своей cookie), но полагаться на включённый JS в вопросе
        // доступности нельзя — это ровно те посетители, у кого он бывает
        // выключен.
        //
        // «Vary: Cookie» тут не годится: он фрагментирует общий кеш по любому
        // значению любой cookie — метка аналитики сделала бы попадания
        // единичными. Поэтому из общего кеша выпадает только сам такой ответ,
        // а браузеру посетителя кешировать его по-прежнему можно.
        $personalized = A11ySettings::isActive(
            A11ySettings::fromCookie($_COOKIE[A11ySettings::COOKIE] ?? null)
        );
        if ($personalized) {
            header(sprintf('Cache-Control: private, max-age=%d', $browserTtl));
        } else {
            header(sprintf(
                'Cache-Control: public, max-age=%d, s-maxage=%d, stale-while-revalidate=30',
                $browserTtl,
                $sharedTtl
            ));
        }
        // Раньше здесь всегда стоял «Vary: Accept-Encoding, Cookie», потому что
        // Router::resolveLocale() редиректил по сохранённому языку на любом
        // адресе: закешированная копия увела бы посетителя мимо редиректа.
        // Теперь этот редирект работает только на корне сайта (корни ниже
        // исключены из кеширования), поэтому язык страницы определяется её
        // адресом, а не cookie.
        //
        // Ответ с непустыми настройками отображения в общий кеш уже не попал
        // (Cache-Control: private выше), поэтому Vary по ним не нужен.
        // Исключение — узбекская кириллица: её даёт транслитерация готовой
        // страницы на сервере (View::wantsCyrillic), клиент это повторить не
        // может, поэтому узбекские ответы по-прежнему зависят от cookie.
        $varyCookie = Locale::current() === 'uz';
        header('Vary: Accept-Encoding' . ($varyCookie ? ', Cookie' : ''));
        self::$cacheable = true;
        self::$snapshotable = !$personalized
            && ((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '')) === '';
    }

    /**
     * Условный ответ: ETag от готовой страницы и `304` при совпадении.
     *
     * Когда истекает max-age, браузер идёт перепроверять — и сейчас получает
     * всю страницу заново, даже если она не изменилась. С ETag он получает
     * пустой `304`: тело (десятки килобайт) не передаётся вовсе.
     *
     * Хеш считается **от готового тела**, а не от времени файла кэша блоков.
     * Это принципиально: страница с формой получает свежий CSRF-токен на
     * каждый запрос, и по mtime кэша ей бы выдали `304` с чужим токеном.
     * Хеш тела в этом случае просто не совпадёт — форма приедет целиком, как и
     * должна. Никаких списков исключений вести не нужно.
     *
     * Экономится трафик, а не процессор: страница всё равно собирается, чтобы
     * посчитать хеш. Процессор снимает дисковый кэш блоков и общий кеш на CDN.
     *
     * @return bool true — ответ уже отправлен, тело печатать не нужно.
     */
    public static function sendConditional(string $html): bool
    {
        if (!self::$cacheable || headers_sent()) {
            return false;
        }
        if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            return false;
        }

        // 32 шестнадцатеричных знака: коллизия невероятна, а заголовок короче.
        $etag = '"' . substr(hash('sha256', $html), 0, 32) . '"';
        header('ETag: ' . $etag);

        if (!self::etagMatches((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
            return false;
        }

        http_response_code(304);
        header_remove('Content-Type');
        header_remove('Content-Length');

        return true;
    }

    /**
     * Сверка `If-None-Match`. Заголовок содержит список, значения могут быть
     * помечены слабыми (`W/"…"`) — для нашего сравнения это тот же тег.
     */
    public static function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $activeCodes Активные языки; нужны, чтобы
     *        отличить корень языка («/uz») от страницы со схожим slug
     *        («/news»). null — считать корнем только «/».
     */
    public static function isCacheableRequest(
        string $path,
        string $method,
        bool $sessionActive,
        int $status,
        bool $hasAuthorization = false,
        ?array $activeCodes = null
    ): bool {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)
            || $sessionActive
            || $hasAuthorization
            || $status !== 200) {
            return false;
        }

        // Корни языков отдают либо страницу, либо редирект — по cookie.
        if (self::isLanguageRootPath($path, $activeCodes ?? [])) {
            return false;
        }

        if (self::isPrivatePath($path)) {
            return false;
        }

        // Публичные маршруты могут иметь языковой префикс (/uz/search).
        $localizedPath = preg_replace('#^/[a-zA-Z]{2,8}(?=/|$)#', '', $path) ?: '/';
        return !self::isPrivatePath($localizedPath);
    }

    /**
     * Корень сайта и корни языков («/», «/uz», «/uz/»). Только на них
     * Router::resolveLocale() ещё редиректит по сохранённому языку, поэтому
     * ответ зависит от cookie и в общий кеш не годится.
     *
     * Сверяемся со списком активных языков, а не с шаблоном «/xx»: иначе
     * обычная страница со slug из двух-восьми букв (/news, /contacts) тоже
     * считалась бы корнем и потеряла кеширование.
     */
    public static function isLanguageRootPath(string $path, array $activeCodes): bool
    {
        $trimmed = rtrim($path, '/');
        if ($trimmed === '') {
            return true;
        }

        return in_array(strtolower(ltrim($trimmed, '/')), array_map('strtolower', $activeCodes), true);
    }

    /**
     * Приватные и служебные пути: их ответ зависит от сессии, ставит cookie
     * или отдаёт файл. Список читает и `SpeculationRules` — упреждать такие
     * адреса нельзя ровно по тем же причинам, по которым их нельзя класть в
     * общий кеш, и второй такой список разъехался бы с этим при первой
     * правке.
     *
     * @return list<string>
     */
    public static function privatePaths(): array
    {
        return self::PRIVATE_PATHS;
    }

    private static function isPrivatePath(string $path): bool
    {
        foreach (self::PRIVATE_PATHS as $privatePath) {
            if ($path === $privatePath || str_starts_with($path, $privatePath . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Сброс общего кеша после успешной мутации публичного контента. */
    public static function registerContentInvalidation(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $matches = false;
        foreach (self::CONTENT_ADMIN_PATHS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return;
        }

        register_shutdown_function(static function (): void {
            $status = http_response_code();
            if (!is_int($status) || $status < 400) {
                Cache::forgetPrefix('page:');
                // Снимки стираем вместе с кэшем страниц. Иначе снятая с
                // публикации новость могла бы вернуться на глаза во время
                // аварии — для госсайта это хуже, чем 503. Окно без запасной
                // копии короткое: она пересобирается первым же успешным
                // заходом на страницу.
                Cache::forgetPrefix('stale_page:');
            }
        });
    }

    /**
     * Срок жизни аварийного снимка. Неделя — это не «свежесть», а верхняя
     * граница: снимок и так пересобирается при каждом успешном заходе и
     * стирается при правке контента.
     */
    private const STALE_TTL = 604800;

    /**
     * Ключ снимка. Считается из адреса и **одним методом на обе стороны**:
     * сохранение идёт при живой базе, а отдача — при мёртвой, где ни языка,
     * ни настроек из БД уже не спросить. Разъехавшиеся ключи означали бы, что
     * снимок пишется, но никогда не находится, — отказ, который заметен
     * только в аварии, то есть ровно тогда, когда проверять поздно.
     *
     * Язык в ключ отдельно не входит: он и так в адресе (`/uz/news`). Ответы,
     * которые различаются не адресом, а cookie (узбекская кириллица, режимы
     * отображения), в снимок не попадают вовсе — см. $snapshotable.
     */
    private static function staleKey(string $path): string
    {
        return 'stale_page:' . md5($path);
    }

    /** Путь текущего запроса без параметров. */
    private static function requestPath(): string
    {
        return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    }

    /**
     * Сохраняет снимок готовой публичной страницы на случай отказа БД.
     *
     * Вызывается из View::render() после apply(): к этому моменту уже
     * известно, годится ли ответ (200, GET/HEAD, без сессии, не служебный
     * путь, не персонализированный, без параметров адреса).
     */
    public static function saveSnapshot(string $html): void
    {
        if (!self::$snapshotable || $html === '') {
            return;
        }

        // Запись только при изменении: страница отдаётся сотни раз подряд
        // одной и той же, а снимок — это десятки килобайт на каждый такой
        // ответ. Сверка отпечатка стоит чтения шестнадцати байт.
        Cache::putIfChanged(self::staleKey(self::requestPath()), $html, self::STALE_TTL);
    }

    /**
     * Отдаёт вчерашнюю копию страницы, когда база недоступна.
     *
     * Вызывается из bootstrap.php вместо брендированной 503. Ходить в БД
     * здесь нельзя ничем: ни за языком, ни за настройками, — поэтому все
     * проверки опираются только на сам запрос и файловый кеш.
     *
     * Отдаём 200, а не 503: содержимое настоящее, просто не самое свежее —
     * это семантика `stale-if-error`. Мониторинг при этом не обманут:
     * `/health` до сюда не доходит, он отвечает 503 отдельной веткой выше.
     *
     * `no-store` намеренно: пока база лежит, каждый следующий запрос должен
     * снова попробовать её поднять. Иначе после восстановления посетители
     * ещё какое-то время получали бы старую копию из чужого кеша.
     */
    public static function tryServeStale(): bool
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return false;
        }
        if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            return false;
        }
        // Адрес с параметрами в снимок не попадал — искать нечего.
        if (((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '')) !== '') {
            return false;
        }

        $path = self::requestPath();
        if (self::isPrivatePath($path)) {
            return false;
        }

        $html = Cache::get(self::staleKey($path));
        if (!is_string($html) || $html === '') {
            return false;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Cache-Status: STALE-RECOVERED');

        echo $html;

        return true;
    }
}
