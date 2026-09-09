<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Language;

final class LanguageController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/languages/index', ['items' => Language::all()]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);
        if ($error !== null) {
            Flash::error($error);
            header('Location: /admin/languages');
            exit;
        }

        Language::create($data);
        Flash::success('Язык добавлен.');
        header('Location: /admin/languages');
        exit;
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $lang = Language::findById($id);
        if (!$lang) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$data, $error] = $this->collectInput($id);
        if ($error !== null) {
            Flash::error($error);
            header('Location: /admin/languages');
            exit;
        }

        Language::update($id, $data);
        Flash::success('Язык обновлён.');
        header('Location: /admin/languages');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $lang = Language::findById($id);
        if ($lang && (int) $lang['is_default'] === 1) {
            Flash::error('Нельзя удалить язык по умолчанию. Сначала назначьте другой язык основным.');
            header('Location: /admin/languages');
            exit;
        }

        Language::delete($id);
        Flash::success('Язык удалён.');
        header('Location: /admin/languages');
        exit;
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?int $id): array
    {
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));

        if (!preg_match('/^[a-z]{2,8}$/', $code)) {
            return [[], 'Код языка должен состоять из 2–8 латинских букв (например, ru, uz, en).'];
        }
        if ($name === '') {
            return [[], 'Укажите название языка.'];
        }
        if (Language::codeExists($code, $id)) {
            return [[], 'Язык с таким кодом уже существует.'];
        }

        return [[
            'code' => $code,
            'name' => $name,
            'short_name' => trim((string) ($_POST['short_name'] ?? '')),
            'is_default' => !empty($_POST['is_default']),
            'is_active' => !empty($_POST['is_active']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ], null];
    }
}
