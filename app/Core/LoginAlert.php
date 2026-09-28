<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Сообщение в Telegram о входе в панель: успешном и неудачном.
 *
 * Сигнал о входе уже был — `Logger::security()` уходит в чат из
 * `config[telegram]`, — но толку от него мало. Этот чат настраивается файлом
 * на сервере, а не в панели, поэтому у владельца его обычно нет. Сообщение
 * общее для всех («Успешный вход в панель управления») и потому гасится
 * троттлингом: одно на десять минут, чей бы вход ни был. А о неудачных
 * попытках не сообщалось вовсе — хотя «неверный код второго фактора» значит,
 * что пароль уже знает кто-то другой.
 *
 * Поэтому сообщение уходит **владельцу аккаунта** — в тот чат, который он сам
 * привязал к боту для кодов входа (`users.telegram_chat_id`). Ему и решать,
 * был ли это он: по IP, времени и устройству. Попытка под несуществующим
 * именем не принадлежит никому и уходит супер-администраторам.
 *
 * **Неудачные попытки не сообщаются чаще раза в десять минут** на пару
 * «получатель + адрес»: перебор паролей дал бы сотню сообщений, и в них
 * утонуло бы то единственное, ради которого уведомления заведены. Успешный
 * вход не гасится никогда — каждый из них владелец обязан увидеть.
 *
 * **Отправка идёт после ответа посетителю.** Бот ходит в сеть с таймаутом
 * десять секунд, и недоступный Telegram не должен превращать вход в панель в
 * ожидание: сообщение — это уведомление, а не условие входа.
 */
final class LoginAlert
{
    public const SUCCESS_KEY = 'telegram_login_alert_success';
    public const FAILURE_KEY = 'telegram_login_alert_failure';

    /** Сколько секунд молчим о повторных неудачах с того же адреса. */
    public const FAILURE_QUIET_SECONDS = 600;

    public const METHOD_TOTP = 'totp';
    public const METHOD_TELEGRAM = 'telegram';
    public const METHOD_PASSWORD = 'password';

    public const FAIL_PASSWORD = 'password';
    public const FAIL_CODE = 'code';
    public const FAIL_UNKNOWN_USER = 'unknown_user';

    private const METHODS = [
        self::METHOD_TOTP => 'пароль и код из приложения-аутентификатора',
        self::METHOD_TELEGRAM => 'пароль и код из Telegram',
        self::METHOD_PASSWORD => 'только пароль',
    ];

    /** @var callable(int, string): void|null Транспорт для тестов: отправка сразу. */
    private static $transport = null;

    /** @var list<array{0: int, 1: string}> Сообщения, ждущие конца запроса. */
    private static array $queue = [];

    private static bool $shutdownRegistered = false;

    /** @param callable(int, string): void|null $transport */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function successEnabled(): bool
    {
        return Setting::get(self::SUCCESS_KEY, '1') !== '0';
    }

    public static function failureEnabled(): bool
    {
        return Setting::get(self::FAILURE_KEY, '1') !== '0';
    }

    /** @param array<string, mixed> $user */
    public static function success(array $user, string $method): void
    {
        if (!self::successEnabled()) {
            return;
        }
        $chatId = (int) ($user['telegram_chat_id'] ?? 0);
        if ($chatId <= 0) {
            return;
        }

        $lines = [
            '✅ <b>Вход в панель управления</b>',
            '',
            'Пользователь: <b>' . self::e((string) ($user['username'] ?? '')) . '</b>',
            'Способ: ' . (self::METHODS[$method] ?? self::METHODS[self::METHOD_PASSWORD]),
        ];

        self::queue([$chatId], array_merge($lines, self::circumstances(), [
            '',
            '<i>Если это были не вы, смените пароль и завершите чужие сессии в профиле: '
                . self::e(self::profileUrl()) . '</i>',
        ]), null);
    }

    /**
     * @param array<string, mixed>|null $user запись аккаунта; null — такого имени нет
     */
    public static function failure(?array $user, string $reason, string $username = ''): void
    {
        if (!self::failureEnabled()) {
            return;
        }

        if ($user === null) {
            $recipients = self::superAdminChats();
            $who = 'Имя, которого нет среди пользователей: <b>' . self::e(mb_strimwidth($username, 0, 64, '…')) . '</b>';
            $advice = 'Кто-то подбирает имя пользователя. Если попытки повторяются, закройте адрес панели списком разрешённых IP.';
        } else {
            $chatId = (int) ($user['telegram_chat_id'] ?? 0);
            $recipients = $chatId > 0 ? [$chatId] : [];
            $who = 'Пользователь: <b>' . self::e((string) ($user['username'] ?? '')) . '</b>';
            $advice = $reason === self::FAIL_CODE
                // Код спрашивают только после верного пароля: пароль уже известен.
                ? 'Пароль был введён верно, ошибся только второй фактор. Если это были не вы, пароль знает кто-то ещё: смените его сейчас.'
                : 'Если это были не вы, кто-то подбирает пароль к вашему аккаунту. Вход после нескольких ошибок блокируется сам.';
        }

        $cause = match ($reason) {
            self::FAIL_CODE => 'неверный код второго фактора',
            self::FAIL_UNKNOWN_USER => 'такого пользователя нет',
            default => 'неверный пароль',
        };

        $lines = array_merge([
            '⚠️ <b>Неудачная попытка входа</b>',
            '',
            $who,
            'Причина: ' . $cause,
        ], self::circumstances(), [
            '',
            '<i>' . $advice . '</i>',
            '<i>О повторных попытках с этого адреса в ближайшие ' . intdiv(self::FAILURE_QUIET_SECONDS, 60) . ' минут не сообщаем.</i>',
        ]);

        self::queue($recipients, $lines, $reason);
    }

    /**
     * IP, время и устройство — то, по чему владелец узнаёт свой вход.
     *
     * @return list<string>
     */
    private static function circumstances(): array
    {
        return [
            'IP: <code>' . self::e(self::ip()) . '</code>',
            'Устройство: ' . self::e(UserAgentLabel::describe((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
            'Время: ' . DateFormatter::dateTime(time()) . ' (Ташкент)',
        ];
    }

    /**
     * @param list<int> $chatIds
     * @param list<string> $lines
     * @param string|null $quietKind вид неудачи; null — не гасить повторы
     */
    private static function queue(array $chatIds, array $lines, ?string $quietKind): void
    {
        if ($chatIds === [] || (self::$transport === null && !TelegramBot::isConfigured())) {
            return;
        }
        $text = implode("\n", $lines);

        foreach (array_values(array_unique($chatIds)) as $chatId) {
            if ($quietKind !== null && !self::passQuiet($chatId, $quietKind)) {
                continue;
            }
            if (self::$transport !== null) {
                (self::$transport)($chatId, $text);
                continue;
            }
            self::$queue[] = [$chatId, $text];
        }

        if (self::$queue !== [] && !self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Отправка после ответа: под PHP-FPM ответ сначала отдаётся посетителю.
     * Сессию закрываем раньше — иначе браузер, ушедший по редиректу, ждал бы
     * её блокировки ровно столько, сколько идёт отправка.
     *
     * @internal вызывается из register_shutdown_function
     */
    public static function flush(): void
    {
        $pending = self::$queue;
        self::$queue = [];
        if ($pending === []) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            fastcgi_finish_request();
        }

        foreach ($pending as [$chatId, $text]) {
            try {
                TelegramBot::sendMessage($chatId, $text);
            } catch (\Throwable $e) {
                Logger::swallowed('Уведомление о входе в Telegram не отправлено', $e);
            }
        }
    }

    /** Молчание о повторных неудачах: одна отметка на получателя, адрес и вид. */
    private static function passQuiet(int $chatId, string $kind): bool
    {
        $key = 'login_alert:' . sha1($chatId . '|' . self::ip() . '|' . $kind);
        $last = Cache::get($key);
        if (is_int($last) && time() - $last < self::FAILURE_QUIET_SECONDS) {
            return false;
        }
        Cache::put($key, time(), self::FAILURE_QUIET_SECONDS);

        return true;
    }

    /** @return list<int> */
    private static function superAdminChats(): array
    {
        try {
            $rows = Database::rows(Database::pdo()->query(
                "SELECT telegram_chat_id FROM users WHERE role = 'admin' AND telegram_chat_id > 0"
            ));
        } catch (\Throwable $e) {
            Logger::swallowed('Список супер-администраторов для уведомления о входе', $e);

            return [];
        }

        return array_values(array_map(static fn (array $row): int => (int) $row['telegram_chat_id'], $rows));
    }

    private static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $ip !== '' ? $ip : 'неизвестен';
    }

    private static function profileUrl(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . '/admin/profile';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
