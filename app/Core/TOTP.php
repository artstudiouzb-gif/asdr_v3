<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Реализация TOTP (RFC 6238) на чистом PHP, без внешних зависимостей.
 * Совместима с Google Authenticator, Яндекс Ключ и аналогичными приложениями.
 */
final class TOTP
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /**
     * Длина секрета в байтах. Нижняя граница не косметическая: RFC 4226 требует
     * не меньше 128 бит, а `random_bytes(0)` — это ещё и `ValueError`, то есть
     * подключение второго фактора упало бы на пустом месте. Ни один вызывающий
     * своё значение не передаёт, поэтому проверка ничего не меняет сегодня и
     * страхует завтрашнего.
     *
     * @param int<16, max> $bytesLength
     */
    public static function generateSecret(int $bytesLength = 20): string
    {
        if ($bytesLength < 16) {
            throw new \InvalidArgumentException('секрет TOTP короче 128 бит: ' . $bytesLength . ' байт');
        }

        return self::base32Encode(random_bytes($bytesLength));
    }

    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        // Компактный URI: algorithm=SHA1, digits=6, period=30 — это значения
        // по умолчанию TOTP (RFC 6238), которые используют Google Authenticator
        // и Яндекс Ключ, поэтому их не указываем — так otpauth-URI короче и
        // помещается в компактный QR-код.
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ], '', '&', PHP_QUERY_RFC3986);

        $uri = "otpauth://totp/{$label}?{$params}";

        // Компактный QR-генератор (QrCode) вмещает ~106 байт. Если URI длиннее,
        // убираем дублирующий параметр issuer — приложения-аутентификаторы
        // берут издателя из префикса label («issuer:account»).
        if (strlen($uri) > 100) {
            $uri = "otpauth://totp/{$label}?secret={$secret}";
        }

        return $uri;
    }

    /**
     * Текущий код для секрета — тем же алгоритмом, что и проверка. Нужен
     * инструментам (обход админки в smoke), которым надо войти без телефона.
     */
    public static function code(string $secret, ?int $timeSlice = null): string
    {
        return self::calculateCode($secret, $timeSlice ?? (int) floor(time() / self::PERIOD));
    }

    public static function verify(string $secret, string $code, int $windowSteps = 1): bool
    {
        return self::matchStep($secret, $code, $windowSteps) !== null;
    }

    /**
     * Тот же разбор, что и verify(), но возвращает номер совпавшего шага
     * времени (или null). Номер нужен защите от повтора: RFC 6238 §5.2
     * требует принимать код одного шага ровно один раз, иначе подсмотренный
     * код остаётся рабочим до полутора минут.
     */
    public static function matchStep(string $secret, string $code, int $windowSteps = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $currentTimeSlice = (int) floor(time() / self::PERIOD);

        for ($i = -$windowSteps; $i <= $windowSteps; $i++) {
            $step = $currentTimeSlice + $i;
            if (hash_equals(self::calculateCode($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function calculateCode(string $secret, int $timeSlice): string
    {
        $secretBinary = self::base32Decode($secret);
        $timeBinary = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $timeBinary, $secretBinary, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $mod = $value % (10 ** self::DIGITS);

        return str_pad((string) $mod, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $output .= self::BASE32_ALPHABET[(int) bindec($chunk)];
        }

        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';

        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                // `bindec()` объявлена как `int|float` — float она отдаёт на
                // числах больше PHP_INT_MAX. Здесь их не бывает: восемь бит
                // это максимум 255. Приведение говорит это вслух, потому что
                // под `strict_types` float в `chr()` был бы TypeError.
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }
}
