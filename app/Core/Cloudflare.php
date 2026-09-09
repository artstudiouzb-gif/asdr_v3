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
        return trim((string) Setting::get('cf_api_token', ''));
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

        $msg = is_array($data) && isset($data['errors'][0]['message'])
            ? (string) $data['errors'][0]['message']
            : ('HTTP ' . (int) ($res['status'] ?? 0));

        return ['ok' => false, 'message' => 'Cloudflare: ' . $msg];
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
        $msg = is_array($data) && isset($data['errors'][0]['message'])
            ? (string) $data['errors'][0]['message']
            : ('HTTP ' . (int) ($res['status'] ?? 0));
        Logger::warning('Cloudflare ' . $op . ' ошибка: ' . $msg);

        return false;
    }
}
