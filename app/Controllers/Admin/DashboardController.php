<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RbacGuard;
use App\Core\View;

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

        // Последние поступившие заявки с сайта
        $recentSubmissions = [];
        if ($canManageSubmissions) {
            try {
                $recentSubmissions = Database::pdo()->query(
                    'SELECT fs.id, fs.form_id, fs.data_json, fs.is_read, fs.created_at, f.name AS form_title
                     FROM form_submissions fs
                     LEFT JOIN forms f ON fs.form_id = f.id
                     ORDER BY fs.id DESC LIMIT 4'
                )->fetchAll();
            } catch (\Throwable $e) {
                // Игнорируем если таблица пуста
            }
        }

        // Статистика здоровья и конфигурации системы
        $systemHealth = [
            'php_version' => PHP_VERSION,
            'maintenance' => \App\Models\Setting::get('maintenance_mode', '0') === '1',
            'active_langs_count' => count(\App\Models\Language::activeCodes()),
            'telegram_linked' => \App\Core\TelegramBot::isConfigured() || \App\Core\TelegramGateway::isConfigured(),
            'queue_pending' => (int) (Database::isConnected() ? Database::pdo()->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn() : 0),
            'queue_failed' => (int) (Database::isConnected() ? Database::pdo()->query("SELECT COUNT(*) FROM jobs WHERE status = 'failed'")->fetchColumn() : 0),
        ];

        // Статистика заявок за последние 7 дней для графика
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chartData[$date] = 0;
        }
        if ($canManageSubmissions) {
            try {
                $stmt = Database::pdo()->query('SELECT DATE(created_at) as d, COUNT(*) as c FROM form_submissions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at)');
                foreach ($stmt->fetchAll() as $row) {
                    if (isset($chartData[$row['d']])) {
                        $chartData[$row['d']] = (int) $row['c'];
                    }
                }
            } catch (\Throwable $e) {
                // Игнорируем ошибки при отсутствии таблицы
            }
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
            'recentSubmissions' => $recentSubmissions,
            'systemHealth' => $systemHealth,
            'popularSearches' => $popularSearches,
            'topReadNews' => $topReadNews,
            'topRepoDownloads' => $topRepoDownloads,
            'chartData' => $chartData,
            'canManageSubmissions' => $canManageSubmissions,
            'canManageAudit' => $canManageAudit,
        ]);
    }
}
