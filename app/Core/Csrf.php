<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    /** Методы без побочных эффектов — токен не требуется (RFC 9110 §9.2.1). */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(?string $token): bool
    {
        Session::start();
        if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Проверяет токен у всех небезопасных методов, а не только у POST:
     * роутер сегодня знает лишь GET и POST, но глушить проверку на первом же
     * добавленном PUT/DELETE было бы слишком тихим отказом.
     */
    public static function verifyRequest(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, self::SAFE_METHODS, true)) {
            return;
        }

        $token = $_POST['csrf_token'] ?? null;
        if (!self::verify(is_string($token) ? $token : null)) {
            http_response_code(419);
            exit('Сессия устарела (CSRF token mismatch). Обновите страницу и попробуйте снова.');
        }
    }

    /**
     * Honeypot-поля для публичных форм: невидимое текстовое поле, которое
     * заполняют боты, и подписанная HMAC метка времени рендера формы.
     */
    public static function honeypotField(): string
    {
        $ts = (string) time();
        $sig = self::honeypotSignature($ts);

        return '<div class="csrf-honeypot" aria-hidden="true">'
            . '<label>Не заполняйте это поле<input type="text" name="hp_website" tabindex="-1" autocomplete="off" value=""></label>'
            . '</div>'
            . '<input type="hidden" name="hp_ts" value="' . htmlspecialchars($ts, ENT_QUOTES) . '">'
            . '<input type="hidden" name="hp_sig" value="' . htmlspecialchars($sig, ENT_QUOTES) . '">';
    }

    private static function honeypotSignature(string $timestamp): string
    {
        $key = (string) (Config::get('crypto.encryption_key') ?? Config::get('app.key', 'asdr_honeypot_secret'));
        return hash_hmac('sha256', $timestamp . '|honeypot', $key);
    }

    /**
     * Признак спам-отправки: honeypot заполнен, подпись нарушена либо форма
     * отправлена подозрительно быстро (менее 2 секунд после рендера).
     */
    public static function isSpam(int $minSeconds = 2): bool
    {
        $spam = false;
        if (trim((string) ($_POST['hp_website'] ?? '')) !== '') {
            $spam = true;
        } else {
            $ts = (int) ($_POST['hp_ts'] ?? 0);
            $sig = (string) ($_POST['hp_sig'] ?? '');
            if ($ts <= 0 || (time() - $ts) < $minSeconds) {
                $spam = true;
            } elseif ($sig !== '' && !hash_equals(self::honeypotSignature((string) $ts), $sig)) {
                $spam = true;
            }
        }

        if ($spam) {
            // SECURITY-событие с длинным троттлингом — поток спама не заливает чат.
            Logger::security('Honeypot: подозрительная отправка формы отклонена', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'throttle' => 3600,
            ]);
        }

        return $spam;
    }
}
