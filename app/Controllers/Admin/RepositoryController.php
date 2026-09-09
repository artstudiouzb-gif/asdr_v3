<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\PasswordPolicy;
use App\Core\View;
use App\Models\RepoCategory;
use App\Models\RepoFile;
use App\Models\RepoUser;
use App\Models\Setting;

/**
 * Управление защищённым файловым хранилищем из админ-панели: загрузка/удаление
 * файлов и управление учётными записями портала. Только супер-администратор.
 */
final class RepositoryController
{
    public function files(): void
    {
        Auth::requireSuperAdmin();

        $query = trim((string) ($_GET['q'] ?? ''));
        $files = RepoFile::all($query);
        $pending = RepoFile::pending();
        $categories = RepoCategory::flatOptions();
        $users = RepoUser::all();

        View::render('admin/repository/files', [
            'files' => $files,
            'pending' => $pending,
            'query' => $query,
            'categories' => $categories,
            'repoLogo' => (string) Setting::get('repo_logo', ''),
            'stats' => [
                'total_files' => count($query === '' ? $files : RepoFile::all('')),
                'pending_files' => count($pending),
                'total_categories' => count($categories),
                'total_users' => count($users),
            ],
        ]);
    }

    /** Оформление портала: собственный логотип шапки и формы входа. */
    public function saveSettings(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $logo = ImageField::resolve('repo_logo_file', 'repo_logo', (string) Setting::get('repo_logo', ''), Auth::id());
        Setting::set('repo_logo', trim((string) $logo));
        Flash::success('Оформление портала сохранено.');
        header('Location: /admin/repository');
        exit;
    }

    /**
     * Одобрение файла, загруженного пользователем портала.
     * @param array<string, string> $params
     */
    public function approveFile(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        if (RepoFile::findById($id) === null) {
            Flash::error('Файл не найден.');
        } else {
            RepoFile::approve($id);
            Flash::success('Файл одобрен и опубликован на портале.');
        }
        header('Location: /admin/repository');
        exit;
    }

    /** Присланная категория: id существующей или null («без категории»). */
    private function categoryIdFromPost(): ?int
    {
        $id = (int) ($_POST['category_id'] ?? 0);

        return $id > 0 && RepoCategory::findById($id) !== null ? $id : null;
    }

    public function upload(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $file = $_FILES['file'] ?? null;

        if ($title === '') {
            Flash::error('Укажите название файла.');
            header('Location: /admin/repository');
            exit;
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::error('Выберите файл для загрузки.');
            header('Location: /admin/repository');
            exit;
        }

        try {
            RepoFile::store($file, $title, $description, $this->categoryIdFromPost(), Auth::id());
            Flash::success('Файл загружен в хранилище.');
        } catch (\Throwable $e) {
            Flash::error('Не удалось загрузить файл: ' . $e->getMessage());
        }

        header('Location: /admin/repository');
        exit;
    }

    /** @param array<string, string> $params */
    public function updateFile(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (RepoFile::findById($id) === null) {
            Flash::error('Файл не найден.');
        } elseif ($title === '') {
            Flash::error('Укажите название файла.');
        } else {
            RepoFile::updateMeta($id, $title, $description, $this->categoryIdFromPost());
            Flash::success('Данные файла обновлены.');
        }
        header('Location: /admin/repository');
        exit;
    }

    // --- Категории хранилища (с подкатегориями) ---

    public function categories(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/repository/categories', ['tree' => RepoCategory::tree()]);
    }

    public function storeCategory(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $parent = $parentId > 0 ? RepoCategory::findById($parentId) : null;

        if ($name === '' || mb_strlen($name) > 120) {
            Flash::error('Укажите название категории (до 120 символов).');
        } elseif ($parentId > 0 && $parent === null) {
            Flash::error('Родительская категория не найдена.');
        } elseif ($parent !== null && $parent['parent_id'] !== null) {
            // Один уровень вложенности: подкатегория не может быть родителем.
            Flash::error('Подкатегорию можно создать только внутри корневой категории.');
        } else {
            RepoCategory::create($name, $parent !== null ? $parentId : null);
            Flash::success($parent !== null ? 'Подкатегория создана.' : 'Категория создана.');
        }
        header('Location: /admin/repository/categories');
        exit;
    }

    /** @param array<string, string> $params */
    public function renameCategory(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));

        if (RepoCategory::findById($id) === null) {
            Flash::error('Категория не найдена.');
        } elseif ($name === '' || mb_strlen($name) > 120) {
            Flash::error('Укажите название категории (до 120 символов).');
        } else {
            RepoCategory::rename($id, $name);
            Flash::success('Категория переименована.');
        }
        header('Location: /admin/repository/categories');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroyCategory(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        RepoCategory::delete((int) ($params['id'] ?? 0));
        Flash::success('Категория удалена. Файлы остались без категории, подкатегории удалены.');
        header('Location: /admin/repository/categories');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroyFile(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        RepoFile::delete((int) ($params['id'] ?? 0));
        Flash::success('Файл удалён из хранилища.');
        header('Location: /admin/repository');
        exit;
    }

    // --- Учётные записи портала ---

    public function users(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/repository/users', ['users' => RepoUser::all(), 'error' => null]);
    }

    public function storeUser(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $organization = trim((string) ($_POST['organization'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $error = null;
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Заполните логин и корректный email.';
        } elseif (($pwErrors = PasswordPolicy::validate($password, [$username, $email])) !== []) {
            $error = implode(' ', $pwErrors);
        } elseif (RepoUser::usernameExists($username)) {
            $error = 'Пользователь с таким логином уже существует.';
        } elseif (RepoUser::emailExists($email)) {
            $error = 'Пользователь с таким email уже существует.';
        }

        if ($error !== null) {
            View::render('admin/repository/users', ['users' => RepoUser::all(), 'error' => $error]);
            return;
        }

        RepoUser::create($username, $fullName, $email, $password, $organization);
        Flash::success('Учётная запись портала создана. 2FA пользователь может включить сам после входа.');
        header('Location: /admin/repository/users');
        exit;
    }

    /** @param array<string, string> $params */
    public function toggleUser(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        $user = RepoUser::findById($id);
        if ($user !== null) {
            RepoUser::setActive($id, (int) $user['is_active'] !== 1);
            Flash::success((int) $user['is_active'] === 1 ? 'Доступ отключён.' : 'Доступ включён.');
        }
        header('Location: /admin/repository/users');
        exit;
    }

    /** @param array<string, string> $params */
    public function resetUserPassword(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        $user = RepoUser::findById($id);
        $password = (string) ($_POST['password'] ?? '');

        if ($user === null) {
            Flash::error('Пользователь не найден.');
        } elseif (($pwErrors = PasswordPolicy::validate($password, [(string) $user['username'], (string) $user['email']])) !== []) {
            Flash::error(implode(' ', $pwErrors));
        } else {
            RepoUser::updatePassword($id, $password);
            RepoUser::disableTotp($id);
            RepoUser::setTelegramChatId($id, null);
            Flash::success('Пароль и все каналы 2FA сброшены. Активные сессии завершатся автоматически.');
        }
        header('Location: /admin/repository/users');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroyUser(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        RepoUser::delete((int) ($params['id'] ?? 0));
        Flash::success('Учётная запись портала удалена.');
        header('Location: /admin/repository/users');
        exit;
    }
}
