<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Ранняя подсказка о критических ресурсах (`Link: rel=preload`, HTTP 103).
 *
 * Смысл приёма: пока PHP собирает страницу, канал простаивает. Заголовок
 * `Link` называет браузеру таблицу стилей и файл шрифта до того, как он
 * дочитает `<head>`, а прокси, умеющий 103 Early Hints, отправляет их ещё
 * раньше — не дожидаясь готового ответа.
 *
 * **Чего PHP не может, и об этом надо сказать прямо.** Сам по себе PHP-FPM
 * не отправляет промежуточный ответ 103: заголовки уходят вместе с телом,
 * когда скрипт уже отработал. Поэтому здесь печатаются именно заголовки
 * `Link` готового ответа, а превращает их в 103 тот, кто стоит перед сайтом:
 * Cloudflare (настройка «Early Hints» — он запоминает `Link` с origin и
 * повторяет их следующему посетителю ещё до обращения к нам), Apache с
 * `H2EarlyHints on` или Caddy. Без такого прокси заголовок всё равно не
 * бесполезен: он приходит с первым же байтом ответа, то есть раньше, чем
 * парсер дойдёт до `<link>` в разметке.
 *
 * **Адреса обязаны совпадать с разметкой до символа.** Preload с другим
 * адресом браузер за тот же ресурс не считает и качает файл второй раз,
 * поэтому CSS берётся через `Asset::url()` (с `?v=` и префиксом CDN, как в
 * `<head>`), а шрифты — из `FrontendAssets::bundledFontPreloads()`, то есть
 * из того же списка, что печатает шапка.
 *
 * **Подсказка короткая.** Три ресурса — потолок: каждый лишний preload
 * конкурирует за канал с тем, что рисует первый экран, а заголовки едут в
 * каждом ответе.
 */
final class EarlyHints
{
    public const SETTING = 'perf_early_hints';

    /** Больше трёх подсказок — уже не подсказка, а конкуренция за канал. */
    private const MAX_LINKS = 3;

    /**
     * Машинные ответы: подсказка про CSS и шрифт им не нужна. Остальное
     * отсеивает сам заголовок `Accept` — навигация браузера просит text/html,
     * а запрос картинки или скрипта нет.
     *
     * @var list<string>
     */
    private const NON_DOCUMENT_PATHS = [
        '/sitemap.xml', '/robots.txt', '/manifest.webmanifest', '/rss.xml', '/rss',
    ];

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') === '1';
    }

    /**
     * Значения заголовка `Link` для текущей темы и набора шрифтов.
     *
     * @return list<string>
     */
    public static function links(): array
    {
        $links = [];

        $styles = FrontendAssets::styles();
        $bundle = $styles[0] ?? '';
        if (is_string($bundle) && $bundle !== '') {
            $links[] = '<' . Asset::url($bundle) . '>; rel=preload; as=style';
        }

        foreach (FrontendAssets::bundledFontPreloads() as $fontFile) {
            // crossorigin обязателен даже для своего домена: шрифты грузятся в
            // анонимном режиме, и preload без него считается другим запросом.
            $links[] = '<' . $fontFile . '>; rel=preload; as=font; type="font/woff2"; crossorigin';
        }

        return array_slice($links, 0, self::MAX_LINKS);
    }

    /**
     * Печатает заголовки для публичной навигации.
     *
     * Зовётся до маршрутизации — то есть до сборки страницы: в этом весь
     * смысл, иначе подсказка приезжала бы вместе с готовым ответом.
     */
    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || !self::isDocumentRequest()) {
            return;
        }
        if (!self::enabled()) {
            return;
        }

        foreach (self::links() as $link) {
            header('Link: ' . $link, false);
        }
    }

    /** Запрос за HTML-страницей сайта, а не за файлом и не в служебную область. */
    public static function isDocumentRequest(
        ?string $path = null,
        ?string $method = null,
        ?string $accept = null
    ): bool {
        $method = strtoupper($method ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // Навигация браузера всегда просит text/html; подзапрос за картинкой
        // или скриптом — нет. Это точнее списка расширений и не требует знать
        // маршруты.
        $accept = strtolower($accept ?? (string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (!str_contains($accept, 'text/html')) {
            return false;
        }

        $path = $path ?? (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if (in_array($path, self::NON_DOCUMENT_PATHS, true)) {
            return false;
        }

        // Служебные области грузят свой CSS (у админки он другой), и подсказка
        // про публичный бандл увела бы их канал на ненужный файл. Список тот
        // же, что у общего кеша, — своего второго здесь не заводим.
        foreach (PublicResponseCache::privatePaths() as $private) {
            if ($path === $private || str_starts_with($path, $private . '/')) {
                return false;
            }
        }

        return true;
    }
}
