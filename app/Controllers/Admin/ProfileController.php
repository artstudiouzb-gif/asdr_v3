<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Locale;
use App\Core\PasswordPolicy;
use App\Core\Session;
use App\Core\View;
use App\Models\SessionRegistry;
use App\Models\User;

/**
 * Профиль администратора: смена пароля, управление активными сессиями,
 * телефон для кода входа через Telegram (Verification Codes).
 */
final class ProfileController
{
    public function index(): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();
        $currentHash = SessionRegistry::hash(Session::id());
        $profileUser = User::findById($userId);

        // Одноразовый код привязки Telegram-бота: показываем, пока аккаунт
        // не привязан. Код живёт в сессии, боту его отправляет сам админ.
        $botConfigured = \App\Core\TelegramBot::isConfigured();
        $botLinked = (int) ($profileUser['telegram_chat_id'] ?? 0) > 0;
        $botUsername = null;
        if ($botConfigured && !$botLinked) {
            \App\Core\TelegramLink::code();
            $botUsername = \App\Core\TelegramLink::botUsername();
        }

        View::render('admin/profile/index', [
            'sessions' => SessionRegistry::forUser($userId),
            'currentHash' => $currentHash,
            'profileUser' => $profileUser,
            'botConfigured' => $botConfigured,
            'botLinked' => $botLinked,
            'botUsername' => $botUsername,
            'linkCode' => $_SESSION['tg_link_code'] ?? null,
            'error' => null,
        ] + self::totpViewData($profileUser));
    }

    /**
     * Данные для раздела «Приложение-аутентификатор». Секрет живёт в сессии,
     * пока код не подтверждён: незавершённая настройка не должна попадать в
     * базу и делать вид, что второй фактор уже работает.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    private static function totpViewData(?array $user, ?string $totpError = null): array
    {
        $enabled = (int) ($user['totp_enabled'] ?? 0) === 1;
        // Секрет хранится зашифрованным: без ключа приложение подключить
        // нельзя, и честнее сказать об этом, чем упасть при сохранении.
        $ready = \App\Core\SecretBox::hasValidCurrentKey();
        $secret = null;
        if (!$enabled && $ready) {
            if (empty($_SESSION['totp_setup_secret'])) {
                $_SESSION['totp_setup_secret'] = \App\Core\TOTP::generateSecret();
            }
            $secret = (string) $_SESSION['totp_setup_secret'];
        }

        return [
            'totpReady' => $ready,
            'totpEnabled' => $enabled,
            'totpSecret' => $secret,
            'totpUri' => $secret !== null
                ? \App\Core\TOTP::provisioningUri($secret, (string) ($user['username'] ?? 'admin'), self::totpIssuer())
                : null,
            'totpError' => $totpError,
        ];
    }

    /**
     * Issuer для otpauth-URI: только короткий ASCII (домен сайта). Кириллица
     * раздувает URI percent-кодированием и не помещается в компактный QR.
     */
    private static function totpIssuer(): string
    {
        $host = (string) (parse_url((string) \App\Core\Config::get('app.url'), PHP_URL_HOST) ?: '');

        return $host !== '' && preg_match('/^[\x21-\x7E]{1,40}$/', $host) ? $host : 'ASDR';
    }

    /**
     * Подключение приложения-аутентификатора: верный код плюс подтверждение
     * паролем. Пароль обязателен по той же причине, что и при отключении:
     * иначе угнанная сессия привязывала бы к аккаунту свой аутентификатор и
     * получала постоянный второй фактор.
     */
    public function enableTotp(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $user = User::findById($userId);
        $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
        $code = (string) preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));

        if (!\App\Core\SecretBox::hasValidCurrentKey()) {
            Flash::error('Не задан ключ шифрования APP_ENCRYPTION_KEY — секрет приложения негде безопасно хранить.');
            header('Location: /admin/profile');
            exit;
        }

        if ($user === null || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            Flash::error('Неверный пароль. Приложение-аутентификатор не подключён.');
            header('Location: /admin/profile');
            exit;
        }

        if ($secret === '' || !\App\Core\TOTP::verify($secret, $code)) {
            Flash::error('Неверный код. Проверьте, что время на устройстве синхронизировано, и попробуйте ещё раз.');
            header('Location: /admin/profile');
            exit;
        }

        User::enableTotp($userId, $secret);
        unset($_SESSION['totp_setup_secret']);

        // Второй фактор появился — ограниченная сессия становится полной.
        $updated = User::findById($userId);
        if ($updated !== null) {
            Auth::syncTwoFactorSetup($updated);
        }
        \App\Core\Logger::security('Подключено приложение-аутентификатор', [
            'user' => (string) ($_SESSION['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        Flash::success('Приложение-аутентификатор подключено. Коды входа теперь можно брать из него.');
        header('Location: /admin/profile');
        exit;
    }

    /** Отключение — с подтверждением паролем: это ослабляет защиту входа. */
    public function disableTotp(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $user = User::findById($userId);
        if ($user === null || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            Flash::error('Неверный пароль. Приложение-аутентификатор не отключён.');
            header('Location: /admin/profile');
            exit;
        }

        User::disableTotp($userId);
        $updated = User::findById($userId);
        if ($updated !== null) {
            Auth::syncTwoFactorSetup($updated);
        }
        \App\Core\Logger::security('Отключено приложение-аутентификатор', [
            'user' => (string) ($_SESSION['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        Flash::success('Приложение-аутентификатор отключён.');
        header('Location: /admin/profile');
        exit;
    }

    /**
     * Проверка привязки Telegram: админ отправил боту одноразовый код —
     * находим его через getUpdates и сохраняем chat_id.
     */
    public function linkTelegram(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        // Общая с разделом «Telegram» логика — чтобы два места не разъезжались.
        $res = \App\Core\TelegramLink::confirm((int) Auth::id());
        if ($res['ok']) {
            Flash::success($res['message']);
        } else {
            Flash::error($res['message']);
        }

        header('Location: /admin/profile');
        exit;
    }

    /** Отвязка Telegram — с подтверждением паролем (ослабляет защиту входа). */
    public function unlinkTelegram(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $user = User::findById($userId);
        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            Flash::error('Неверный пароль. Telegram не отвязан.');
            header('Location: /admin/profile');
            exit;
        }

        User::updateTelegramChatId($userId, null);
        $updatedUser = User::findById($userId);
        if ($updatedUser) {
            Auth::syncTwoFactorSetup($updatedUser);
        }
        \App\Core\Logger::security('Отвязан Telegram для кодов входа', [
            'user' => (string) ($_SESSION['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        Flash::success('Telegram отвязан.');
        header('Location: /admin/profile');
        exit;
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $user = User::findById($userId);

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $error = null;
        if (!$user || !password_verify($current, $user['password_hash'])) {
            $error = 'Текущий пароль указан неверно.';
        } elseif ($new !== $confirm) {
            $error = 'Новый пароль и подтверждение не совпадают.';
        } else {
            $errors = PasswordPolicy::validate($new, [(string) $user['username'], (string) $user['email']]);
            if ($errors !== []) {
                $error = implode(' ', $errors);
            }
        }

        if ($error !== null) {
            \App\Core\Logger::security('Отклонён слабый/некорректный пароль при смене', [
                'user' => (string) ($user['username'] ?? ''),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            Flash::error($error);
            header('Location: /admin/profile');
            exit;
        }

        User::updatePassword($userId, $new);
        // Текущую сессию оставляем активной, но с новым идентификатором:
        // если старый успел утечь, он умирает вместе со сменой пароля.
        // Реестр перерегистрирует её под новым sid, иначе Auth::check()
        // не найдёт строку и выкинет того, кто пароль и менял.
        session_regenerate_id(true);
        SessionRegistry::register(
            $userId,
            Session::id(),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );
        SessionRegistry::revokeAllExcept($userId, Session::id());

        \App\Core\Logger::security('Пароль администратора изменён', [
            'user' => (string) ($user['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        Flash::success('Пароль изменён. Другие сессии завершены.');
        header('Location: /admin/profile');
        exit;
    }

    /** @param array<string, string> $params */
    public function revokeSession(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        SessionRegistry::revoke((int) Auth::id(), (int) $params['id']);
        Flash::success('Сессия отозвана.');
        header('Location: /admin/profile');
        exit;
    }

    public function revokeOthers(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        SessionRegistry::revokeAllExcept((int) Auth::id(), Session::id());
        \App\Core\Logger::security('Отзыв всех прочих сессий администратора', [
            'user' => (string) ($_SESSION['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        Flash::success('Все другие сессии завершены.');
        header('Location: /admin/profile');
        exit;
    }

    /**
     * Телефон для кода входа через Telegram (E.164). Изменение подтверждается
     * текущим паролем — иначе угнанная сессия могла бы перевесить 2FA на себя.
     */
    public function updatePhone(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $user = User::findById($userId);

        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            Flash::error('Неверный пароль. Телефон не изменён.');
            header('Location: /admin/profile');
            exit;
        }

        $raw = trim((string) ($_POST['phone'] ?? ''));
        if ($raw === '') {
            User::updatePhone($userId, null);
            $updatedUser = User::findById($userId);
            if ($updatedUser) {
                Auth::syncTwoFactorSetup($updatedUser);
            }
            Flash::success('Телефон удалён. Если другого канала подтверждения нет, доступ ограничен до настройки 2FA.');
        } else {
            $phone = \App\Core\TelegramGateway::normalizePhone($raw);
            if ($phone === null) {
                Flash::error('Некорректный номер. Укажите телефон в международном формате, например +998901234567.');
                header('Location: /admin/profile');
                exit;
            }
            User::updatePhone($userId, $phone);
            $updatedUser = User::findById($userId);
            if ($updatedUser) {
                Auth::syncTwoFactorSetup($updatedUser);
            }
            Flash::success('Телефон сохранён. Коды входа будут приходить в Telegram (Verification Codes).');
        }

        header('Location: /admin/profile');
        exit;
    }

    public function updateAdminLang(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $code = trim((string) ($_POST['admin_lang'] ?? ''));
        $activeCodes = \App\Models\Language::activeCodes();

        $adminLang = in_array($code, $activeCodes, true) ? $code : null;
        User::updateAdminLang($userId, $adminLang);

        if ($adminLang !== null) {
            Locale::set($adminLang);
        } else {
            Locale::set(\App\Models\Language::defaultCode());
        }

        Flash::success('Язык интерфейса по умолчанию сохранён.');
        header('Location: /admin/profile');
        exit;
    }

    public function updateAdminTheme(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $theme = trim((string) ($_POST['admin_theme'] ?? 'default'));
        $validThemes = ['default', 'wp_classic', 'navy_teal', 'royal_purple', 'dark_emerald'];

        if (!in_array($theme, $validThemes, true)) {
            $theme = 'default';
        }

        \App\Models\Setting::set('admin_theme_user_' . $userId, $theme);
        $_SESSION['admin_theme'] = $theme;

        Flash::success('Цветовая схема админ-панели успешно сохранена.');
        header('Location: /admin/profile');
        exit;
    }
}
