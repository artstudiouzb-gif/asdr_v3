<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\Redirect;
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
        if (($removed['role'] ?? '') === 'admin' && User::isActive($removed) && User::otherActiveAdmins($id) === 0) {
            Flash::error('Это последний активный супер-администратор — удалить его нельзя.');
            Redirect::to('/admin/users');
        }
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

    /**
     * Правка учётки: почта, роль, телефон. Себе роль не понижают, и последний
     * активный супер-администратор остаётся супер-администратором — иначе
     * системные разделы стало бы некому открыть.
     *
     * @param array<string, string> $params
     */
    public function update(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $user = User::findById($id);
        if ($user === null) {
            Flash::error('Пользователь не найден.');
            Redirect::to('/admin/users');
        }

        $in = Input::post();
        $email = $in->str('email', '', 190);
        $role = $in->oneOf('role', ['admin', 'editor'], (string) $user['role']);
        $phoneRaw = $in->str('phone', '', 32);
        $phone = null;

        $error = null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Укажите корректный email.';
        } elseif (User::emailTakenByOther($email, $id)) {
            $error = 'Этот email уже занят другим пользователем.';
        } elseif ($phoneRaw !== '' && ($phone = \App\Core\TelegramGateway::normalizePhone($phoneRaw)) === null) {
            $error = 'Некорректный телефон. Используйте международный формат, например +998901234567.';
        } elseif ($role !== 'admin' && $user['role'] === 'admin') {
            if ($id === Auth::id()) {
                $error = 'Нельзя понизить собственную роль.';
            } elseif (User::otherActiveAdmins($id) === 0) {
                $error = 'Это последний активный супер-администратор — роль понизить нельзя.';
            }
        }
        if ($error !== null) {
            Flash::error($error);
            Redirect::to('/admin/users');
        }

        User::updateProfile($id, $email, $role, $phone);
        if ($role !== $user['role']) {
            self::announce('Изменена роль', 'роль пользователя «' . $user['username'] . '» изменена на ' . $role, true);
        }
        Flash::success('Данные пользователя «' . $user['username'] . '» сохранены.');
        Redirect::to('/admin/users');
    }

    /**
     * Блокировка без удаления: удаление обнуляет авторство новостей
     * (`fk_news_author ON DELETE SET NULL`), а блокировка только закрывает
     * вход — и сразу завершает все открытые сессии.
     *
     * @param array<string, string> $params
     */
    public function toggleActive(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $user = User::findById($id);
        if ($user === null) {
            Flash::error('Пользователь не найден.');
            Redirect::to('/admin/users');
        }
        $block = User::isActive($user);
        if ($block && $id === Auth::id()) {
            Flash::error('Нельзя заблокировать собственную учётную запись.');
            Redirect::to('/admin/users');
        }
        if ($block && $user['role'] === 'admin' && User::otherActiveAdmins($id) === 0) {
            Flash::error('Это последний активный супер-администратор — блокировать его нельзя.');
            Redirect::to('/admin/users');
        }

        User::setActive($id, !$block);
        if ($block) {
            \App\Models\SessionRegistry::revokeAll($id);
        }
        self::announce(
            $block ? 'Пользователь заблокирован' : 'Пользователь разблокирован',
            ($block ? 'заблокировал' : 'разблокировал') . ' пользователя «' . $user['username'] . '»',
            true
        );
        Flash::success($block
            ? 'Пользователь «' . $user['username'] . '» заблокирован, его сессии завершены.'
            : 'Пользователь «' . $user['username'] . '» снова может войти.');
        Redirect::to('/admin/users');
    }

    /**
     * Сброс второго фактора — когда сотрудник потерял телефон. Снимаются оба
     * канала, сессии завершаются: при следующем входе панель сама попросит
     * подключить второй фактор заново (`2fa_setup_required`).
     *
     * @param array<string, string> $params
     */
    public function resetTwoFactor(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $user = User::findById($id);
        if ($user === null) {
            Flash::error('Пользователь не найден.');
            Redirect::to('/admin/users');
        }
        if ($id === Auth::id()) {
            Flash::error('Свой второй фактор отключают в профиле — там это подтверждается паролем.');
            Redirect::to('/admin/users');
        }

        User::resetSecondFactor($id);
        \App\Models\SessionRegistry::revokeAll($id);
        self::announce('Сброшен второй фактор', 'сбросил второй фактор пользователя «' . $user['username'] . '»', true);
        Flash::success('Второй фактор «' . $user['username'] . '» сброшен. При входе панель попросит подключить его заново.');
        Redirect::to('/admin/users');
    }

    private static function announce(string $title, string $action, bool $important): void
    {
        $actor = Auth::sessionUser();
        NotificationCenter::administrators(
            $title,
            (string) ($actor['username'] ?? 'Администратор') . ' ' . $action . '.',
            'users',
            'security',
            '/admin/users',
            $important,
            null,
            isset($actor['id']) ? (int) $actor['id'] : null
        );
    }
}
