<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Goal;
use App\Models\News;
use App\Models\Page;
use App\Models\PhotoAlbum;
use App\Models\Project;
use App\Models\TeamMember;

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
        'goals' => Goal::class,
        'team' => TeamMember::class,
        'albums' => PhotoAlbum::class,
    ];

    /**
     * Набор действий объявлен один раз и для каждого типа свой — подпись,
     * которую видит редактор, и то, что действие реально делает, берутся из
     * одной записи. Второй список в шаблоне разъехался бы с этим молча (тот
     * же случай, ради которого настройка блока объявляется один раз в схеме
     * полей). У «Целей» нет `draft`/`published` — только `is_active`
     * («показывать в карусели»), поэтому там «Включить»/«Выключить», а не
     * «Опубликовать»/«Снять с публикации»: называть чужое состояние своим
     * именем — врать подписью. Дублирования нет ни у goals, ни у team, ни у
     * albums — методов `duplicate()` у этих моделей никогда не было.
     * Удаление у News/Page/Project мягкое (уходит в корзину, `deleted_at`), у
     * Goal/TeamMember/PhotoAlbum — настоящее (`DELETE FROM`), поэтому подпись
     * у них честно «Удалить»: назвать необратимое действие «В корзину»
     * значило бы пообещать восстановление, которого не будет.
     *
     * @return array<string, array{label: string, run: callable(int): bool}>
     */
    private static function actions(string $type): array
    {
        switch ($type) {
            case 'news':
            case 'pages':
            case 'projects':
                $model = self::MAP[$type];

                return [
                    'publish' => [
                        'label' => 'Опубликовать',
                        'run' => static function (int $id) use ($model): bool {
                            $model::setStatus($id, 'published');

                            return true;
                        },
                    ],
                    'unpublish' => [
                        'label' => 'Снять с публикации',
                        'run' => static function (int $id) use ($model): bool {
                            $model::setStatus($id, 'draft');

                            return true;
                        },
                    ],
                    'duplicate' => [
                        'label' => 'Дублировать',
                        'run' => static fn (int $id): bool => $model::duplicate($id) !== null,
                    ],
                    'trash' => [
                        'label' => 'В корзину',
                        'run' => static function (int $id) use ($model): bool {
                            $model::delete($id);

                            return true;
                        },
                    ],
                ];

            case 'team':
                return [
                    'publish' => [
                        'label' => 'Опубликовать',
                        'run' => static function (int $id): bool {
                            TeamMember::setStatus($id, 'published');

                            return true;
                        },
                    ],
                    'unpublish' => [
                        'label' => 'Снять с публикации',
                        'run' => static function (int $id): bool {
                            TeamMember::setStatus($id, 'draft');

                            return true;
                        },
                    ],
                    'delete' => [
                        'label' => 'Удалить',
                        'run' => static function (int $id): bool {
                            TeamMember::delete($id);

                            return true;
                        },
                    ],
                ];

            case 'albums':
                return [
                    'publish' => [
                        'label' => 'Опубликовать',
                        'run' => static function (int $id): bool {
                            PhotoAlbum::setPublished($id, true);

                            return true;
                        },
                    ],
                    'unpublish' => [
                        'label' => 'Снять с публикации',
                        'run' => static function (int $id): bool {
                            PhotoAlbum::setPublished($id, false);

                            return true;
                        },
                    ],
                    'delete' => [
                        'label' => 'Удалить',
                        'run' => static function (int $id): bool {
                            PhotoAlbum::delete($id);

                            return true;
                        },
                    ],
                ];

            case 'goals':
                return [
                    'activate' => [
                        'label' => 'Включить',
                        'run' => static function (int $id): bool {
                            Goal::setActive($id, true);

                            return true;
                        },
                    ],
                    'deactivate' => [
                        'label' => 'Выключить',
                        'run' => static function (int $id): bool {
                            Goal::setActive($id, false);

                            return true;
                        },
                    ],
                    'delete' => [
                        'label' => 'Удалить',
                        'run' => static function (int $id): bool {
                            Goal::delete($id);

                            return true;
                        },
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Подписи действий для выпадающего списка вьюхи, в том порядке, в каком
     * их выполнит `handle()`. Вьюха не заводит свой список опций — она
     * спрашивает этот, иначе список в форме и обработчик рано или поздно
     * разъедутся молча.
     *
     * @return array<string, string>
     */
    public static function labels(string $type): array
    {
        return array_map(static fn (array $a): string => $a['label'], self::actions($type));
    }

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
        $actions = self::actions($type);

        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = (string) ($_POST['bulk_action'] ?? '');
        $returnPath = AdminListQuery::returnPath('/admin/' . $type, $_POST['return_query'] ?? '');

        if ($ids === []) {
            Flash::error('Не выбрано ни одной записи.');
            $this->back($returnPath);
        }

        if (!isset($actions[$action])) {
            Flash::error('Неизвестное действие.');
            $this->back($returnPath);
        }

        $done = 0;
        foreach ($ids as $id) {
            if (($actions[$action]['run'])($id)) {
                $done++;
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
