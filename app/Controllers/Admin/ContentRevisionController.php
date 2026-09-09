<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\ContentRevision;
use App\Models\News;
use App\Models\Page;
use App\Models\Project;

final class ContentRevisionController
{
    private const LABELS = ['page' => 'страницы', 'news' => 'новости', 'project' => 'проекта'];
    private const EDIT_PATHS = ['page' => '/admin/pages/%d/edit', 'news' => '/admin/news/%d/edit', 'project' => '/admin/projects/%d/edit'];

    /** @param array<string, string> $params */
    public function index(array $params): void
    {
        Auth::requireLogin();
        $type = (string) ($params['type'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        $entity = $this->findEntity($type, $id);
        if ($entity === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/revisions/index', [
            'entityType' => $type,
            'entity' => $entity,
            'entityLabel' => self::LABELS[$type],
            'editUrl' => sprintf(self::EDIT_PATHS[$type], $id),
            'revisions' => ContentRevision::forEntity($type, $id),
        ]);
    }

    /** @param array<string, string> $params */
    public function restore(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $type = (string) ($params['type'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        $revisionId = (int) ($params['revisionId'] ?? 0);
        if ($this->findEntity($type, $id) === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $revision = ContentRevision::find($revisionId);
        if ($revision === null || (string) $revision['entity_type'] !== $type || (int) $revision['entity_id'] !== $id) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        ContentRevision::restore($revisionId, Auth::id());
        Cache::forgetPrefix('page:');
        Flash::success('Версия восстановлена. Предыдущее состояние также сохранено в истории.');
        header('Location: ' . sprintf(self::EDIT_PATHS[$type], $id));
        exit;
    }

    private function findEntity(string $type, int $id): ?array
    {
        return match ($type) {
            'page' => Page::findById($id),
            'news' => News::findById($id),
            'project' => Project::findById($id),
            default => null,
        };
    }
}
