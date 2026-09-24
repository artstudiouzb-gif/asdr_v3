<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    /**
     * Метка «в сессии ждёт сообщение». Шапка публичной страницы спрашивает
     * flash на каждом ответе, и без метки ей пришлось бы поднимать сессию у
     * всякого, у кого есть её cookie, — а поднятая сессия делает ответ
     * `private, no-store`: браузер не кеширует страницу и не хранит её для
     * кнопки «Назад». Сообщение появляется только после действия (POST и
     * редирект), поэтому метка живёт до первого показа.
     */
    public const MARKER_COOKIE = 'asc_flash';

    public static function set(string $type, string $message): void
    {
        Session::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        self::marker(true);
    }

    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    public static function info(string $message): void
    {
        self::set('info', $message);
    }

    public static function warning(string $message): void
    {
        self::set('warning', $message);
    }

    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    public static function pull(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE
            && !(Session::hasCookie() && isset($_COOKIE[self::MARKER_COOKIE]))) {
            return [];
        }
        Session::start();
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        if (isset($_COOKIE[self::MARKER_COOKIE])) {
            self::marker(false);
        }

        return $messages;
    }

    private static function marker(bool $on): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::MARKER_COOKIE, $on ? '1' : '', [
            'expires' => $on ? 0 : time() - 3600,
            'path' => '/',
            'secure' => RequestUrl::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($on) {
            $_COOKIE[self::MARKER_COOKIE] = '1';
        } else {
            unset($_COOKIE[self::MARKER_COOKIE]);
        }
    }
}
