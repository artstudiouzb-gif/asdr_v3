<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\RbacGuard;
use App\Core\View;
use App\Models\News;
use App\Models\Page;
use App\Models\Project;

/**
 * Корзина — административный раздел. Право `manage_trash` объявлено в
 * `RbacGuard` вместе с `manage_users` и `manage_settings`, но потребителя у
 * него не было ни одного: контроллер проверял только факт входа, и роль
 * `editor` доходила до безвозвратного удаления страниц, новостей и проектов
 * (`Page::forceDelete()` — это физический DELETE вместе с ревизиями).
 * Объявленный и никем не прочитанный запрет — тот же тихий отказ, что и
 * настройка без потребителя: правило есть, а не действует.
 */
final class TrashController
{
    private const TYPES = ['pages', 'news', 'projects'];

    public function index(): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_trash');
        View::render('admin/trash/index', [
            'pages' => Page::trashed(),
            'news' => News::trashed(),
            'projects' => Project::trashed(),
        ]);
    }

    /** @param array<string, string> $params */
    public function restore(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_trash');
        Csrf::verifyRequest();

        $type = (string) ($params['type'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        if (!in_array($type, self::TYPES, true)) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        match ($type) {
            'pages' => Page::restore($id),
            'news' => News::restore($id),
            'projects' => Project::restore($id),
        };

        Flash::success('Элемент восстановлен из корзины.');
        header('Location: /admin/trash');
        exit;
    }

    /** @param array<string, string> $params */
    public function forceDelete(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_trash');
        Csrf::verifyRequest();

        $type = (string) ($params['type'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        if (!in_array($type, self::TYPES, true)) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        // Собираем привязанные медиа ДО удаления, удаляем сущность, затем
        // чистим файлы-сироты (не используемые больше нигде).
        $media = [];
        if ($type === 'pages') {
            $media = \App\Core\MediaCleaner::collectForPage($id);
            Page::forceDelete($id);
        } elseif ($type === 'news') {
            $news = News::findById($id);
            $media = $news ? \App\Core\MediaCleaner::collectForNews($news) : [];
            News::forceDelete($id);
        } else {
            Project::forceDelete($id);
        }

        \App\Core\MediaCleaner::purgeUnreferenced($media);

        Flash::success('Элемент удалён навсегда.');
        header('Location: /admin/trash');
        exit;
    }

    public function emptyAll(): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_trash');
        Csrf::verifyRequest();

        $this->purgeAll();

        Flash::success('Корзина успешно очищена.');
        header('Location: /admin/trash');
        exit;
    }

    /**
     * Окончательно удаляет всё содержимое корзины и подчищает медиа, на которые
     * больше никто не ссылается.
     *
     * Вынесено из emptyAll() отдельным методом ради проверяемости: обработчик
     * начинается с проверок доступа и заканчивается redirect + exit, а exit
     * не является исключением и не перехватывается try/catch. Из-за этого вызов
     * действия напрямую завершал процесс тест-раннера вместе со всем прогоном.
     *
     * @return array{pages: int, news: int, projects: int} сколько записей удалено
     */
    public function purgeAll(): array
    {
        /** @var array<int, array<string, mixed>> $pages */
        $pages = Page::trashed();
        foreach ($pages as $p) {
            $id = (int) $p['id'];
            $media = \App\Core\MediaCleaner::collectForPage($id);
            Page::forceDelete($id);
            \App\Core\MediaCleaner::purgeUnreferenced($media);
        }

        /** @var array<int, array<string, mixed>> $news */
        $news = News::trashed();
        foreach ($news as $n) {
            $id = (int) $n['id'];
            $media = \App\Core\MediaCleaner::collectForNews($n);
            News::forceDelete($id);
            \App\Core\MediaCleaner::purgeUnreferenced($media);
        }

        /** @var array<int, array<string, mixed>> $projects */
        $projects = Project::trashed();
        foreach ($projects as $pr) {
            $id = (int) $pr['id'];
            Project::forceDelete($id);
        }

        return [
            'pages' => count($pages),
            'news' => count($news),
            'projects' => count($projects),
        ];
    }
}
