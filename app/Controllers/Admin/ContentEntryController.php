<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\ContentFields;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Slug;
use App\Core\View;
use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\Language;

/**
 * Автоматический CRUD записей пользовательского типа контента (задача 131).
 * Формы генерируются по определению полей типа; без правки кода на новый тип.
 */
final class ContentEntryController
{
    private function type(string $slug): ?array
    {
        return ContentType::findBySlug($slug);
    }

    /** @param array<string, string> $params */
    public function index(array $params): void
    {
        Auth::requireLogin();
        $type = $this->type((string) $params['type']);
        if (!$type) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        $filters = AdminListQuery::normalize(
            $_GET,
            ['manual', 'newest', 'oldest', 'title_asc', 'title_desc'],
            'manual'
        );
        $total = ContentEntry::adminCount((int) $type['id'], $filters);
        [$filters, $pages] = AdminListQuery::fitPage($filters, $total);
        View::render('admin/content/index', [
            'type' => $type,
            'items' => ContentEntry::adminList((int) $type['id'], $filters),
            'filters' => $filters,
            'filterParams' => AdminListQuery::urlParams($filters),
            'total' => $total,
            'pages' => $pages,
        ]);
    }

    /** @param array<string, string> $params */
    public function create(array $params): void
    {
        Auth::requireLogin();
        $type = $this->type((string) $params['type']);
        if (!$type) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        View::render('admin/content/form', [
            'type' => $type,
            'fields' => ContentType::fields((int) $type['id']),
            'entry' => null,
            'translations' => [],
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function store(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $type = $this->type((string) $params['type']);
        if (!$type) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$ok, $error, $id] = $this->save($type, null);
        if (!$ok) {
            View::render('admin/content/form', [
                'type' => $type,
                'fields' => ContentType::fields((int) $type['id']),
                'entry' => null,
                'translations' => [],
                'error' => $error,
            ]);
            return;
        }
        Flash::success('Запись создана.');
        header('Location: /admin/content/' . $type['slug'] . '/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();
        $type = $this->type((string) $params['type']);
        $entry = $type ? ContentEntry::findById((int) $params['id']) : null;
        if (!$type || !$entry || (int) $entry['type_id'] !== (int) $type['id']) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        View::render('admin/content/form', [
            'type' => $type,
            'fields' => ContentType::fields((int) $type['id']),
            'entry' => $entry,
            'translations' => ContentEntry::translations((int) $entry['id']),
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $type = $this->type((string) $params['type']);
        $entry = $type ? ContentEntry::findById((int) $params['id']) : null;
        if (!$type || !$entry || (int) $entry['type_id'] !== (int) $type['id']) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$ok, $error] = $this->save($type, $entry);
        if (!$ok) {
            View::render('admin/content/form', [
                'type' => $type,
                'fields' => ContentType::fields((int) $type['id']),
                'entry' => ContentEntry::findById((int) $entry['id']),
                'translations' => ContentEntry::translations((int) $entry['id']),
                'error' => $error,
            ]);
            return;
        }
        Flash::success('Запись сохранена.');
        header('Location: /admin/content/' . $type['slug']);
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $type = $this->type((string) $params['type']);
        $entry = $type ? ContentEntry::findById((int) $params['id']) : null;
        if (!$type || !$entry || (int) $entry['type_id'] !== (int) $type['id']) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        ContentEntry::delete((int) $entry['id']);
        Flash::success('Запись удалена.');
        header('Location: ' . AdminListQuery::returnPath('/admin/content/' . $type['slug'], $_POST['return_query'] ?? ''));
        exit;
    }

    /**
     * @return array{0:bool, 1:?string, 2:int}
     */
    private function save(array $type, ?array $entry): array
    {
        $fields = ContentType::fields((int) $type['id']);
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            return [false, 'Укажите заголовок записи.', 0];
        }

        [$values, $errors] = ContentFields::collect($fields, 'f_', Auth::id());
        if ($errors !== []) {
            return [false, implode(' ', $errors), 0];
        }

        $slug = Slug::make((string) ($_POST['slug'] ?? '') ?: $title);
        if (ContentEntry::slugExists((int) $type['id'], $slug, $entry['id'] ?? null)) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }
        $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        if ($entry === null) {
            $id = ContentEntry::create((int) $type['id'], $title, $slug, $status, $values);
        } else {
            $id = (int) $entry['id'];
            ContentEntry::update($id, $title, $slug, $status, $values);
        }

        // Переводы (для мультиязычных типов).
        if ((int) $type['has_translations'] === 1) {
            $defaultCode = Language::defaultCode();
            foreach (Language::active() as $lang) {
                $code = (string) $lang['code'];
                if ($code === $defaultCode) {
                    continue;
                }
                [$trValues] = ContentFields::collect($fields, 't_' . $code . '_', Auth::id(), false);
                $trTitle = trim((string) ($_POST['title_' . $code] ?? ''));
                ContentEntry::upsertTranslation($id, $code, $trTitle !== '' ? $trTitle : null, $trValues);
            }
        }

        return [true, null, $id];
    }
}
