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
 * - **Публичный рендер в сеть не ходит** (тест 110): отправка живёт в воркере
 *   по cron, `app/Console/indexnow_worker.php`. Нет строки в crontab — нет и
 *   отправок, настройки для этого не нужно.
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

    private const KEY_SETTING = 'indexnow_key';
    private const SENT_SETTING = 'indexnow_last_sent';

    /**
     * Первый проход не пересылает весь сайт: поисковики знают его по карте,
     * а уведомление о тысячах давно проиндексированных адресов протокол
     * считает злоупотреблением. Два дня — окно, в котором правка ещё новость.
     */
    private const FIRST_RUN_WINDOW = 2 * 86400;

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
     * Адреса карты сайта, изменённые позже отметки.
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
            if ($ts !== false && $ts > $since) {
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
    public static function run(?int $now = null): array
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

        $res = Http::postJson(self::ENDPOINT, self::payload($base, $key, $urls), [], 20);
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
