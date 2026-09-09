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
 *   cf_api_token — API-токен с правом «Zone.Cache Purge»
 *   cf_zone_id   — идентификатор зоны сайта
 *   cf_real_ip   — доверять заголовку CF-Connecting-IP ('1'/'0')
 */
final class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** Чистка кэша выполняется не чаще одного раза за запрос. */
    private static bool $purgedThisRequest = false;

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

        // 6003 — «Invalid request headers»: заголовок авторизации не разобран.
        // Причина почти всегда одна из двух, и обе не видны в ответе.
        if ((int) ($error['code'] ?? 0) === 6003) {
            $msg .= '. Проверьте, что в поле вставлен API-токен (Zone.Cache Purge), а не Global API Key,'
                . ' и скопирован он без лишних символов.';
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

    /**
     * @param array{status?: int, body?: string, error?: string} $res
     */
    private static function ok(array $res, string $op): bool
    {
        if (($res['error'] ?? '') !== '') {
            Logger::warning('Cloudflare ' . $op . ' сеть: ' . $res['error']);
            return false;
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($data) && !empty($data['success'])) {
            return true;
        }
        Logger::warning('Cloudflare ' . $op . ' ошибка: ' . self::errorText($data, (int) ($res['status'] ?? 0)));

        return false;
    }
}
