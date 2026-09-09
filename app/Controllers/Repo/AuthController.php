<?php

declare(strict_types=1);

namespace App\Controllers\Repo;

use App\Core\Csrf;
use App\Core\RepoAuth;
use App\Core\View;

/**
 * Вход/выход портала файлового хранилища. Отдельный от админ-панели поток
 * авторизации (см. App\Core\RepoAuth).
 */
final class AuthController
{
    public function showLogin(): void
    {
        if (RepoAuth::check()) {
            header('Location: /repo');
            exit;
        }
        View::render('repo/login', ['error' => null]);
    }

    public function login(): void
    {
        Csrf::verifyRequest();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            View::render('repo/login', ['error' => 'Введите логин и пароль.']);
            return;
        }

        $result = RepoAuth::attemptLogin($username, $password);

        switch ($result['status']) {
            case 'ok':
                header('Location: /repo');
                exit;
            case 'needs_2fa':
                header('Location: /repo/login/2fa');
                exit;
            case 'locked':
                $mins = (int) ceil((int) ($result['retry_after'] ?? 0) / 60);
                View::render('repo/login', ['error' => 'Слишком много попыток. Повторите через ' . max(1, $mins) . ' мин.']);
                return;
            case 'disabled':
                View::render('repo/login', ['error' => 'Учётная запись отключена. Обратитесь к администратору.']);
                return;
            case 'send_failed':
                View::render('repo/login', ['error' => 'Не удалось отправить код подтверждения в Telegram. Попробуйте позже или обратитесь к администратору.']);
                return;
            default:
                View::render('repo/login', ['error' => 'Неверный логин или пароль.']);
        }
    }

    public function showTwoFactor(): void
    {
        if (RepoAuth::pendingUserId() === null) {
            header('Location: /repo/login');
            exit;
        }
        View::render('repo/login_2fa', ['error' => null, 'channels' => RepoAuth::pendingChannels()]);
    }

    public function verifyTwoFactor(): void
    {
        Csrf::verifyRequest();

        if (RepoAuth::pendingUserId() === null) {
            header('Location: /repo/login');
            exit;
        }

        $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));

        if (RepoAuth::completeTwoFactor((string) $code)) {
            header('Location: /repo');
            exit;
        }

        View::render('repo/login_2fa', ['error' => 'Неверный код. Попробуйте ещё раз.', 'channels' => RepoAuth::pendingChannels()]);
    }

    /** Повторная отправка кода в Telegram со страницы подтверждения. */
    public function resendTelegramCode(): void
    {
        Csrf::verifyRequest();

        if (RepoAuth::pendingUserId() === null) {
            header('Location: /repo/login');
            exit;
        }

        $error = RepoAuth::resendTelegramCode()
            ? null
            : 'Не удалось отправить код. Подождите пару минут и попробуйте ещё раз.';
        View::render('repo/login_2fa', [
            'error' => $error,
            'notice' => $error === null ? 'Новый код отправлен в Telegram.' : null,
            'channels' => RepoAuth::pendingChannels(),
        ]);
    }

    public function logout(): void
    {
        Csrf::verifyRequest();
        RepoAuth::logout();
        header('Location: /repo/login');
        exit;
    }
}
