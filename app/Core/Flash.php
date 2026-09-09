<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        Session::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
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
        if (session_status() !== PHP_SESSION_ACTIVE && !Session::hasCookie()) {
            return [];
        }
        Session::start();
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $messages;
    }
}
