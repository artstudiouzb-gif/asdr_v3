<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Привязка Telegram-аккаунта администратора к боту: CMS показывает одноразовый
 * код, админ отправляет его боту, CMS находит сообщение через getUpdates и
 * запоминает chat_id.
 *
 * Логика вынесена сюда, потому что привязка доступна из двух мест — «Профиль»
 * и раздел «Telegram», — и расходиться они не должны.
 */
final class TelegramLink
{
    private const SESSION_KEY = 'tg_link_code';
    private const SESSION_EXPIRES_KEY = 'tg_link_code_expires';
    private const CODE_TTL_SECONDS = 600;

    /** Код привязки текущей сессии; создаётся при первом обращении. */
    public static function code(): string
    {
        Session::start();
        $expires = (int) ($_SESSION[self::SESSION_EXPIRES_KEY] ?? 0);
        if (empty($_SESSION[self::SESSION_KEY]) || $expires <= time()) {
            $_SESSION[self::SESSION_KEY] = 'link-' . bin2hex(random_bytes(4));
            $_SESSION[self::SESSION_EXPIRES_KEY] = time() + self::CODE_TTL_SECONDS;
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function currentCode(): ?string
    {
        Session::start();
        if ((int) ($_SESSION[self::SESSION_EXPIRES_KEY] ?? 0) <= time()) {
            unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_EXPIRES_KEY]);
            return null;
        }

        return isset($_SESSION[self::SESSION_KEY]) ? (string) $_SESSION[self::SESSION_KEY] : null;
    }

    /** @return string '' — бот не настроен; иначе @username бота */
    public static function botUsername(): string
    {
        $me = TelegramBot::getMe();

        return is_array($me) ? (string) ($me['username'] ?? '') : '';
    }

    /**
     * Подтверждение привязки: ищем код в сообщениях бота и сохраняем chat_id.
     *
     * @return array{ok:bool, message:string}
     */
    public static function confirm(int $userId): array
    {
        if (!TelegramBot::isConfigured()) {
            return ['ok' => false, 'message' => 'Привязка недоступна: токен бота не задан.'];
        }
        $code = self::currentCode();
        if ($code === null || $code === '') {
            return ['ok' => false, 'message' => 'Код привязки не найден — обновите страницу и попробуйте снова.'];
        }

        $chatId = TelegramBot::findChatIdByCode($code);
        if ($chatId === null) {
            return [
                'ok' => false,
                'message' => 'Код не найден в сообщениях бота. Отправьте код боту в Telegram и нажмите «Проверить привязку» ещё раз.',
            ];
        }

        User::updateTelegramChatId($userId, $chatId);
        Auth::completeTwoFactorSetup();
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_EXPIRES_KEY]);
        Logger::security('Привязан Telegram для кодов входа', [
            'user' => (string) ($_SESSION['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return ['ok' => true, 'message' => 'Telegram привязан — коды входа будут приходить от бота.'];
    }
}
