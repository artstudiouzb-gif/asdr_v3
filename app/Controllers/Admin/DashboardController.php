<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RbacGuard;
use App\Core\SystemHealth;
use App\Core\View;
use App\Models\NotFoundLog;

final class DashboardController
{
    public function index(): void
    {
        Auth::requireLogin();
        $canManageSubmissions = RbacGuard::can('manage_submissions');
        $canManageAudit = RbacGuard::can('manage_audit');

        $counts = [
            'news' => (int) Database::pdo()->query('SELECT COUNT(*) FROM news WHERE deleted_at IS NULL')->fetchColumn(),
            'news_drafts' => (int) Database::pdo()->query("SELECT COUNT(*) FROM news WHERE status = 'draft' AND deleted_at IS NULL")->fetchColumn(),
            'pages' => (int) Database::pdo()->query("SELECT COUNT(*) FROM pages WHERE deleted_at IS NULL AND entity_type = 'page'")->fetchColumn(),
            'projects' => (int) Database::pdo()->query("SELECT COUNT(*) FROM pages WHERE entity_type = 'project' AND deleted_at IS NULL")->fetchColumn(),
            'team' => (int) Database::pdo()->query('SELECT COUNT(*) FROM team_members')->fetchColumn(),
            'forms' => (int) Database::pdo()->query('SELECT COUNT(*) FROM forms')->fetchColumn(),
            'submissions_unread' => $canManageSubmissions
                ? (int) Database::pdo()->query('SELECT COUNT(*) FROM form_submissions WHERE is_read = 0')->fetchColumn()
                : 0,
            'files' => (int) Database::pdo()->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'repo_files' => (int) Database::pdo()->query("SELECT COUNT(*) FROM repo_files WHERE status = 'approved'")->fetchColumn(),
            'repo_downloads' => (int) Database::pdo()->query("SELECT COALESCE(SUM(download_count), 0) FROM repo_files WHERE status = 'approved'")->fetchColumn(),
        ];

        $topRepoDownloads = [];
        try {
            $topRepoDownloads = Database::pdo()->query(
                "SELECT id, title, original_name, download_count, created_at FROM repo_files WHERE status = 'approved' ORDER BY download_count DESC, created_at DESC LIMIT 5"
            )->fetchAll();
        } catch (\Throwable $e) {
            // Игнорируем если таблица пуста
        }

        // Получаем последние 5 действий из журнала аудита
        $recentLogs = $canManageAudit
            ? \App\Models\AuditLog::search([], 1, 5)['items']
            : [];

        // «Продолжить работу»: последние редактированные новости и страницы
        $recentItems = [];
        try {
            $recentItems = Database::pdo()->query(
                "(SELECT 'news' AS kind, id, title, status, updated_at FROM news WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 6)
                 UNION ALL
                 (SELECT 'page' AS kind, id, title, status, updated_at FROM pages WHERE deleted_at IS NULL AND entity_type = 'page' ORDER BY updated_at DESC LIMIT 6)
                 ORDER BY updated_at DESC LIMIT 6"
            )->fetchAll();
        } catch (\Throwable $e) {
            // Игнорируем если таблица пуста
        }

        /*
         * «Требует внимания»: только те факты состояния, с которыми надо
         * что-то делать.
         *
         * Прежде дашборд считал своё маленькое состояние системы — версию PHP,
         * «База данных: подключена», число задач в очереди. Версия статична,
         * подключение к базе тавтологично (страница и так собрана из неё), а
         * пустая очередь — это «всё хорошо», сказанное там, где смотрят в
         * тревоге. Настоящие ответы уже считает `SystemHealth` для раздела
         * «Состояние системы»; второй, наивный список рядом с ним разъехался
         * бы с первым при первой же новой проверке.
         *
         * Берём из него `fail` и `warn`. `unknown` («ни разу не
         * использовалась») сюда не идёт: ненастроенная интеграция — не
         * поломка, а на свежей установке таких строк большинство, и они
         * утопили бы настоящие.
         */
        $attention = [];
        foreach (SystemHealth::groups() as $group) {
            foreach ($group['checks'] as $check) {
                if ($check['state'] === SystemHealth::FAIL || $check['state'] === SystemHealth::WARN) {
                    $attention[] = $check;
                }
            }
        }
        usort(
            $attention,
            static fn (array $a, array $b): int => ($b['state'] === SystemHealth::FAIL ? 1 : 0)
                <=> ($a['state'] === SystemHealth::FAIL ? 1 : 0)
        );
        $attentionTotal = count($attention);
        $attention = array_slice($attention, 0, 6);

        // Битые ссылки: по ним посетитель уже пришёл и ничего не нашёл, а
        // починка — один редирект. Журнал 404 отсеивает сканеров при записи.
        $brokenLinks = [];
        try {
            $brokenLinks = NotFoundLog::top(5);
        } catch (\Throwable $e) {
            // Журнала может не быть на старой базе — дашборд из-за этого не падает.
        }

        // Статистика внутренних поисковых запросов
        $popularSearches = \App\Models\SearchLog::popular(5);

        // Популярные / читаемые новости за 30 дней
        $topReadNews = \App\Models\News::mostViewed(30, 5);

        View::render('admin/dashboard', [
            'user' => Auth::user(),
            'counts' => $counts,
            'recentLogs' => $recentLogs,
            'recentItems' => $recentItems,
            'attention' => $attention,
            'attentionTotal' => $attentionTotal,
            'brokenLinks' => $brokenLinks,
            'popularSearches' => $popularSearches,
            'topReadNews' => $topReadNews,
            'topRepoDownloads' => $topRepoDownloads,
            'canManageSubmissions' => $canManageSubmissions,
            'canManageAudit' => $canManageAudit,
        ]);
    }
}
