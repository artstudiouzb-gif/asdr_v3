<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\User;

/**
 * Вход в админку: пароль плюс второй фактор. Подходит любой из двух каналов —
 * приложение-аутентификатор (TOTP) или одноразовый код в Telegram (бот либо
 * шлюз Verification Codes). Сама логика — в App\Core\Auth.
 */
final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /admin');
            exit;
        }

        View::render('admin/auth/login', ['error' => null]);
    }

    public function login(): void
    {
        Csrf::verifyRequest();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            View::render('admin/auth/login', ['error' => 'Введите логин и пароль.']);
            return;
        }

        $result = Auth::attemptLogin($username, $password);

        // Разрез аутентификации в «Журнале действий» (метод AUTH): успех,
        // ожидание 2FA, блокировка и неверные учётные данные — с IP.
        switch ($result['status']) {
            case 'ok':
                \App\Models\AuditLog::auth('login', (int) ($_SESSION['user_id'] ?? 0) ?: null, $username);
                header('Location: /admin');
                exit;
            case 'setup_required':
                \App\Models\AuditLog::auth('login.setup-required', (int) ($_SESSION['user_id'] ?? 0) ?: null, $username);
                \App\Core\Flash::error('Для доступа к панели сначала подключите второй фактор: приложение-аутентификатор или код в Telegram.');
                header('Location: /admin/profile');
                exit;
            case 'needs_code':
                \App\Models\AuditLog::auth('login.pending-2fa', Auth::pendingUserId(), $username);
                header('Location: /admin/login/2fa');
                exit;
            case 'send_failed':
                \App\Models\AuditLog::auth('login.send-failed', null, $username);
                View::render('admin/auth/login', [
                    'error' => 'Не удалось отправить код в Telegram. Проверьте токен бота или шлюза в настройках и телефон пользователя, либо повторите позже. Обходной путь — приложение-аутентификатор: оно работает без сети.',
                ]);
                return;
            case 'locked':
                \App\Models\AuditLog::auth('login.locked', null, $username);
                $minutes = (int) ceil(($result['retry_after'] ?? 0) / 60);
                View::render('admin/auth/login', [
                    'error' => "Слишком много попыток входа. Повторите через {$minutes} мин.",
                ]);
                return;
            default:
                \App\Models\AuditLog::auth('login.failed', null, $username);
                View::render('admin/auth/login', ['error' => 'Неверный логин или пароль.']);
        }
    }

    public function showTwoFactor(): void
    {
        if (Auth::pendingUserId() === null) {
            header('Location: /admin/login');
            exit;
        }

        View::render('admin/auth/2fa', ['error' => null]);
    }

    public function verifyTwoFactor(): void
    {
        Csrf::verifyRequest();

        if (Auth::pendingUserId() === null) {
            header('Location: /admin/login');
            exit;
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        $pendingId = Auth::pendingUserId();
        $pendingUser = $pendingId !== null ? User::findById($pendingId) : null;
        $pendingUsername = (string) ($pendingUser['username'] ?? '');

        if (Auth::completeTwoFactor($code)) {
            \App\Models\AuditLog::auth('2fa', (int) ($_SESSION['user_id'] ?? 0) ?: null, (string) ($_SESSION['username'] ?? ''));
            header('Location: /admin');
            exit;
        }
        \App\Models\AuditLog::auth('2fa.failed', $pendingId, $pendingUsername);

        // Просроченный/сброшенный pending уводит на логин, неверный код — ошибка.
        if (Auth::pendingUserId() === null) {
            Flash::error('Код устарел. Войдите заново — мы отправим новый.');
            header('Location: /admin/login');
            exit;
        }

        View::render('admin/auth/2fa', ['error' => 'Неверный код. Попробуйте снова.']);
    }

    /** Повторная отправка кода в Telegram (лимит: 3 раза за 5 минут). */
    public function resendCode(): void
    {
        Csrf::verifyRequest();

        if (Auth::pendingUserId() === null) {
            header('Location: /admin/login');
            exit;
        }

        $pendingId = Auth::pendingUserId();
        $pendingUser = $pendingId !== null ? User::findById($pendingId) : null;
        $resent = Auth::resendCode();
        \App\Models\AuditLog::auth(
            $resent ? '2fa.resent' : '2fa.resend-failed',
            $pendingId,
            (string) ($pendingUser['username'] ?? '')
        );

        View::render('admin/auth/2fa', [
            'error' => null,
            'notice' => $resent
                ? 'Новый код отправлен в Telegram.'
                : 'Не удалось отправить код (превышен лимит или шлюз недоступен). Подождите и попробуйте снова.',
        ]);
    }

    public function logout(): void
    {
        Csrf::verifyRequest();
        \App\Models\AuditLog::auth('logout', (int) ($_SESSION['user_id'] ?? 0) ?: null, (string) ($_SESSION['username'] ?? ''));
        Auth::logout();
        header('Location: /admin/login');
        exit;
    }
}
