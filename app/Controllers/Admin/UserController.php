<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\NotificationCenter;
use App\Core\View;
use App\Models\User;

/**
 * Управление учётными записями — только для супер-администратора.
 */
final class UserController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/users/index', ['items' => User::all(), 'error' => null]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? 'editor') === 'admin' ? 'admin' : 'editor';
        $phoneRaw = trim((string) ($_POST['phone'] ?? ''));
        $phone = null;
        $adminLangRaw = trim((string) ($_POST['admin_lang'] ?? ''));
        $activeCodes = \App\Models\Language::activeCodes();
        $adminLang = in_array($adminLangRaw, $activeCodes, true) ? $adminLangRaw : null;

        $error = null;
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Заполните логин и корректный email.';
        } elseif (($pwErrors = \App\Core\PasswordPolicy::validate($password, [$username, $email])) !== []) {
            $error = implode(' ', $pwErrors);
        } elseif (User::findByUsername($username)) {
            $error = 'Пользователь с таким логином уже существует.';
        } elseif (User::emailExists($email)) {
            $error = 'Пользователь с таким email уже существует.';
        } elseif ($phoneRaw !== '') {
            $phone = \App\Core\TelegramGateway::normalizePhone($phoneRaw);
            if ($phone === null) {
                $error = 'Некорректный телефон. Используйте международный формат, например +998901234567.';
            }
        }

        if ($error !== null) {
            View::render('admin/users/index', ['items' => User::all(), 'error' => $error]);
            return;
        }

        $newUserId = User::create($username, $email, $password, $role, $phone, $adminLang);
        $actor = Auth::sessionUser();
        $actorName = (string) ($actor['username'] ?? 'Администратор');
        $actorId = isset($actor['id']) ? (int) $actor['id'] : null;

        NotificationCenter::users(
            [$newUserId],
            'Учётная запись создана',
            'Для вас создан доступ к панели управления. После первого входа проверьте профиль и подключите второй фактор.',
            'users',
            'success',
            '/admin/profile',
            false,
            'user-created-welcome-' . $newUserId,
            $actorId
        );
        NotificationCenter::administrators(
            'Создан пользователь',
            $actorName . ' создал учётную запись «' . $username . '» с ролью ' . $role . '.',
            'users',
            'security',
            '/admin/users',
            false,
            null,
            $actorId
        );

        Flash::success('Пользователь создан. Код входа будет приходить в Telegram, если указан телефон и настроен шлюз.');
        header('Location: /admin/users');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];

        // Нельзя удалить самого себя и последнего пользователя.
        if ($id === Auth::id()) {
            Flash::error('Нельзя удалить собственную учётную запись.');
            header('Location: /admin/users');
            exit;
        }
        if (User::count() <= 1) {
            Flash::error('Нельзя удалить последнего пользователя.');
            header('Location: /admin/users');
            exit;
        }

        $removed = User::findById($id);
        User::delete($id);

        $actor = Auth::sessionUser();
        $actorName = (string) ($actor['username'] ?? 'Администратор');
        $actorId = isset($actor['id']) ? (int) $actor['id'] : null;
        NotificationCenter::administrators(
            'Удалена учётная запись',
            $actorName . ' удалил пользователя «' . (string) ($removed['username'] ?? ('ID ' . $id)) . '».',
            'users',
            'security',
            '/admin/users',
            true,
            null,
            $actorId
        );

        Flash::success('Пользователь удалён.');
        header('Location: /admin/users');
        exit;
    }
}
