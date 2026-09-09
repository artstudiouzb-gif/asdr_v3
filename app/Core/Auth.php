<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SessionRegistry;
use App\Models\User;

final class Auth
{
    /** Срок жизни кода подтверждения входа, секунд. */
    private const CODE_TTL = 300;

    /**
     * Корзины перебора, которые снимает успешный вход. Корзины IP здесь нет
     * намеренно: иначе известный пароль одного аккаунта сбрасывал бы защиту
     * от перебора остальных с того же адреса.
     */
    private const CLEAR_ON_SUCCESS = ['pair', 'account'];

    /**
     * Вход по паролю со вторым фактором. Каналов два и достаточно любого:
     * приложение-аутентификатор (TOTP, считается на устройстве, работает без
     * сети) и одноразовый код в Telegram — бесплатным ботом или платным
     * шлюзом Verification Codes (Telegram Gateway API).
     *
     * Статусы: needs_code — второй фактор ожидается;
     * setup_required — пароль верен, но второй фактор ещё не настроен;
     * send_failed — шлюз не принял сообщение; invalid/locked — как раньше.
     *
     * @return array{status: string, retry_after?: int}
     */
    public static function attemptLogin(string $username, string $password): array
    {
        Session::start();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifiers = self::loginIdentifiers($username, $ip);

        foreach ($identifiers as $identifier => $limit) {
            if (RateLimiter::tooManyAttempts($identifier, $limit)) {
                return ['status' => 'locked', 'retry_after' => RateLimiter::secondsUntilRetry($identifier)];
            }
        }

        $user = User::findByUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            foreach (array_keys($identifiers) as $identifier) {
                RateLimiter::recordAttempt($identifier, false);
            }
            return ['status' => 'invalid'];
        }

        // Успешный вход очищает корзины аккаунта и конкретной пары. Корзина
        // IP сохраняется до истечения окна, чтобы известный пароль одного
        // аккаунта не позволял сбрасывать защиту от password spraying.
        // Ключи перечислены явно: порядок массива менять безопасно.
        foreach (self::CLEAR_ON_SUCCESS as $bucket) {
            RateLimiter::clearAttempts(self::loginIdentifier($bucket, $username, $ip));
        }

        session_regenerate_id(true);

        $totpOn = self::totpChannelAvailable($user);
        $telegramOn = self::telegramChannelAvailable($user);

        if (!$totpOn && !$telegramOn) {
            self::establishSession($user);

            if ((string) Config::get('app.env') !== 'development') {
                $_SESSION['2fa_setup_required'] = true;
                Logger::security('Вход ограничен до настройки второго фактора', [
                    'user' => (string) $user['username'],
                    'ip' => $ip,
                ]);

                return ['status' => 'setup_required'];
            }

            return ['status' => 'ok'];
        }

        $_SESSION['pending_user_id'] = (int) $user['id'];
        $_SESSION['pending_since'] = time();

        // Код в Telegram отправляем, только если этот канал вообще подключён.
        // Приложение-аутентификатор работает офлайн, поэтому недоступный
        // Telegram больше не запирает вход.
        $sent = $telegramOn && self::sendLoginCode($user);
        if ($telegramOn && !$sent && !$totpOn) {
            self::clearPending();

            return ['status' => 'send_failed'];
        }

        $_SESSION['pending_totp'] = $totpOn;
        $_SESSION['pending_telegram'] = $sent;

        return ['status' => 'needs_code'];
    }

    /**
     * Настроен ли хоть один второй фактор: приложение-аутентификатор,
     * бесплатный бот (telegram_chat_id) или платный шлюз Verification Codes
     * (телефон). Пока нет ни одного — сессия ограничена онбордингом.
     */
    private static function hasCodeChannel(array $user): bool
    {
        return self::totpChannelAvailable($user) || self::telegramChannelAvailable($user);
    }

    /**
     * Приложение-аутентификатор: коды считаются на устройстве, поэтому канал
     * работает без сети и не зависит от чужого сервиса.
     */
    private static function totpChannelAvailable(array $user): bool
    {
        return (int) ($user['totp_enabled'] ?? 0) === 1 && trim((string) ($user['totp_secret'] ?? '')) !== '';
    }

    /** Бесплатный бот (telegram_chat_id) или платный шлюз Verification Codes. */
    private static function telegramChannelAvailable(array $user): bool
    {
        if (TelegramBot::isConfigured() && (int) ($user['telegram_chat_id'] ?? 0) > 0) {
            return true;
        }

        return TelegramGateway::isConfigured() && trim((string) ($user['phone'] ?? '')) !== '';
    }

    /**
     * Корзины перебора и их пределы. Пара «IP + аккаунт» держит основной
     * лимит, отдельные корзины IP и аккаунта ловят перебор паролей одного
     * аккаунта с разных адресов и password spraying с одного адреса.
     *
     * @return array<string, int> identifier => max attempts
     */
    private static function loginIdentifiers(string $username, string $ip): array
    {
        $base = max(1, (int) Config::get('security.login_max_attempts', 5));
        $limits = [
            'pair' => $base,
            'ip' => max(25, $base * 5),
            'account' => max(10, $base * 2),
        ];

        $identifiers = [];
        foreach ($limits as $bucket => $limit) {
            $identifiers[self::loginIdentifier($bucket, $username, $ip)] = $limit;
        }

        return $identifiers;
    }

    /**
     * Набор корзин закрыт, и match это отражает: значения вне набора нет.
     * Тип параметра сужен, чтобы несоответствие ловилось у вызывающего, а не
     * превращалось в UnhandledMatchError посреди проверки перебора паролей.
     *
     * @param 'pair'|'ip'|'account' $bucket
     */
    private static function loginIdentifier(string $bucket, string $username, string $ip): string
    {
        $account = mb_strtolower(trim($username));

        return match ($bucket) {
            'pair' => 'admin_login|pair|' . $ip . '|' . $account,
            'ip' => 'admin_login|ip|' . $ip,
            'account' => 'admin_login|account|' . $account,
        };
    }

    /**
     * Генерирует одноразовый код, сохраняет его хэш в сессии и отправляет в
     * Telegram. Приоритет — бесплатный бот; иначе платный шлюз (канал
     * Verification Codes). Используется при входе и при повторной отправке.
     */
    private static function sendLoginCode(array $user): bool
    {
        $code = (string) random_int(100000, 999999);
        $_SESSION['pending_code_hash'] = hash('sha256', $code);
        $_SESSION['pending_code_expires'] = time() + self::CODE_TTL;

        $chatId = (int) ($user['telegram_chat_id'] ?? 0);
        if (TelegramBot::isConfigured() && $chatId > 0) {
            return TelegramBot::sendLoginCode($chatId, $code);
        }

        return TelegramGateway::sendCode((string) $user['phone'], $code);
    }

    /**
     * Повторная отправка кода (по кнопке на странице подтверждения).
     * Ограничена: не чаще 3 раз за 5 минут с одного IP.
     */
    public static function resendCode(): bool
    {
        Session::start();
        $userId = $_SESSION['pending_user_id'] ?? null;
        if (!$userId) {
            return false;
        }
        if (!RateLimiter::throttle('2fa_resend', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 3, 5)) {
            return false;
        }

        // Пересылать нечего, если код не отправляют, а считают в приложении.
        $user = User::findById((int) $userId);
        if (!$user || !self::telegramChannelAvailable($user)) {
            return false;
        }

        if (!self::sendLoginCode($user)) {
            return false;
        }

        $_SESSION['pending_telegram'] = true;

        return true;
    }

    /**
     * Проверка кода второго фактора: TOTP из приложения (считается на
     * устройстве) или одноразовый код из Telegram (hash_equals с хэшем из
     * сессии, срок жизни 5 минут). Перебор ограничен RateLimiter.
     */
    public static function completeTwoFactor(string $code): bool
    {
        Session::start();
        $userId = $_SESSION['pending_user_id'] ?? null;
        if (!$userId || (time() - (int) ($_SESSION['pending_since'] ?? 0)) > self::CODE_TTL) {
            self::clearPending();
            return false;
        }

        $user = User::findById((int) $userId);
        if (!$user) {
            self::clearPending();
            return false;
        }

        $identifier = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|2fa|' . mb_strtolower($user['username']);
        if (RateLimiter::tooManyAttempts($identifier)) {
            return false;
        }

        // Принимается код любого включённого канала: из приложения-аутентифи-
        // катора или одноразовый, отправленный в Telegram.
        $code = preg_replace('/\s+/', '', $code) ?? '';
        $valid = false;
        if (!empty($_SESSION['pending_totp']) && trim((string) ($user['totp_secret'] ?? '')) !== '') {
            // Шаг времени засчитывается один раз (RFC 6238 §5.2): иначе
            // подсмотренный код оставался бы рабочим до полутора минут.
            $step = TOTP::matchStep((string) $user['totp_secret'], $code);
            $valid = $step !== null && User::consumeTotpStep((int) $user['id'], $step);
        }
        // Отправленный код проверяем и без метки канала: сессии, начатые до
        // появления TOTP, метки не знают, а настоящий секрет здесь — сам хэш.
        // Иначе обновление выбило бы всех, кто в этот момент вводил код.
        $expectedHash = (string) ($_SESSION['pending_code_hash'] ?? '');
        $codeExpired = $expectedHash !== '' && time() > (int) ($_SESSION['pending_code_expires'] ?? 0);
        if (!$valid && $expectedHash !== '' && !$codeExpired) {
            $valid = hash_equals($expectedHash, hash('sha256', $code));
        }

        if (!$valid) {
            // Одноразовый код протух — вход начинают заново. У приложения
            // срока годности нет, поэтому такой вход не сбрасываем.
            if ($codeExpired && empty($_SESSION['pending_totp'])) {
                self::clearPending();

                return false;
            }
            RateLimiter::recordAttempt($identifier, false);

            return false;
        }

        RateLimiter::clearAttempts($identifier);
        self::clearPending();
        self::establishSession($user);

        return true;
    }

    public static function establishSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['authenticated_at'] = time();
        $_SESSION['fingerprint'] = self::fingerprint();

        if (!empty($user['admin_lang'])) {
            Locale::set((string) $user['admin_lang']);
        }

        User::touchLastLogin((int) $user['id']);

        // Регистрируем сессию в реестре: даёт список устройств и мгновенный
        // серверный отзыв (страница «Мои сессии»).
        try {
            SessionRegistry::register(
                (int) $user['id'],
                Session::id(),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
        } catch (\Throwable $e) {
            Logger::error('SessionRegistry::register failed: ' . $e->getMessage());
        }

        Logger::security('Успешный вход в панель управления', [
            'user' => (string) $user['username'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Вероятностная очистка старых записей брутфорса и ротация логов.
        RateLimiter::garbageCollect();
    }

    /**
     * Фингерпринт клиента: хэш от User-Agent и первых двух октетов IP
     * (подсеть /16). Привязывает сессию к устройству/сети, затрудняя
     * использование украденного cookie с другого клиента, но не ломает
     * сессию при смене последнего октета динамического IP.
     */
    private static function fingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $subnet = '';
        if (str_contains($ip, '.')) {
            $octets = explode('.', $ip);
            $subnet = ($octets[0] ?? '') . '.' . ($octets[1] ?? '');
        } elseif (str_contains($ip, ':')) {
            // IPv6: первые два хекстета.
            $parts = explode(':', $ip);
            $subnet = ($parts[0] ?? '') . ':' . ($parts[1] ?? '');
        }

        return hash('sha256', $ua . '|' . $subnet);
    }

    private static function clearPending(): void
    {
        unset(
            $_SESSION['pending_user_id'],
            $_SESSION['pending_since'],
            $_SESSION['pending_code_hash'],
            $_SESSION['pending_code_expires'],
            $_SESSION['pending_totp'],
            $_SESSION['pending_telegram']
        );
    }

    public static function check(): bool
    {
        // Без cookie сессии посетитель войти не мог — значит и заводить ему
        // сессию незачем. Раньше здесь стоял безусловный Session::start(), и
        // публичная страница выдавала Set-Cookie каждому анониму: шапка зовёт
        // AppToolbar::isVisible() → sessionUser() → check() на каждом рендере.
        // Побочный эффект был дороже: активная сессия делает ответ
        // некэшируемым (PublicResponseCache), поэтому общий HTTP-кэш публички
        // не работал вообще ни на одной странице.
        // Восстанавливать нечего: данных сессии в процессе нет, она не
        // запущена и cookie не прислана. Условие проверяет все три источника —
        // в CLI и тестах $_SESSION заполняют напрямую, там cookie не бывает.
        if (empty($_SESSION['user_id'])
            && session_status() !== PHP_SESSION_ACTIVE
            && !Session::hasCookie()) {
            return false;
        }

        Session::start();
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        // Защита от перехвата сессии: фингерпринт должен совпадать.
        if (!isset($_SESSION['fingerprint']) || !hash_equals($_SESSION['fingerprint'], self::fingerprint())) {
            Logger::security('Несовпадение фингерпринта сессии — принудительный выход', [
                'user' => (string) ($_SESSION['username'] ?? ''),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            self::logout();
            return false;
        }

        // Мгновенный серверный отзыв: сессия действительна, только пока её
        // строка присутствует в реестре. Удаление строки («выйти на этом
        // устройстве»/«везде»/смена пароля) немедленно завершает сессию.
        try {
            if (!self::sessionStillRegistered((int) $_SESSION['user_id'], Session::id())) {
                self::logout();
                return false;
            }
            // Обновляем «последнюю активность» не чаще раза в минуту.
            if ((time() - (int) ($_SESSION['sid_seen_at'] ?? 0)) > 60) {
                SessionRegistry::touch((int) $_SESSION['user_id'], Session::id());
                $_SESSION['sid_seen_at'] = time();
            }
        } catch (\Throwable $e) {
            // Транзиентная ошибка БД не должна разлогинивать всех — фингерпринт
            // уже проверен; логируем и пропускаем.
            Logger::error('SessionRegistry check failed: ' . $e->getMessage());
        }

        return true;
    }

    public static function id(): ?int
    {
        Session::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Строка сессии всё ещё в реестре — ответ на запрос спрашивается один раз.
     *
     * `check()` зовут отовсюду: `requireLogin`, `user()`, помощники вьюх, RBAC.
     * Каждый вызов ходил в реестр, и страница админки делала 5–8 одинаковых
     * запросов. Мгновенность отзыва от памяти не страдает: запрос живёт
     * миллисекунды, а «немедленно» и означает «со следующего запроса».
     * Ключ включает идентификатор сессии, поэтому смена сессии внутри запроса
     * (вход, регенерация) память не переиспользует.
     */
    private static function sessionStillRegistered(int $userId, string $sessionId): bool
    {
        $key = $userId . '|' . $sessionId;
        if (array_key_exists($key, self::$registryMemo)) {
            return self::$registryMemo[$key];
        }

        return self::$registryMemo[$key] = SessionRegistry::exists($userId, $sessionId);
    }

    /**
     * Ответы реестра в пределах запроса: «пользователь|сессия» → есть ли строка.
     *
     * @var array<string, bool>
     */
    private static array $registryMemo = [];

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return User::findById((int) $_SESSION['user_id']);
    }

    /**
     * Минимальный профиль из уже проверенной сессии. Подходит для интерфейса
     * и RBAC, где новый запрос к users на каждом рендере не нужен.
     *
     * @return array{id:int,username:string,role:string}|null
     */
    public static function sessionUser(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => (string) ($_SESSION['username'] ?? ''),
            'role' => (string) ($_SESSION['role'] ?? RbacGuard::ROLE_EDITOR),
        ];
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /admin/login');
            exit;
        }

        $user = self::user();
        if ($user) {
            $activeCodes = \App\Models\Language::activeCodes();
            $requestedCode = LocalePreference::requestedCode($_SERVER['REQUEST_URI'] ?? '', $activeCodes);

            if ($requestedCode !== null) {
                if (($user['admin_lang'] ?? '') !== $requestedCode) {
                    User::updateAdminLang((int) $user['id'], $requestedCode);
                }
                Locale::set($requestedCode);
            } elseif (!empty($user['admin_lang']) && in_array($user['admin_lang'], $activeCodes, true)) {
                Locale::set((string) $user['admin_lang']);
            }
        }
    }

    public static function role(): string
    {
        Session::start();
        return (string) ($_SESSION['role'] ?? RbacGuard::ROLE_EDITOR);
    }

    /**
     * Идентификатор входа, ожидающего второго фактора. Через него, а не через
     * $_SESSION напрямую, — иначе GET страницы ввода кода читает суперглобал
     * до того, как кто-нибудь запустит сессию (Session::start ленивый).
     */
    /**
     * Идентификатор входа, ожидающего второго фактора.
     *
     * Значение читается из сессии и **меняется между вызовами**: неудачная
     * или просроченная проверка кода зовёт `clearPending()`. Без пометки
     * статический анализ считает вызов детерминированным, запоминает результат
     * первой проверки и объявляет вторую лишней — а она как раз и отличает
     * «код неверный» от «сессия истекла, войдите заново».
     *
     * @phpstan-impure
     */
    public static function pendingUserId(): ?int
    {
        Session::start();
        $id = $_SESSION['pending_user_id'] ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * Какими каналами можно подтвердить текущий вход. Нужно странице ввода
     * кода: «код из Telegram» и «код из приложения» — разные инструкции.
     *
     * @return array{totp: bool, telegram: bool}
     */
    public static function pendingChannels(): array
    {
        Session::start();

        return [
            'totp' => !empty($_SESSION['pending_totp']),
            'telegram' => !empty($_SESSION['pending_telegram']),
        ];
    }

    public static function requiresTwoFactorSetup(): bool
    {
        Session::start();
        return !empty($_SESSION['2fa_setup_required']);
    }

    public static function completeTwoFactorSetup(): void
    {
        Session::start();
        unset($_SESSION['2fa_setup_required']);
    }

    /** Синхронизирует ограничения текущей сессии после изменения каналов 2FA. */
    public static function syncTwoFactorSetup(array $user): void
    {
        Session::start();
        if (self::hasCodeChannel($user)) {
            self::completeTwoFactorSetup();
            return;
        }

        $_SESSION['2fa_setup_required'] = true;
    }

    /**
     * Ролей ровно две — те, что перечисляет ENUM `users.role`: 'admin'
     * (супер-администратор, полный доступ) и 'editor' (только контент).
     * Имена и права живут в RbacGuard, чтобы два списка не разъезжались.
     */
    public static function isSuperAdmin(): bool
    {
        return self::role() === RbacGuard::ROLE_ADMIN;
    }

    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            http_response_code(403);
            \App\Core\View::render('errors/403');
            exit;
        }
    }

    public static function logout(): void
    {
        // Сессия закончилась — прежний ответ реестра больше не действует.
        self::$registryMemo = [];
        Session::start();
        // Снимаем сессию с реестра активных сессий.
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                SessionRegistry::remove(Session::id());
            }
        } catch (\Throwable $e) {
            Logger::error('SessionRegistry::remove failed: ' . $e->getMessage());
        }

        $_SESSION = [];

        // Всё, что ниже, имеет смысл только при живой сессии. Session::start()
        // ничего не открывает в CLI, а без этой проверки session_destroy()
        // писал в лог «Trying to destroy uninitialized session» — шум, за
        // которым легко пропустить настоящую ошибку.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(Session::name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }
}
