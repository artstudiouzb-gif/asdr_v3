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

    /**
     * Новый токен после входа. Токен, выданный анонимной сессии до входа,
     * мог быть подсмотрен или навязан (фиксация сессии): смены id сессии
     * мало, если в ней остаётся прежний токен.
     */
    public static function rotate(): void
    {
        Session::start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    public static function honeypotField(bool $cacheable = false): string
    {
        // Форма подписки стоит в подвале и сайдбаре новости, то есть почти
        // на каждой странице. Метка с точностью до секунды меняла тело
        // ответа на каждом запросе — ETag не совпадал, и 304 не случался
        // никогда. Ей метка — начало прошлого десятиминутного окна: тело
        // стабильно, а проверка подписи остаётся (порог «слишком быстро»
        // такой форме не нужен — её держит ограничение частоты).
        $ts = (string) ($cacheable ? intdiv(time(), 600) * 600 - 600 : time());
        $sig = self::honeypotSignature($ts);

        return '<div class="csrf-honeypot" aria-hidden="true">'
            . '<label>Не заполняйте это поле<input type="text" name="hp_website" tabindex="-1" autocomplete="off" value=""></label>'
            . '</div>'
            // data-cacheable стоит между name и value намеренно: PageBlocks
            // подставляет свежее время только в разметку name="hp_ts" value=.
            . '<input type="hidden" name="hp_ts"' . ($cacheable ? ' data-cacheable' : '') . ' value="' . htmlspecialchars($ts, ENT_QUOTES) . '">'
            . '<input type="hidden" name="hp_sig"' . ($cacheable ? ' data-cacheable' : '') . ' value="' . htmlspecialchars($sig, ENT_QUOTES) . '">';
    }

    /**
     * Свежая метка honeypot в HTML из общего кэша страниц. Подставлять надо
     * и время, и его подпись: прежде PageBlocks менял только hp_ts, подпись
     * оставалась от времени сборки кэша, isSpam() видел несовпадение и молча
     * отвечал «успех» — заявки форм на кэшированных страницах терялись.
     * Поля с data-cacheable не трогаются: их метка стабильна намеренно.
     */
    public static function refreshHoneypot(string $html): string
    {
        if (!str_contains($html, 'name="hp_ts" value="')) {
            return $html;
        }
        $ts = (string) time();
        $html = (string) preg_replace('/(name="hp_ts" value=")[^"]*(")/', '${1}' . $ts . '${2}', $html);

        return (string) preg_replace(
            '/(name="hp_sig" value=")[^"]*(")/',
            '${1}' . self::honeypotSignature($ts) . '${2}',
            $html
        );
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
