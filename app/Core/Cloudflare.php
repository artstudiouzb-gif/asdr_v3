<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Интеграция с Cloudflare CDN: очистка кэша по API при изменении контента
 * и определение реального IP посетителя (CF-Connecting-IP).
 *
 * Настройки (Производительность → Cloudflare):
 *   cf_enabled   — '1'/'0'
 *   cf_api_token — API-токен с правом «Zone · Cache Purge» (этого хватает;
 *                  «Zone · Zone · Read» необязательно — с ним проверка связи
 *                  показывает ещё и название зоны)
 *   cf_zone_id   — идентификатор зоны сайта
 *   cf_real_ip   — доверять заголовку CF-Connecting-IP ('1'/'0')
 */
final class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** Чистка кэша выполняется не чаще одного раза за запрос. */
    private static bool $purgedThisRequest = false;

    /**
     * Причина последнего отказа API.
     *
     * Очистка отвечает «получилось/не получилось», а почему — знал только
     * журнал. На shared-хостинге до `storage/logs/error.log` владелец обычно
     * не доходит, и сообщение «Cloudflare: ошибка очистки» не оставляло ему
     * ничего, кроме догадок. Тот же случай, что и с проверкой связи: причина
     * у нас есть, надо её донести.
     */
    private static string $lastError = '';

    public static function enabled(): bool
    {
        return Setting::get('cf_enabled', '0') === '1'
            && self::token() !== ''
            && self::zone() !== '';
    }

    public static function token(): string
    {
        return self::normalizeToken((string) Setting::get('cf_api_token', ''));
    }

    /**
     * Чистка значения токена.
     *
     * `trim()` снимает обычные пробелы, но скопированный из панели Cloudflare
     * токен приносит с собой неразрывный пробел или нулевой ширины — их видно
     * не бывает, а заголовок с таким байтом Cloudflare отвергает целиком:
     * «Invalid request headers». Отказ читается как «токен неверный», хотя сам
     * токен верный, и починить его вслепую нечем. Поэтому пробелы любого рода
     * вырезаются: внутри токена их не бывает по формату.
     */
    public static function normalizeToken(string $raw): string
    {
        $clean = preg_replace('/[\s\x{00A0}\x{180E}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $raw);

        return is_string($clean) ? $clean : trim($raw);
    }

    /**
     * Похоже ли значение на API-токен, а не на Global API Key.
     *
     * Это две разные вещи, и путают их постоянно: токен (40 знаков из букв,
     * цифр, `_` и `-`) отправляется как `Authorization: Bearer`, а глобальный
     * ключ (37 шестнадцатеричных знаков) — только парой заголовков
     * `X-Auth-Email` + `X-Auth-Key`. Глобальный ключ в Bearer Cloudflare не
     * разбирает и отвечает про заголовки, ни словом не упоминая, что дело в
     * типе ключа.
     */
    public static function looksLikeToken(string $token): bool
    {
        // Одного алфавита мало: шестнадцатеричные знаки — его подмножество, и
        // под «буквы и цифры» подходят и Global API Key (37 знаков), и Zone ID
        // (32). Оба попадают в это поле чаще, чем сам токен, поэтому две самые
        // частые подмены названы по форме. Настоящий токен длиной 40 состоял
        // бы из одних hex-знаков с вероятностью, которой можно пренебречь.
        if (preg_match('/^[a-f0-9]{32}$/i', $token) === 1 || preg_match('/^[a-f0-9]{37}$/i', $token) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]{30,120}$/', $token) === 1;
    }

    public static function zone(): string
    {
        return trim((string) Setting::get('cf_zone_id', ''));
    }

    /**
     * Очистить весь кэш зоны. Безопасна: ошибки логируются, исключения не
     * пробрасываются (очистка кэша не должна ронять запрос).
     */
    public static function purgeEverything(): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $res = Http::request(
            'POST',
            self::API . '/zones/' . rawurlencode(self::zone()) . '/purge_cache',
            (string) json_encode(['purge_everything' => true]),
            self::authHeaders(),
            15
        );

        return self::ok($res, 'purge_everything');
    }

    /**
     * Очистить кэш конкретных URL (до 30 за раз — ограничение API).
     *
     * @param list<string> $urls
     */
    public static function purgeUrls(array $urls): bool
    {
        $urls = array_values(array_filter(array_map('trim', $urls), static fn (string $u): bool => $u !== ''));
        if ($urls === []) {
            return false;
        }
        if (!self::enabled()) {
            return false;
        }

        $ok = true;
        foreach (array_chunk($urls, 30) as $chunk) {
            $res = Http::request(
                'POST',
                self::API . '/zones/' . rawurlencode(self::zone()) . '/purge_cache',
                (string) json_encode(['files' => $chunk]),
                self::authHeaders(),
                15
            );
            $ok = self::ok($res, 'purge_files') && $ok;
        }

        return $ok;
    }

    /**
     * Очистить кэш сайта при изменении контента — но не чаще раза за запрос,
     * чтобы серия правок не порождала лавину вызовов API.
     */
    public static function purgeSite(): void
    {
        if (self::$purgedThisRequest) {
            return;
        }
        try {
            if (!self::enabled()) {
                return;
            }
            self::$purgedThisRequest = true;
            self::purgeEverything();
        } catch (\Throwable $e) {
            // Очистка кэша не должна ронять сохранение контента.
            self::$purgedThisRequest = true;
        }
    }

    /**
     * Проверка подключения — разбор по шагам, а не одно «получилось».
     *
     * Прежде она спрашивала только сведения о зоне, и это оказалось худшим из
     * возможных вопросов: чтение зоны требует права Zone:Read, которого
     * интеграции не нужно, а очистку — то единственное, ради чего токен и
     * заводится, — не проверяло вовсе. Отсюда тупик: проверка отвечала
     * «Подключено к зоне asdr.uz», очистка тут же отказывала, и понять,
     * какое из трёх звеньев не работает, было нечем.
     *
     * Теперь спрашиваем все три и называем каждое: сам токен, доступ к зоне
     * и очистку. Очистка идёт по несуществующему адресу — ничего не удаляет,
     * но проходит те же проверки, что и рабочий сброс.
     *
     * @return array{ok: bool, message: string}
     */
    public static function verify(): array
    {
        if (self::token() === '' || self::zone() === '') {
            return ['ok' => false, 'message' => 'Укажите API-токен и Zone ID.'];
        }
        if (!self::looksLikeToken(self::token())) {
            return [
                'ok' => false,
                'message' => 'Значение не похоже на API-токен Cloudflare. Нужен токен из «My Profile → '
                    . 'API Tokens» с правом Zone.Cache Purge (40 знаков: буквы, цифры, «_» и «-»), '
                    . 'а не Global API Key и не Zone ID.',
            ];
        }

        $parts = [];

        // 1. Сам токен: активен ли он и не отозван ли. На этот вопрос отвечает
        //    любой токен независимо от прав — поэтому шаг и первый.
        $token = self::probe('GET', '/user/tokens/verify');
        $parts[] = 'токен — ' . ($token['ok'] ? 'активен' : $token['text']);

        // 2. Доступ к зоне. Для очистки это право не обязательно, поэтому его
        //    отказ не делает проверку неуспешной. Но сама запись зоны отвечает
        //    на вопросы, которых мы прежде не задавали: чья это зона, какого
        //    она типа и не ведёт ли её партнёр. Отказ очистки при живом токене
        //    объясняется чаще всего именно этим, а гадать вместо чтения ответа
        //    мы уже пробовали.
        $zone = self::probe('GET', '/zones/' . rawurlencode(self::zone()));
        $facts = self::zoneFacts($zone['data']);
        $parts[] = $zone['ok']
            ? 'зона — ' . self::zoneSummary($facts)
            : 'чтение зоны — ' . $zone['text'] . ' (для очистки это право не требуется)';

        // 3. Очистка. Ради неё всё и затевалось, поэтому успех проверки решают
        //    этот шаг и первый, а не чтение зоны.
        $purge = self::probePurge($facts['name']);
        $parts[] = 'очистка — ' . ($purge['ok'] ? 'работает' : $purge['text'] . self::rawTail($purge['raw']));

        // Адрес сайта и зона обязаны быть одним доменом. Иначе очистка честно
        // отработает — и не затронет ни одной страницы сайта: кэш чистится у
        // той зоны, чей номер записан, а сайт лежит в другой. Проверить это
        // стоит дешевле, чем однажды искать причину «кэш не сбрасывается».
        $mismatch = self::siteHostMismatch((string) Config::get('app.url', ''), $facts['name']);
        if ($mismatch !== '') {
            $parts[] = $mismatch;
        }

        $ok = $token['ok'] && $purge['ok'] && $mismatch === '';

        return [
            'ok' => $ok,
            'message' => ($ok ? 'Cloudflare готов. ' : 'Cloudflare: ') . implode('; ', $parts) . '.',
        ];
    }

    /**
     * Разбор записи зоны на то, что объясняет отказ очистки.
     *
     * @param mixed $data разобранный ответ `GET /zones/{id}`
     * @return array{name: string, type: string, status: string, paused: bool, partner: string, account: string}
     */
    public static function zoneFacts(mixed $data): array
    {
        $result = is_array($data) && is_array($data['result'] ?? null) ? $data['result'] : [];
        $host = is_array($result['host'] ?? null) ? $result['host'] : [];
        $account = is_array($result['account'] ?? null) ? $result['account'] : [];

        return [
            'name' => (string) ($result['name'] ?? ''),
            'type' => (string) ($result['type'] ?? ''),
            'status' => (string) ($result['status'] ?? ''),
            'paused' => (bool) ($result['paused'] ?? false),
            'partner' => (string) ($host['name'] ?? ''),
            'account' => (string) ($account['name'] ?? ''),
        ];
    }

    /**
     * Короткая сводка по зоне для сообщения проверки.
     *
     * @param array{name: string, type: string, status: string, paused: bool, partner: string, account: string} $facts
     */
    public static function zoneSummary(array $facts): string
    {
        $out = $facts['name'] !== '' ? $facts['name'] : 'доступна';
        $notes = [];
        if ($facts['account'] !== '') {
            // Токен не может больше, чем его хозяин: если зона лежит в чужом
            // аккаунте, где у владельца токена урезанная роль, Cloudflare
            // молча срежет право очистки, как бы оно ни было записано в самом
            // токене. Название аккаунта — единственное, по чему это видно.
            $notes[] = 'аккаунт «' . $facts['account'] . '»';
        }
        if ($facts['partner'] !== '') {
            $notes[] = 'ведёт партнёр ' . $facts['partner'];
        }
        if ($facts['type'] === 'partial') {
            $notes[] = 'подключение CNAME';
        }
        if ($facts['status'] !== '' && $facts['status'] !== 'active') {
            $notes[] = 'состояние ' . $facts['status'];
        }
        if ($facts['paused']) {
            $notes[] = 'проксирование выключено';
        }

        return $notes === [] ? $out : $out . ' (' . implode(', ', $notes) . ')';
    }

    /**
     * Совпадает ли адрес сайта с зоной, чей номер записан в настройках.
     *
     * Пустой ответ — расхождения нет (или сравнивать не с чем).
     */
    public static function siteHostMismatch(string $appUrl, string $zoneName): string
    {
        $zoneName = strtolower(trim($zoneName));
        $host = strtolower((string) parse_url(trim($appUrl), PHP_URL_HOST));
        if ($zoneName === '' || $host === '') {
            return '';
        }
        if ($host === $zoneName || str_ends_with($host, '.' . $zoneName)) {
            return '';
        }

        return 'внимание: сайт работает на ' . $host . ', а Zone ID указывает на зону ' . $zoneName
            . ' — очистка кэша не затронет сайт. Возьмите Zone ID домена ' . $host;
    }

    /**
     * Ответ Cloudflare как есть — коротким хвостом к разобранному сообщению.
     *
     * Разбор ответа — это пересказ, а пересказ уже дважды увёл диагноз не туда.
     * Поэтому рядом с объяснением печатается и сам ответ: он короткий, а спорить
     * с ним нельзя.
     */
    private static function rawTail(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return '';
        }
        if (mb_strlen($raw) > 300) {
            $raw = mb_substr($raw, 0, 300) . '…';
        }

        return ' [ответ Cloudflare: ' . $raw . ']';
    }

    /**
     * Один шаг проверки.
     *
     * @return array{ok: bool, text: string, data: mixed, raw: string}
     */
    private static function probe(string $method, string $path, string $body = ''): array
    {
        $res = Http::request($method, self::API . $path, $body, self::authHeaders(), 15);
        if (($res['error'] ?? '') !== '') {
            return ['ok' => false, 'text' => 'сеть: ' . $res['error'], 'data' => null, 'raw' => ''];
        }

        $raw = (string) ($res['body'] ?? '');
        $data = json_decode($raw, true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            return ['ok' => true, 'text' => '', 'data' => $data, 'raw' => $raw];
        }

        return [
            'ok' => false,
            'text' => self::errorText($data, (int) ($res['status'] ?? 0)),
            'data' => $data,
            'raw' => $raw,
        ];
    }

    /**
     * Пробная очистка по несуществующему адресу.
     *
     * Сбрасывать весь кэш ради проверки нельзя — это настоящее действие с
     * последствиями (холодная кромка для всех посетителей сразу). Очистка
     * одного адреса, которого на сайте нет, не удаляет ничего, но проходит те
     * же проверки: токен, право Cache Purge и номер зоны.
     *
     * Адрес берётся у самой зоны, а не у сайта: очистка по адресу, чей домен
     * зоне не принадлежит, отвергается независимо от прав токена — и отказ
     * читался бы как нехватка права, которого на самом деле хватает.
     *
     * @return array{ok: bool, text: string, data: mixed, raw: string}
     */
    private static function probePurge(string $zoneName = ''): array
    {
        $base = $zoneName !== ''
            ? 'https://' . $zoneName
            : rtrim((string) Config::get('app.url', ''), '/');
        if ($base === '') {
            return ['ok' => false, 'text' => 'не задан адрес сайта (app.url)', 'data' => null, 'raw' => ''];
        }

        return self::probe(
            'POST',
            '/zones/' . rawurlencode(self::zone()) . '/purge_cache',
            (string) json_encode(
                ['files' => [$base . '/__cf-verify-' . bin2hex(random_bytes(4))]],
                JSON_THROW_ON_ERROR
            )
        );
    }


    /**
     * Человеческая причина отказа из ответа API.
     *
     * Cloudflare кладёт подробность не в `errors[].message`, а в `error_chain`:
     * верхняя строка говорит «Invalid request headers» и не сообщает ничего —
     * ровно это и видел владелец. Читаем обе, а для кодов про заголовки
     * добавляем то, чего в ответе нет вовсе: чем именно токен не подошёл.
     *
     * @param mixed $data разобранный JSON ответа
     */
    private static function errorText(mixed $data, int $status): string
    {
        if (!is_array($data) || !isset($data['errors'][0]) || !is_array($data['errors'][0])) {
            return 'HTTP ' . $status;
        }

        $error = $data['errors'][0];
        $msg = (string) ($error['message'] ?? ('HTTP ' . $status));
        $chain = $error['error_chain'][0]['message'] ?? '';
        if (is_string($chain) && $chain !== '') {
            $msg .= ' — ' . $chain;
        }
        // Код печатаем рядом с текстом: одна и та же фраза приходит с разными
        // кодами, и без него причину приходится угадывать по формулировке.
        $code = (int) ($error['code'] ?? 0);
        if ($code !== 0) {
            $msg .= ' (код ' . $code . ')';
        }

        // 6003 — «Invalid request headers»: заголовок авторизации не разобран.
        // Причина почти всегда одна из двух, и обе не видны в ответе.
        if ($code === 6003) {
            $msg .= '. Проверьте, что в поле вставлен API-токен (Zone.Cache Purge), а не Global API Key,'
                . ' и скопирован он без лишних символов.';
        }
        // Отказ прав. Формулировки замерены на боевом сайте, а не угаданы:
        // «Unable to purge. Unauthorized.» приходит, когда токен узнан, но
        // права Cache Purge у него нет, «Authentication error» — когда токен
        // не принят вовсе. Ни то, ни другое не называет ни права, ни места,
        // где его выдать, поэтому объясняем сами. Первая догадка была написана
        // на текст, которого Cloudflare не присылает («requires permission»),
        // и подсказка не срабатывала ни разу.
        foreach (['Unable to purge', 'Unauthorized', 'Authentication error', 'cache.purge', 'requires permission'] as $needle) {
            if (str_contains($msg, $needle)) {
                $msg .= '. Причин три. (1) У токена нет права Zone · Cache Purge · Purge на эту зону'
                    . ' (My Profile → API Tokens → Edit; чтения зоны для очистки недостаточно, поэтому'
                    . ' зона читается, а очистка нет). (2) Токен не может больше своего хозяина: если зона'
                    . ' лежит в чужом аккаунте, где у вас урезанная роль, право очистки срезается молча,'
                    . ' как бы оно ни было записано в токене — сверьте название аккаунта выше и свою роль'
                    . ' в нём (Manage Account → Members). (3) Зону ведёт партнёр (хостинг, регистратор):'
                    . ' такие зоны очистку из своего аккаунта не разрешают, сбрасывать кэш придётся у него.';
                break;
            }
        }

        return $msg;
    }

    /** @return array<int, string> */
    private static function authHeaders(): array
    {
        return [
            'Authorization: Bearer ' . self::token(),
            'Content-Type: application/json',
        ];
    }

    /** Причина последнего отказа очистки — пустая строка, если её не было. */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * @param array{status?: int, body?: string, error?: string} $res
     */
    private static function ok(array $res, string $op): bool
    {
        if (($res['error'] ?? '') !== '') {
            self::$lastError = 'сеть: ' . $res['error'];
            Logger::warning('Cloudflare ' . $op . ' сеть: ' . $res['error']);
            IntegrationStatus::fail('cloudflare', self::$lastError, $op);
            return false;
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            self::$lastError = '';
            IntegrationStatus::ok('cloudflare', $op);
            return true;
        }
        self::$lastError = self::errorText($data, (int) ($res['status'] ?? 0));
        Logger::warning('Cloudflare ' . $op . ' ошибка: ' . self::$lastError);
        IntegrationStatus::fail('cloudflare', self::$lastError, $op);

        return false;
    }
}
