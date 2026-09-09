<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\AdminEntryGate;
use App\Core\Auth;
use App\Core\AppUrl;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\PasswordPolicy;
use App\Core\RateLimiter;
use App\Core\SecurityHeaders;
use App\Core\View;
use App\Models\PasswordResetToken;
use App\Models\SessionRegistry;
use App\Models\User;

/**
 * Восстановление пароля администратора по одноразовой ссылке (TTL 30 минут).
 * Токен в БД хранится хешированным; сама ссылка уходит по e-mail через Mailer.
 */
final class PasswordResetController
{
    public function showForgot(): void
    {
        if (Auth::check()) {
            header('Location: /admin');
            exit;
        }
        View::render('admin/auth/forgot', ['error' => null, 'sent' => false]);
    }

    public function requestReset(): void
    {
        Csrf::verifyRequest();

        // Анти-абьюз: не более 5 запросов сброса с одного IP за 15 минут.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::throttle('pwreset', $ip, 5, 15)) {
            http_response_code(429);
            View::render('admin/auth/forgot', [
                'error' => 'Слишком много запросов. Попробуйте позже.',
                'sent' => false,
            ]);
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? User::findByEmail($email) : null;

        if ($user !== null) {
            try {
                $token = PasswordResetToken::issue((int) $user['id']);
                $this->sendResetEmail((string) $user['email'], (string) $user['username'], $token);
            } catch (\Throwable $e) {
                Logger::error('Password reset issue failed: ' . $e->getMessage());
            }
        }

        // Единый ответ независимо от существования e-mail (защита от перечисления).
        View::render('admin/auth/forgot', ['error' => null, 'sent' => true]);
    }

    /** @param array<string, string> $params */
    public function showReset(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $record = PasswordResetToken::findValid($token);

        if ($record === null) {
            View::render('admin/auth/reset', [
                'error' => 'Ссылка недействительна или просрочена. Запросите новую.',
                'token' => '',
                'invalid' => true,
            ]);
            return;
        }

        View::render('admin/auth/reset', ['error' => null, 'token' => $token, 'invalid' => false]);
    }

    /** @param array<string, string> $params */
    public function submitReset(array $params): void
    {
        Csrf::verifyRequest();

        $token = (string) ($_POST['token'] ?? ($params['token'] ?? ''));
        $record = PasswordResetToken::findValid($token);

        if ($record === null) {
            View::render('admin/auth/reset', [
                'error' => 'Ссылка недействительна или просрочена. Запросите новую.',
                'token' => '',
                'invalid' => true,
            ]);
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $user = User::findById((int) $record['user_id']);

        if ($password !== $confirm) {
            View::render('admin/auth/reset', ['error' => 'Пароли не совпадают.', 'token' => $token, 'invalid' => false]);
            return;
        }

        $errors = PasswordPolicy::validate($password, [
            (string) ($user['username'] ?? ''),
            (string) ($user['email'] ?? ''),
        ]);
        if ($errors !== []) {
            View::render('admin/auth/reset', ['error' => implode(' ', $errors), 'token' => $token, 'invalid' => false]);
            return;
        }

        User::updatePassword((int) $record['user_id'], $password);
        PasswordResetToken::markUsed((int) $record['id']);

        // Принудительно завершаем все активные сессии пользователя.
        SessionRegistry::revokeAll((int) $record['user_id']);

        Logger::security('Пароль сброшен по ссылке восстановления', [
            'user' => (string) ($user['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        Flash::success('Пароль изменён. Войдите с новым паролем.');
        header('Location: /admin/login');
        exit;
    }

    private function sendResetEmail(string $email, string $username, string $token): void
    {
        $base = AppUrl::base();
        if ($base === '') {
            $scheme = SecurityHeaders::isHttps() ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $link = $base . AdminEntryGate::entryUrl('/admin/reset/' . $token);

        $subject = 'Восстановление пароля — ArtStudio CMS';
        $body = "Здравствуйте, {$username}!\n\n"
            . "Поступил запрос на сброс пароля в панели управления ArtStudio CMS.\n"
            . "Чтобы задать новый пароль, перейдите по ссылке (действительна 30 минут):\n\n"
            . "{$link}\n\n"
            . "Если вы не запрашивали сброс — просто проигнорируйте это письмо, пароль останется прежним.";

        if (!Mailer::isConfigured()) {
            // SMTP не настроен: письмо не уйдёт. Логируем факт (без токена).
            Logger::error('Password reset requested, but SMTP is not configured.');
            return;
        }

        (new Mailer())->send($email, $subject, $body, $username);
    }
}
