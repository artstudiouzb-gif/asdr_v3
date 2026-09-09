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
     * Проверка подключения: запрашиваем детали зоны.
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

        $res = Http::request(
            'GET',
            self::API . '/zones/' . rawurlencode(self::zone()),
            '',
            self::authHeaders(),
            15
        );

        if (($res['error'] ?? '') !== '') {
            return ['ok' => false, 'message' => 'Сеть: ' . $res['error']];
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            $name = (string) ($data['result']['name'] ?? '');
            return ['ok' => true, 'message' => 'Подключено к зоне' . ($name !== '' ? ': ' . $name : '') . '.'];
        }

        // Отказ авторизации здесь ещё ничего не говорит о токене: сведения о
        // зоне требуют права Zone:Read, а интеграции нужен только Cache Purge.
        // Токен, выданный ровно под задачу, спотыкался о нашу же проверку и
        // выглядел негодным — поэтому спрашиваем то, ради чего он и заведён.
        if (in_array((int) ($res['status'] ?? 0), [401, 403], true)) {
            return self::verifyByPurge();
        }

        return ['ok' => false, 'message' => 'Cloudflare: ' . self::errorText($data, (int) ($res['status'] ?? 0))];
    }

    /**
     * Запасная проверка для токена с одним правом Cache Purge.
     *
     * Сбрасываем кэш одного адреса, которого на сайте нет: очистка
     * несуществующего пути ничего не удаляет, но проходит ровно те же
     * проверки, что и рабочий сброс, — токен, право и номер зоны. Проверять
     * возможность обходным путём (`/user/tokens/verify`) смысла нет: она
     * подтверждает сам токен и молчит про зону и право.
     *
     * @return array{ok: bool, message: string}
     */
    private static function verifyByPurge(): array
    {
        // Адрес сайта — из конфигурации, как и везде: HTTP_HOST подделывается
        // заголовком, а сброс пошёл бы по чужой зоне.
        $probe = rtrim((string) Config::get('app.url', ''), '/');
        if ($probe === '') {
            return ['ok' => false, 'message' => 'Не задан адрес сайта (app.url) — проверять нечего.'];
        }
        $probe .= '/__cf-verify-' . bin2hex(random_bytes(4));

        $res = Http::request(
            'POST',
            self::API . '/zones/' . rawurlencode(self::zone()) . '/purge_cache',
            (string) json_encode(['files' => [$probe]], JSON_THROW_ON_ERROR),
            self::authHeaders(),
            15
        );

        if (($res['error'] ?? '') !== '') {
            return ['ok' => false, 'message' => 'Сеть: ' . $res['error']];
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            return [
                'ok' => true,
                'message' => 'Очистка кэша доступна. Название зоны не показано: у токена нет права '
                    . 'Zone · Zone · Read, и для работы интеграции оно не нужно.',
            ];
        }

        return ['ok' => false, 'message' => 'Cloudflare: ' . self::errorText($data, (int) ($res['status'] ?? 0))];
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
                $msg .= '. Причин две: у токена нет права Zone · Cache Purge · Purge на эту зону'
                    . ' (My Profile → API Tokens → Edit; чтения зоны для очистки недостаточно, поэтому'
                    . ' проверка связи может проходить, а очистка нет) — либо зона подключена через'
                    . ' партнёра и не разрешает очистку всего кэша разом.';
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
            return false;
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            self::$lastError = '';
            return true;
        }
        self::$lastError = self::errorText($data, (int) ($res['status'] ?? 0));
        Logger::warning('Cloudflare ' . $op . ' ошибка: ' . self::$lastError);

        return false;
    }
}
