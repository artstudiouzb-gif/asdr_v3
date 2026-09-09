<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Отправка одноразовых кодов входа через официальный Telegram Gateway API
 * (https://gateway.telegram.org). Код приходит пользователю в Telegram от
 * верифицированного канала «Verification Codes» (t.me/VerificationCodes).
 *
 * Без сторонних библиотек: нативный curl. Токен доступа берётся из настроек
 * (telegram_gateway_token, кабинет gateway.telegram.org). Код генерируем сами
 * и проверяем локально (hash_equals) — API используется только для доставки.
 */
final class TelegramGateway
{
    private const DEFAULT_URL = 'https://gatewayapi.telegram.org/sendVerificationMessage';
    private const TIMEOUT_SECONDS = 10;

    public static function isConfigured(): bool
    {
        return trim(Setting::get('telegram_gateway_token', '')) !== '';
    }

    /**
     * Нормализует телефон к виду E.164 (+998901234567). Возвращает null,
     * если после очистки номер не похож на валидный.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }

    /**
     * Тело запроса sendVerificationMessage (выделено для тестируемости).
     *
     * @return array<string,mixed>
     */
    public static function buildPayload(string $phone, string $code, int $ttlSeconds = 300): array
    {
        return [
            'phone_number' => $phone,
            'code' => $code,
            'ttl' => $ttlSeconds,
        ];
    }

    /**
     * Отправляет код на телефон (E.164). true — шлюз принял сообщение.
     * Ошибки логируются; сам код в логи не пишется.
     */
    public static function sendCode(string $phone, string $code): bool
    {
        $token = trim(Setting::get('telegram_gateway_token', ''));
        if ($token === '') {
            return false;
        }

        // URL переопределяется переменной окружения только для тестов.
        $url = getenv('TELEGRAM_GATEWAY_URL') ?: self::DEFAULT_URL;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(self::buildPayload($phone, $code), JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            Logger::warning('Telegram Gateway недоступен: ' . $curlError, ['phone' => self::maskPhone($phone)]);
            return false;
        }

        $json = json_decode((string) $body, true);
        if ($httpCode !== 200 || !is_array($json) || ($json['ok'] ?? false) !== true) {
            Logger::warning('Telegram Gateway отклонил отправку кода', [
                'http' => $httpCode,
                'error' => is_array($json) ? (string) ($json['error'] ?? '') : 'bad response',
                'phone' => self::maskPhone($phone),
            ]);
            return false;
        }

        return true;
    }

    /** Маскирует номер для логов: +99890*****67. */
    public static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 8) {
            return $phone;
        }

        return substr($phone, 0, 6) . str_repeat('*', $len - 8) . substr($phone, -2);
    }
}
