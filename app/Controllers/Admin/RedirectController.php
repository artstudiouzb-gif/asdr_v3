<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Redirect;

/**
 * Менеджер 301/302-редиректов (супер-админ): добавление по одному, массовый
 * импорт списком, включение/выключение, удаление, счётчик срабатываний.
 */
final class RedirectController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/redirects/index', [
            'items' => Redirect::all(),
            'notFound' => \App\Models\NotFoundLog::top(50),
            // Быстрое создание из 404-трекера: ?from= предзаполняет форму.
            'prefillFrom' => (string) ($_GET['from'] ?? ''),
        ]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $from = (string) ($_POST['from_path'] ?? '');
        $ok = Redirect::create(
            $from,
            (string) ($_POST['to_url'] ?? ''),
            (int) ($_POST['code'] ?? 301)
        );
        if ($ok) {
            // Путь закрыт редиректом — убираем его из 404-трекера.
            $normalized = Redirect::normalizePath($from);
            if ($normalized !== null) {
                \App\Models\NotFoundLog::deleteByPath($normalized);
            }
            Flash::success('Редирект добавлен.');
        } else {
            Flash::error('Не удалось добавить: проверьте адреса (путь «откуда» должен начинаться с «/», не может быть корнем или /admin; такой путь мог быть добавлен ранее).');
        }
        $this->back();
    }

    public function import(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        [$added, $skipped] = Redirect::import((string) ($_POST['list'] ?? ''));
        if ($added > 0) {
            Flash::success("Импортировано редиректов: {$added}." . ($skipped > 0 ? " Пропущено (ошибки/дубли): {$skipped}." : ''));
        } else {
            Flash::error('Ничего не импортировано. Формат строки: «/старый-путь /новый-путь» или «/старый -> https://site.uz/новый», третьим словом можно указать 302.');
        }
        $this->back();
    }

    /** @param array<string, string> $params */
    public function toggle(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        Redirect::setActive((int) $params['id'], ($_POST['active'] ?? '') === '1');
        Flash::success('Состояние редиректа обновлено.');
        $this->back();
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        Redirect::delete((int) $params['id']);
        Flash::success('Редирект удалён.');
        $this->back();
    }

    /**
     * Скрыть запись 404-трекера (не создавая редирект).
     * @param array<string, string> $params
     */
    public function dismissNotFound(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        \App\Models\NotFoundLog::delete((int) $params['id']);
        Flash::success('Запись убрана из списка 404.');
        $this->back();
    }

    private function back(): never
    {
        header('Location: /admin/redirects');
        exit;
    }
}
