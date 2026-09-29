<?php

declare(strict_types=1);

namespace App\Core\Seo;

use App\Controllers\Site\SitemapController;
use App\Core\AppUrl;
use App\Core\Http;
use App\Core\IntegrationStatus;
use App\Models\Setting;

/**
 * IndexNow: сайт сам сообщает поисковикам, что адрес появился или изменился.
 *
 * Без него о свежей новости поисковик узнаёт, когда в следующий раз придёт за
 * картой сайта, — у государственного сайта это часы, у малопосещаемого сутки.
 * IndexNow — открытый протокол Яндекса и Bing (его же читают Naver, Seznam и
 * Yep): одно уведомление на общий адрес расходится всем участникам. Google
 * протокол не поддерживает, ему хватает карты сайта с честным lastmod.
 *
 * Правила, из которых выведено остальное:
 * - **Список адресов берётся из карты сайта**, а не собирается второй раз:
 *   она уже знает каждую языковую версию, пропускает адреса с редиректом и
 *   несёт дату последней правки. Уведомляем о тех адресах, чья правка новее
 *   прошлой отправки, — тот же приём, по которому поисковик сам сравнивает
 *   lastmod.
 * - **Отметка сдвигается только после принятой отправки.** Отказ сети или
 *   ответ 4xx/5xx оставляет её на месте, и следующий проход повторит те же
 *   адреса; исход виден в «Состоянии системы» (память интеграций).
 * - **Публичный рендер в сеть не ходит** (тест 110). Отправка идёт после
 *   ответа на сохранение в админке (afterResponse) и, если есть строка в
 *   crontab, воркером `app/Console/indexnow_worker.php` — он добирает
 *   отложенные публикации, которые наступают без сохранения.
 * - **Ключ — не секрет**, его по протоколу отдают файлом (`/indexnow.txt`):
 *   он лишь доказывает, что уведомление прислал владелец домена.
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Адрес файла с ключом; от него же отсчитывается область адресов. */
    public const KEY_PATH = '/indexnow.txt';

    /** Предел протокола на одно уведомление. */
    public const MAX_URLS = 10000;

    /**
     * Сколько ждём ответа в проходе после сохранения. Под PHP-FPM и LiteSpeed
     * ответ редактору к этому моменту уже отдан, но под mod_php ожидание
     * прибавляется к его запросу — поэтому короче, чем у воркера.
     */
    private const AFTER_RESPONSE_TIMEOUT = 5;

    private static bool $scheduled = false;

    private const KEY_SETTING = 'indexnow_key';
    private const SENT_SETTING = 'indexnow_last_sent';

    /**
     * Первый проход не пересылает весь сайт: поисковики знают его по карте,
     * а уведомление о тысячах давно проиндексированных адресов протокол
     * считает злоупотреблением. Два дня — окно, в котором правка ещё новость.
     */
    private const FIRST_RUN_WINDOW = 2 * 86400;

    /**
     * Уведомить после ответа: зовёт Cache::forgetPrefix('page:'), то есть
     * любое сохранение контента, опубликованного на сайте. Так IndexNow
     * работает и без cron: правка в админке сама сообщает поисковикам.
     * Воркер остаётся для того, чего правка не видит, — отложенной публикации,
     * наступившей без сохранения.
     *
     * Только из веб-запроса: консольные сценарии (импорт, тесты) проходят
     * через ту же точку, и слать из них наружу незачем — у воркера свой вызов.
     */
    public static function afterResponse(): void
    {
        if (self::$scheduled || PHP_SAPI === 'cli') {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function([self::class, 'flush']);
    }

    /** @internal вызывается из register_shutdown_function */
    public static function flush(): void
    {
        self::$scheduled = false;
        if (!self::isPublicBase(AppUrl::base())) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        try {
            self::run(null, self::AFTER_RESPONSE_TIMEOUT);
        } catch (\Throwable $e) {
            \App\Core\Logger::swallowed('IndexNow: уведомление после сохранения не отправлено', $e);
        }
    }

    public static function key(): string
    {
        $key = trim(Setting::get(self::KEY_SETTING, ''));

        return self::isValidKey($key) ? $key : '';
    }

    public static function ensureKey(): string
    {
        $key = self::key();
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            Setting::set(self::KEY_SETTING, $key);
        }

        return $key;
    }

    /** Формат ключа по протоколу: 8–128 знаков из латиницы, цифр и дефиса. */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key) === 1;
    }

    /**
     * Адрес годится для уведомлений, только если он публичный и по https:
     * localhost, IP и служебные зоны протокол отвергает, а слать с них — это
     * сообщать поисковику о сайте разработчика.
     */
    public static function isPublicBase(string $base): bool
    {
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || !str_contains($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return preg_match('/\.(local|localhost|test|example|invalid|internal)$/', $host) !== 1;
    }

    /**
     * Адреса карты сайта, изменённые не раньше отметки.
     *
     * @return list<string>
     */
    public static function changedUrls(string $sitemapXml, int $since): array
    {
        $doc = @simplexml_load_string($sitemapXml);
        if ($doc === false) {
            return [];
        }

        $urls = [];
        foreach ($doc->url as $node) {
            $loc = trim((string) $node->loc);
            $lastmod = trim((string) $node->lastmod);
            if ($loc === '' || $lastmod === '') {
                continue;
            }
            $ts = strtotime($lastmod);
            // Не строго: правка в ту же секунду, что прошлая отправка, иначе
            // потерялась бы. Повтор адреса протокол допускает.
            if ($ts !== false && $ts >= $since) {
                $urls[] = $loc;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
    }

    /**
     * Тело уведомления по протоколу.
     *
     * @param list<string> $urls
     * @return array{host: string, key: string, keyLocation: string, urlList: list<string>}
     */
    public static function payload(string $base, string $key, array $urls): array
    {
        return [
            'host' => (string) parse_url($base, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => rtrim($base, '/') . self::KEY_PATH,
            'urlList' => $urls,
        ];
    }

    /**
     * Один проход воркера.
     *
     * @return array{status: string, sent: int, message: string}
     */
    public static function run(?int $now = null, int $timeout = 20): array
    {
        $now ??= time();
        $base = AppUrl::base();
        if (!self::isPublicBase($base)) {
            return ['status' => 'skipped', 'sent' => 0, 'message' => 'адрес сайта (app.url) не публичный https — уведомлять не о чем'];
        }

        $key = self::ensureKey();
        $lastSent = (int) Setting::get(self::SENT_SETTING, '0');
        $since = $lastSent > 0 ? $lastSent : $now - self::FIRST_RUN_WINDOW;

        $urls = self::changedUrls(SitemapController::sitemapXml($base), $since);
        if ($urls === []) {
            Setting::set(self::SENT_SETTING, (string) $now);

            return ['status' => 'idle', 'sent' => 0, 'message' => 'изменённых адресов нет'];
        }

        $res = Http::postJson(self::ENDPOINT, self::payload($base, $key, $urls), [], $timeout);
        $status = (int) $res['status'];
        // 200 — принято, 202 — принято, ключ ещё проверяется.
        if ($status === 200 || $status === 202) {
            Setting::set(self::SENT_SETTING, (string) $now);
            IntegrationStatus::ok('indexnow', 'уведомление об адресах');

            return ['status' => 'sent', 'sent' => count($urls), 'message' => 'принято: ' . count($urls) . ' адр.'];
        }

        $error = $res['error'] !== '' ? $res['error'] : 'HTTP ' . $status . ' ' . self::explain($status);
        IntegrationStatus::fail('indexnow', $error, 'уведомление об адресах');

        return ['status' => 'failed', 'sent' => 0, 'message' => $error];
    }

    /** Коды ответа протокола словами: владелец читает их в панели. */
    private static function explain(int $status): string
    {
        return match ($status) {
            400 => '(неверный формат запроса)',
            403 => '(ключ не подтверждён: файл ' . self::KEY_PATH . ' не отдаётся или не совпадает)',
            422 => '(адреса не принадлежат домену ключа)',
            429 => '(слишком частые уведомления)',
            default => '',
        };
    }
}
