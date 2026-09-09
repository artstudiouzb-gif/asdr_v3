<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\ErrorLog;

/**
 * Журнал действий администраторов: просмотр с фильтрами по пользователю,
 * пути, датам; пагинация. Плюс журнал ошибок сайта (вкладка «Ошибки»):
 * понятные объяснения, хранение 7 дней или ручная очистка. Только супер-админ.
 */
final class AuditController
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        Auth::requireSuperAdmin();

        $method = strtoupper(trim((string) ($_GET['method'] ?? '')));
        if (!in_array($method, ['AUTH', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $method = '';
        }
        $filters = [
            'user_id' => (int) ($_GET['user_id'] ?? 0),
            'method' => $method,
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = AuditLog::search($filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = AuditLog::search($filters, $page, self::PER_PAGE);
        }

        View::render('admin/audit/index', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'actors' => AuditLog::actors(),
        ]);
    }

    /** Вкладка «Ошибки»: журнал ошибок сайта с фильтрами и пагинацией. */
    public function errors(): void
    {
        Auth::requireSuperAdmin();

        // Просмотр — удобный момент для авточистки просроченных записей.
        ErrorLog::purgeExpired();

        $level = strtoupper(trim((string) ($_GET['level'] ?? '')));
        if (!in_array($level, ['ERROR', 'CRITICAL'], true)) {
            $level = '';
        }
        $filters = [
            'level' => $level,
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = ErrorLog::search($filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = ErrorLog::search($filters, $page, self::PER_PAGE);
        }

        View::render('admin/audit/errors', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
        ]);
    }

    /** Ручная очистка журнала ошибок. */
    public function errorsClear(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $deleted = ErrorLog::clear();
        Flash::success('Журнал ошибок очищен (удалено записей: ' . $deleted . ').');
        header('Location: /admin/audit/errors');
        exit;
    }
}
