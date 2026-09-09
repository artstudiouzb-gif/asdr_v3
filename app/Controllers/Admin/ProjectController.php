<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\ConcurrencyException;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\Slug;
use App\Core\View;
use App\Models\Language;
use App\Models\Project;
use App\Models\ContentRevision;

final class ProjectController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = AdminListQuery::normalize(
            $_GET,
            ['manual', 'newest', 'oldest', 'title_asc', 'title_desc'],
            'manual'
        );
        $total = Project::adminCount($filters);
        [$filters, $pages] = AdminListQuery::fitPage($filters, $total);
        View::render('admin/projects/index', [
            'items' => Project::adminList($filters),
            'filters' => $filters,
            'filterParams' => AdminListQuery::urlParams($filters),
            'total' => $total,
            'pages' => $pages,
            'langCounts' => Project::langCounts(),
        ]);
    }

    /** @param array<string, string> $params */
    public function duplicate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $newId = Project::duplicate((int) $params['id']);
        if ($newId === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        Flash::success('Проект дублирован как черновик.');
        header('Location: /admin/projects/' . $newId . '/edit');
        exit;
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/projects/form', ['project' => null, 'error' => null]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);

        if ($error !== null) {
            View::render('admin/projects/form', [
                'project' => $data,
                'error' => $error,
            ]);
            return;
        }

        $id = Database::transaction(static function (\PDO $_pdo) use ($data): int {
            return Project::create($data);
        });

        Flash::success('Проект создан.');
        header('Location: /admin/projects/' . $id . '/edit?draft_saved=project%3Anew');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $project = Project::findById((int) $params['id']);
        if (!$project) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/projects/form', [
            'project' => $project,
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function createTranslation(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $targetLang = trim((string) ($_POST['target_lang'] ?? ''));
        if ($targetLang === '' || !Language::isActive($targetLang)) {
            Flash::error('Указан некорректный язык перевода.');
            header('Location: /admin/projects/' . $id . '/edit');
            exit;
        }

        try {
            $newId = \App\Core\TranslationGroupHelper::createTranslation('projects', $id, $targetLang);
            Flash::success("Создан отдельный пост перевода проекта на язык «{$targetLang}».");
            header('Location: /admin/projects/' . $newId . '/edit');
            exit;
        } catch (\Throwable $e) {
            Flash::error('Не удалось создать перевод: ' . $e->getMessage());
            header('Location: /admin/projects/' . $id . '/edit');
            exit;
        }
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $project = Project::findById($id);
        if (!$project) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        if (!ContentRevision::isFresh('project', $id, (string) ($_POST['expected_updated_at'] ?? ''))) {
            View::render('admin/projects/form', [
                'project' => $project,
                'error' => 'Проект уже был изменён в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
            ]);
            return;
        }

        [$data, $error] = $this->collectInput($id, $project);

        if ($error !== null) {
            View::render('admin/projects/form', [
                'project' => array_merge($project, $data),
                'error' => $error,
            ]);
            return;
        }

        $expectedVersion = (int) ($_POST['expected_lock_version'] ?? 0);
        try {
            Database::transaction(static function (\PDO $_pdo) use ($id, $data, $expectedVersion): void {
                ContentRevision::capture('project', $id, Auth::id());
                Project::update($id, $data, $expectedVersion);
            });
        } catch (ConcurrencyException) {
            $project = Project::findById($id) ?? $project;
            View::render('admin/projects/form', [
                'project' => $project,
                'error' => 'Проект уже был изменён в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
            ]);
            return;
        }

        $activeLang = (string) ($_POST['active_lang_tab'] ?? $_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($activeLang)) {
            $activeLang = Language::defaultCode();
        }
        $langParam = '&lang=' . urlencode($activeLang);
        Flash::success('Проект обновлён.');
        header('Location: /admin/projects/' . $id . '/edit?draft_saved=project%3A' . $id . $langParam);
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        Project::delete((int) $params['id']);
        Flash::success('Проект удалён.');
        header('Location: ' . AdminListQuery::returnPath('/admin/projects', $_POST['return_query'] ?? ''));
        exit;
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?int $id, ?array $existing = null): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        // Анонс для карточки: короткий текст без разметки. Тело проекта живёт
        // в блоках, поэтому HTML здесь только мешал бы вёрстке списков.
        $description = self::excerptInput((string) ($_POST['description'] ?? ''));
        $status = (isset($_POST['publish_action']) || ($_POST['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '') {
            return [['title' => $title, 'slug' => $slugInput, 'description' => $description, 'status' => $status], 'Укажите название проекта.'];
        }

        $rawSlug = $slugInput !== '' ? $slugInput : $title;
        $slug = Slug::unique($rawSlug, [Project::class, 'slugExists'], $id);

        $coverImage = ImageField::resolve('cover_image_file', 'cover_image_url', $existing['cover_image'] ?? null, Auth::id());

        $data = [
            'title' => $title,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'cover_image' => $coverImage,
            'status' => $status,
            'is_featured' => !empty($_POST['is_featured']),
            'sort_order' => $sortOrder,
            'lang' => (string) ($_POST['lang'] ?? $_GET['lang'] ?? Language::defaultCode()),
        ];

        return [$data, null];
    }

    /** Анонс проекта: разметка снимается, пробелы схлопываются, длина 300. */
    private static function excerptInput(string $value): string
    {
        // Теги заменяем пробелом, а не вырезаем: вставка разметки иначе
        // склеивает соседние слова.
        $text = strip_tags((string) preg_replace('/<[^>]*>/', ' ', $value));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 300);
    }
}
