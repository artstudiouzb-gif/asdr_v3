<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\News;
use App\Models\Page;
use App\Models\Project;

/**
 * Массовые операции над списками (задача 91) и дублирование (задача 80):
 * опубликовать / снять с публикации / в корзину / дублировать выбранные.
 */
final class BulkController
{
    private const MAP = [
        'news' => News::class,
        'pages' => Page::class,
        'projects' => Project::class,
    ];

    /** @param array<string, string> $params */
    public function handle(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $type = (string) ($params['type'] ?? '');
        if (!isset(self::MAP[$type])) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        /** @var class-string $model */
        $model = self::MAP[$type];

        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = (string) ($_POST['bulk_action'] ?? '');
        $returnPath = AdminListQuery::returnPath('/admin/' . $type, $_POST['return_query'] ?? '');

        if ($ids === []) {
            Flash::error('Не выбрано ни одной записи.');
            $this->back($returnPath);
        }

        $done = 0;
        foreach ($ids as $id) {
            switch ($action) {
                case 'publish':
                    $model::setStatus($id, 'published');
                    $done++;
                    break;
                case 'unpublish':
                    $model::setStatus($id, 'draft');
                    $done++;
                    break;
                case 'trash':
                    $model::delete($id);
                    $done++;
                    break;
                case 'duplicate':
                    if ($model::duplicate($id) !== null) {
                        $done++;
                    }
                    break;
                default:
                    Flash::error('Неизвестное действие.');
                    $this->back($returnPath);
            }
        }

        // Публикация/снятие/удаление/копия меняют фронтенд — сбрасываем кеш страниц.
        Cache::forgetPrefix('page:');

        Flash::success("Обработано записей: {$done}.");
        $this->back($returnPath);
    }

    private function back(string $returnPath): never
    {
        header('Location: ' . $returnPath);
        exit;
    }
}
