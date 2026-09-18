<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Lang;
use App\Core\View;
use App\Models\InterfaceTranslation;
use App\Models\Language;

final class LanguageController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/languages/index', ['items' => Language::all()]);
    }

    public function translations(): void
    {
        Auth::requireSuperAdmin();

        $languages = array_values(array_filter(
            Language::all(),
            static fn (array $item): bool => (string) ($item['code'] ?? '') !== 'ru'
        ));
        $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
        $codes = array_map(static fn (array $item): string => (string) $item['code'], $languages);
        $lang = in_array($requested, $codes, true) ? $requested : ($codes[0] ?? 'uz');

        $query = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'missing', 'custom'], true)) {
            $status = 'all';
        }

        $base = Lang::baseTable($lang);
        $custom = InterfaceTranslation::forLanguage($lang);
        $rows = [];
        $translated = 0;
        $customCount = 0;

        foreach (Lang::sourceKeys() as $key) {
            $baseValue = (string) ($base[$key] ?? '');
            $customValue = (string) ($custom[$key] ?? '');
            $effective = $customValue !== '' ? $customValue : $baseValue;
            if ($effective !== '') {
                $translated++;
            }
            if ($customValue !== '') {
                $customCount++;
            }
            if ($query !== ''
                && mb_stripos($key, $query) === false
                && mb_stripos($effective, $query) === false) {
                continue;
            }
            if ($status === 'missing' && $effective !== '') {
                continue;
            }
            if ($status === 'custom' && $customValue === '') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'base' => $baseValue,
                'custom' => $customValue,
                'effective' => $effective,
                'missing' => $effective === '',
            ];
        }

        $perPage = 100;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $totalFiltered = count($rows);
        $pages = max(1, (int) ceil($totalFiltered / $perPage));
        $page = min($page, $pages);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        View::render('admin/languages/translations', [
            'languages' => $languages,
            'lang' => $lang,
            'rows' => $rows,
            'query' => $query,
            'status' => $status,
            'page' => $page,
            'pages' => $pages,
            'totalFiltered' => $totalFiltered,
            'total' => count(Lang::sourceKeys()),
            'translated' => $translated,
            'customCount' => $customCount,
        ]);
    }

    public function saveTranslations(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $lang = strtolower(trim((string) ($_POST['lang'] ?? '')));
        $allowed = array_map(
            static fn (array $item): string => (string) $item['code'],
            array_filter(Language::all(), static fn (array $item): bool => (string) ($item['code'] ?? '') !== 'ru')
        );
        if (!in_array($lang, $allowed, true)) {
            Flash::error('Выберите существующий язык перевода.');
            header('Location: /admin/languages/translations');
            exit;
        }

        $keys = (array) ($_POST['translation_key'] ?? []);
        $values = (array) ($_POST['translation_value'] ?? []);
        $known = array_fill_keys(Lang::sourceKeys(), true);
        $save = [];
        foreach ($keys as $index => $key) {
            $key = (string) $key;
            if (!isset($known[$key])) {
                continue;
            }
            $save[$key] = $values[$index] ?? '';
        }

        InterfaceTranslation::saveLanguage($lang, $save);
        Lang::flush();
        Flash::success('Переводы интерфейса сохранены.');

        $query = trim((string) ($_POST['q'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'all');
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $params = http_build_query(array_filter([
            'lang' => $lang,
            'q' => $query,
            'status' => $status !== 'all' ? $status : '',
            'page' => $page > 1 ? $page : '',
        ], static fn ($value): bool => $value !== ''));

        header('Location: /admin/languages/translations' . ($params !== '' ? '?' . $params : ''));
        exit;
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
