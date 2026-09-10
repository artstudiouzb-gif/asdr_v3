<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\BlockController as AdminBlockController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\FileController as AdminFileController;
use App\Controllers\Admin\FormController as AdminFormController;
use App\Controllers\Admin\HeaderController as AdminHeaderController;
use App\Controllers\Admin\LanguageController as AdminLanguageController;
use App\Controllers\Admin\MenuController as AdminMenuController;
use App\Controllers\Admin\NewsController as AdminNewsController;
use App\Controllers\Admin\PageController as AdminPageController;
use App\Controllers\Admin\ProjectController as AdminProjectController;
use App\Controllers\Admin\ContentRevisionController as AdminContentRevisionController;
use App\Controllers\Admin\HealthController as AdminHealthController;
use App\Controllers\Admin\LogController as AdminLogController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\TeamController as AdminTeamController;
use App\Controllers\Admin\GoalController as AdminGoalController;
use App\Controllers\Site\GoalController as SiteGoalController;
use App\Controllers\Admin\WidgetController as AdminWidgetController;
use App\Controllers\InstallController;
use App\Controllers\Site\FormController as SiteFormController;
use App\Controllers\Site\NewsController as SiteNewsController;
use App\Controllers\Site\PageController as SitePageController;
use App\Core\Router;

// --- Веб-инсталлятор: обработка /install и редирект неустановленной системы ---
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Если переписанный запрос к отсутствующему файлу в uploads пришёл в index.php,
// но есть одноимённый webp — отдаём его напрямую (fallback для окружений без
// mod_rewrite).
//
// Имя файла берётся из запроса и уходит в readfile(), поэтому границы каталога
// проверяются дважды. Набор символов не пропускает `..` вовсе, а realpath()
// сверяет уже разрешённый путь с корнем загрузок: без этого запрос вида
// `/uploads/public/../../config/config.jpg` собирал путь за пределами каталога
// (проверено — строка пути действительно выходила наружу). Читать можно было
// только *.webp, и Apache такой путь обычно нормализует раньше PHP, но
// собирать путь из запроса без границ нельзя в любом случае.
if (str_starts_with($requestPath, '/public/uploads/public/')) {
    $requestPath = substr($requestPath, 7);
}

if (str_starts_with($requestPath, '/uploads/public/')
    && preg_match('#^/uploads/public/([A-Za-z0-9._/-]+)\.(jpe?g|png)$#i', $requestPath, $m)
    && !str_contains($m[1], '..')) {
    $uploadsRoot = realpath(__DIR__ . '/uploads/public');
    $webpDisk = realpath(__DIR__ . '/uploads/public/' . $m[1] . '.webp');
    if ($uploadsRoot !== false
        && $webpDisk !== false
        && str_starts_with($webpDisk, $uploadsRoot . DIRECTORY_SEPARATOR)
        && is_file($webpDisk)
    ) {
        header('Content-Type: image/webp');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($webpDisk);
        exit;
    }
}

if (!APP_INSTALLED && !str_starts_with($requestPath, '/install')) {
    header('Location: /install');
    exit;
}

if (str_starts_with($requestPath, '/install')) {
    $installRouter = new Router();
    $installRouter->get('/install', [InstallController::class, 'step1']);
    $installRouter->get('/install/step2', [InstallController::class, 'step2']);
    $installRouter->post('/install/step2', [InstallController::class, 'step2Submit']);
    $installRouter->get('/install/step3', [InstallController::class, 'step3']);
    $installRouter->post('/install/step3', [InstallController::class, 'step3Submit']);
    $installRouter->get('/install/step4', [InstallController::class, 'step4']);
    $installRouter->post('/install/step4', [InstallController::class, 'step4Submit']);
    $installRouter->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    exit;
}

// Во время согласованного бэкапа новые HTTP-изменения временно блокируются.
// GET/HEAD остаются доступны; CLI-воркер самого бэкапа не проходит этот код.
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'HEAD', 'OPTIONS'], true)
    && \App\Core\Backup::isWriteGuardActive()) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('Выполняется резервное копирование. Повторите изменение через минуту.');
}

// --- Режим обслуживания: гостям 503-заглушка, авторизованным админам —
// полный доступ (чтобы можно было наполнять сайт при закрытом фронтенде). ---
$maintenancePath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
// Оговорка про обновление: режим обслуживания включает и оно, а процесс
// обновления может умереть (таймаут хостинга, OOM-убийца, перезагрузка) —
// снять флаг тогда некому, и сайт остался бы закрытым навсегда. Поэтому
// заглушку не показываем, если её включило обновление, переставшее
// отчитываться (см. App\Core\UpdateState::maintenanceStuck).
if (\App\Models\Setting::get('maintenance_mode', '0') === '1'
    && !\App\Core\UpdateState::maintenanceStuck()
    && !(\App\Core\Session::hasCookie() && \App\Core\Auth::check())
    && !str_starts_with($maintenancePath, '/admin')
    && !str_starts_with($maintenancePath, '/repo')
    && !str_starts_with($maintenancePath, '/assets')) {
    http_response_code(503);
    header('Retry-After: 3600');
    \App\Core\View::render('errors/maintenance');
    exit;
}

// --- Динамический ресайзер миниатюр на лету (On-the-fly Media Thumbnailer) ---
if (str_starts_with($requestPath, '/media/thumb/')) {
    if (preg_match('#^/media/thumb/(\d+)x(\d+)/([a-z]+)/(\d+)/([a-f0-9]{16})/(.+)$#', $requestPath, $matches)) {
        \App\Core\MediaResizer::handle(
            (int) $matches[1],
            (int) $matches[2],
            (string) $matches[3],
            (int) $matches[4],
            (string) $matches[5],
            rawurldecode((string) $matches[6])
        );
        exit;
    }
}

// --- Динамический генератор брендированных OpenGraph-обложек ---
if (str_starts_with($requestPath, '/og/news/')) {
    if (preg_match('#^/og/news/([a-z0-9-]+)\.png$#i', $requestPath, $matches)) {
        $news = \App\Models\News::findBySlug($matches[1]);
        if ($news) {
            \App\Core\OpenGraphImage::render((string) ($news['title'] ?? 'Новость'), (string) ($news['category_name'] ?? 'Новость'), (string) ($news['published_at'] ?? ''));
            exit;
        }
    }
}

$router = new Router();

// --- Установщик после установки: аппаратно заблокирован (403) ---
$router->get('/install', [InstallController::class, 'step1']);
$router->post('/install/step2', [InstallController::class, 'step2Submit']);
$router->post('/install/step3', [InstallController::class, 'step3Submit']);
$router->post('/install/step4', [InstallController::class, 'step4Submit']);

// --- Admin: аутентификация (без требования логина) ---
$router->get('/admin/login', [AdminAuthController::class, 'showLogin']);
$router->post('/admin/login', [AdminAuthController::class, 'login']);
$router->get('/admin/login/2fa', [AdminAuthController::class, 'showTwoFactor']);
$router->post('/admin/login/2fa', [AdminAuthController::class, 'verifyTwoFactor']);
$router->post('/admin/login/2fa/resend', [AdminAuthController::class, 'resendCode']);
$router->post('/admin/logout', [AdminAuthController::class, 'logout']);

// --- Admin: восстановление пароля (без требования логина) ---
$router->get('/admin/forgot', [\App\Controllers\Admin\PasswordResetController::class, 'showForgot']);
$router->post('/admin/forgot', [\App\Controllers\Admin\PasswordResetController::class, 'requestReset']);
$router->get('/admin/reset/{token}', [\App\Controllers\Admin\PasswordResetController::class, 'showReset']);
$router->post('/admin/reset', [\App\Controllers\Admin\PasswordResetController::class, 'submitReset']);

// --- Admin: профиль, сессии, backup-коды ---
$router->get('/admin/profile', [\App\Controllers\Admin\ProfileController::class, 'index']);
$router->post('/admin/profile/password', [\App\Controllers\Admin\ProfileController::class, 'changePassword']);
$router->post('/admin/profile/phone', [\App\Controllers\Admin\ProfileController::class, 'updatePhone']);
$router->post('/admin/profile/admin-lang', [\App\Controllers\Admin\ProfileController::class, 'updateAdminLang']);
$router->post('/admin/profile/admin-theme', [\App\Controllers\Admin\ProfileController::class, 'updateAdminTheme']);
$router->post('/admin/profile/telegram/link', [\App\Controllers\Admin\ProfileController::class, 'linkTelegram']);
$router->post('/admin/profile/telegram/unlink', [\App\Controllers\Admin\ProfileController::class, 'unlinkTelegram']);
$router->post('/admin/profile/totp/enable', [\App\Controllers\Admin\ProfileController::class, 'enableTotp']);
$router->post('/admin/profile/totp/disable', [\App\Controllers\Admin\ProfileController::class, 'disableTotp']);
$router->post('/admin/profile/sessions/revoke-others', [\App\Controllers\Admin\ProfileController::class, 'revokeOthers']);
$router->post('/admin/profile/sessions/{id}/revoke', [\App\Controllers\Admin\ProfileController::class, 'revokeSession']);

// --- Admin: дашборд ---
$router->get('/admin', [DashboardController::class, 'index']);

// --- Admin: массовые операции + быстрый поиск (этап 12.4) ---
$router->post('/admin/bulk/{type}', [\App\Controllers\Admin\BulkController::class, 'handle']);
$router->get('/admin/search', [\App\Controllers\Admin\SearchController::class, 'query']);

// --- Admin: история версий страниц, новостей и проектов ---
$router->get('/admin/health', [AdminHealthController::class, 'index']);
$router->get('/admin/logs', [AdminLogController::class, 'index']);
$router->post('/admin/logs/clear', [AdminLogController::class, 'clear']);
$router->get('/admin/revisions/{type}/{id}', [AdminContentRevisionController::class, 'index']);
$router->post('/admin/revisions/{type}/{id}/{revisionId}/restore', [AdminContentRevisionController::class, 'restore']);

// --- Admin: новости ---
$router->get('/admin/news', [AdminNewsController::class, 'index']);
$router->get('/admin/news/import', [\App\Controllers\Admin\NewsImportController::class, 'index']);
$router->post('/admin/news/import/upload', [\App\Controllers\Admin\NewsImportController::class, 'uploadChunk']);
$router->post('/admin/news/import/inspect', [\App\Controllers\Admin\NewsImportController::class, 'inspect']);
$router->post('/admin/news/import/backup', [\App\Controllers\Admin\NewsImportController::class, 'backup']);
$router->post('/admin/news/import/run', [\App\Controllers\Admin\NewsImportController::class, 'importBatch']);
$router->post('/admin/news/import/discard', [\App\Controllers\Admin\NewsImportController::class, 'discard']);
$router->get('/admin/news/create', [AdminNewsController::class, 'create']);
$router->post('/admin/news/create', [AdminNewsController::class, 'store']);
$router->get('/admin/news/{id}/edit', [AdminNewsController::class, 'edit']);
$router->post('/admin/news/ai-summary', [AdminNewsController::class, 'generateAiSummary']);
$router->get('/admin/news/{id}/preview', [AdminNewsController::class, 'preview']);
$router->post('/admin/news/{id}/edit', [AdminNewsController::class, 'update']);
$router->post('/admin/news/{id}/delete', [AdminNewsController::class, 'destroy']);
$router->post('/admin/news/{id}/create-translation', [AdminNewsController::class, 'createTranslation']);
$router->post('/admin/news/{id}/duplicate', [AdminNewsController::class, 'duplicate']);
$router->post('/admin/news/{id}/social', [AdminNewsController::class, 'pushSocial']);
$router->get('/admin/news/{id}/social-preview', [AdminNewsController::class, 'socialPreview']);

// --- Admin: категории новостей ---
// Регистрируются после /admin/news/*, но конфликта нет: адрес другой
// («news-categories»), а не вложенный сегмент новостей.
$router->get('/admin/news-categories', [\App\Controllers\Admin\NewsCategoryController::class, 'index']);
$router->post('/admin/news-categories/create', [\App\Controllers\Admin\NewsCategoryController::class, 'store']);
$router->get('/admin/news-categories/{id}/edit', [\App\Controllers\Admin\NewsCategoryController::class, 'edit']);
$router->post('/admin/news-categories/{id}/update', [\App\Controllers\Admin\NewsCategoryController::class, 'update']);
$router->post('/admin/news-categories/{id}/delete', [\App\Controllers\Admin\NewsCategoryController::class, 'destroy']);

// --- Admin: обложки (Hero) ---
// Обложка — отдельный тип контента: блок страницы только выбирает, какую из
// них вывести, поэтому у раздела свой адрес, а не вложенность в страницы.
$router->get('/admin/heroes', [\App\Controllers\Admin\HeroController::class, 'index']);
$router->post('/admin/heroes/create', [\App\Controllers\Admin\HeroController::class, 'store']);
$router->get('/admin/heroes/{id}/edit', [\App\Controllers\Admin\HeroController::class, 'edit']);
$router->post('/admin/heroes/{id}/update', [\App\Controllers\Admin\HeroController::class, 'update']);
$router->post('/admin/heroes/{id}/preset', [\App\Controllers\Admin\HeroController::class, 'applyPreset']);
$router->post('/admin/heroes/{id}/duplicate', [\App\Controllers\Admin\HeroController::class, 'duplicate']);
$router->post('/admin/heroes/{id}/delete', [\App\Controllers\Admin\HeroController::class, 'destroy']);
$router->post('/admin/heroes/{id}/slides/create', [\App\Controllers\Admin\HeroController::class, 'slideCreate']);
$router->post('/admin/heroes/{id}/slides/reorder', [\App\Controllers\Admin\HeroController::class, 'reorder']);
$router->get('/admin/heroes/{id}/slides/{slide}/edit', [\App\Controllers\Admin\HeroController::class, 'slideEdit']);
$router->post('/admin/heroes/{id}/slides/{slide}/update', [\App\Controllers\Admin\HeroController::class, 'slideUpdate']);
$router->post('/admin/heroes/{id}/slides/{slide}/duplicate', [\App\Controllers\Admin\HeroController::class, 'slideDuplicate']);
$router->post('/admin/heroes/{id}/slides/{slide}/toggle', [\App\Controllers\Admin\HeroController::class, 'slideToggle']);
$router->post('/admin/heroes/{id}/slides/{slide}/delete', [\App\Controllers\Admin\HeroController::class, 'slideDestroy']);

// --- Admin: страницы + конструктор блоков ---
$router->get('/admin/pages', [AdminPageController::class, 'index']);
$router->get('/admin/pages/create', [AdminPageController::class, 'create']);
$router->post('/admin/pages/create', [AdminPageController::class, 'store']);
$router->get('/admin/pages/{id}/edit', [AdminPageController::class, 'edit']);
$router->get('/admin/pages/{id}/preview', [AdminPageController::class, 'preview']);
$router->post('/admin/pages/{id}/edit', [AdminPageController::class, 'update']);
$router->post('/admin/pages/{id}/delete', [AdminPageController::class, 'destroy']);
$router->post('/admin/pages/{id}/make-home', [AdminPageController::class, 'makeHome']);
$router->post('/admin/pages/{id}/copy-language-blocks', [AdminPageController::class, 'copyLanguageBlocks']);
$router->post('/admin/pages/{id}/create-translation', [AdminPageController::class, 'createTranslation']);
$router->post('/admin/pages/{id}/duplicate', [AdminPageController::class, 'duplicate']);
$router->post('/admin/pages/{id}/blocks/add', [AdminBlockController::class, 'store']);

$router->get('/admin/blocks/{id}/edit', [AdminBlockController::class, 'edit']);
$router->post('/admin/blocks/{id}/edit', [AdminBlockController::class, 'update']);
$router->post('/admin/blocks/{id}/delete', [AdminBlockController::class, 'destroy']);
$router->post('/admin/blocks/{id}/move', [AdminBlockController::class, 'move']);
$router->post('/admin/blocks/{id}/toggle', [AdminBlockController::class, 'toggle']);
$router->post('/admin/blocks/reorder', [AdminBlockController::class, 'reorder']);
$router->get('/admin/blocks/{id}/revisions', [AdminBlockController::class, 'revisions']);
$router->post('/admin/blocks/{id}/revisions/restore', [AdminBlockController::class, 'restoreRevision']);

// --- Admin: шаблоны блоков (сниппеты, задача 133) ---
$router->post('/admin/pages/{id}/snippets/save', [\App\Controllers\Admin\SnippetController::class, 'save']);
$router->post('/admin/pages/{id}/snippets/insert', [\App\Controllers\Admin\SnippetController::class, 'insert']);
$router->post('/admin/pages/{id}/presets/apply', [\App\Controllers\Admin\SnippetController::class, 'applyPreset']);
$router->get('/admin/snippets/export', [\App\Controllers\Admin\SnippetController::class, 'export']);
$router->post('/admin/snippets/import', [\App\Controllers\Admin\SnippetController::class, 'import']);
$router->post('/admin/snippets/{id}/delete', [\App\Controllers\Admin\SnippetController::class, 'destroy']);

// --- Admin: проекты ---
$router->get('/admin/projects', [AdminProjectController::class, 'index']);
$router->get('/admin/projects/create', [AdminProjectController::class, 'create']);
$router->post('/admin/projects/create', [AdminProjectController::class, 'store']);
$router->get('/admin/projects/{id}/edit', [AdminProjectController::class, 'edit']);
$router->post('/admin/projects/{id}/edit', [AdminProjectController::class, 'update']);
$router->post('/admin/projects/{id}/delete', [AdminProjectController::class, 'destroy']);
$router->post('/admin/projects/{id}/create-translation', [AdminProjectController::class, 'createTranslation']);
$router->post('/admin/projects/{id}/duplicate', [AdminProjectController::class, 'duplicate']);

// --- Admin: команда ---
$router->get('/admin/team', [AdminTeamController::class, 'index']);
$router->get('/admin/team/create', [AdminTeamController::class, 'create']);
$router->post('/admin/team/create', [AdminTeamController::class, 'store']);
$router->get('/admin/team/{id}/edit', [AdminTeamController::class, 'edit']);
$router->post('/admin/team/{id}/edit', [AdminTeamController::class, 'update']);
$router->post('/admin/team/{id}/delete', [AdminTeamController::class, 'destroy']);

// --- Admin: формы и заявки ---
$router->get('/admin/forms', [AdminFormController::class, 'index']);
$router->get('/admin/forms/submissions', [AdminFormController::class, 'allSubmissions']);
$router->get('/admin/forms/submissions/{id}', [AdminFormController::class, 'submission']);
$router->get('/admin/forms/create', [AdminFormController::class, 'create']);
$router->post('/admin/forms/create', [AdminFormController::class, 'store']);
$router->get('/admin/forms/{id}/edit', [AdminFormController::class, 'edit']);
$router->post('/admin/forms/{id}/edit', [AdminFormController::class, 'update']);
$router->post('/admin/forms/{id}/delete', [AdminFormController::class, 'destroy']);
$router->get('/admin/forms/{id}/submissions', [AdminFormController::class, 'submissions']);
$router->post('/admin/forms/submissions/{id}/delete', [AdminFormController::class, 'deleteSubmission']);

// --- Admin: языки ---
$router->get('/admin/languages', [AdminLanguageController::class, 'index']);
$router->post('/admin/languages/create', [AdminLanguageController::class, 'store']);
$router->post('/admin/languages/{id}/edit', [AdminLanguageController::class, 'update']);
$router->post('/admin/languages/{id}/delete', [AdminLanguageController::class, 'destroy']);

// --- Admin: конструктор меню ---
$router->get('/admin/menu', [AdminMenuController::class, 'index']);
$router->post('/admin/menu/create', [AdminMenuController::class, 'store']);
$router->post('/admin/menu/reorder', [AdminMenuController::class, 'reorder']);
$router->post('/admin/menu/synchronize', [AdminMenuController::class, 'synchronize']);
$router->post('/admin/menu/{id}/edit', [AdminMenuController::class, 'update']);
$router->post('/admin/menu/{id}/delete', [AdminMenuController::class, 'destroy']);
$router->post('/admin/menu/{id}/move', [AdminMenuController::class, 'move']);

// --- Admin: конструктор шапки (mini app) ---
$router->get('/admin/header', [AdminHeaderController::class, 'index']);
$router->post('/admin/header', [AdminHeaderController::class, 'update']);

// --- Admin: конструктор подвала ---
$router->get('/admin/footer', [\App\Controllers\Admin\FooterController::class, 'index']);
$router->post('/admin/footer', [\App\Controllers\Admin\FooterController::class, 'update']);

// --- Admin: производительность ---
$router->get('/admin/update', [\App\Controllers\Admin\UpdateController::class, 'index']);
// Заказ обновления: пишет намерение и возвращает редирект. Саму замену делает
// app/Console/update_worker.php — веб-запрос её не пережил бы (см. контроллер).
$router->post('/admin/update/request', [\App\Controllers\Admin\UpdateController::class, 'request']);
$router->post('/admin/update/reset', [\App\Controllers\Admin\UpdateController::class, 'reset']);
$router->get('/admin/seo', [\App\Controllers\Admin\SeoController::class, 'index']);
$router->post('/admin/seo/run', [\App\Controllers\Admin\SeoController::class, 'run']);
$router->get('/admin/performance', [\App\Controllers\Admin\PerformanceController::class, 'index']);
$router->post('/admin/performance', [\App\Controllers\Admin\PerformanceController::class, 'update']);
$router->post('/admin/performance/clear-cache', [\App\Controllers\Admin\PerformanceController::class, 'clearCache']);
$router->post('/admin/performance/reset-opcache', [\App\Controllers\Admin\PerformanceController::class, 'resetOpcache']);
$router->post('/admin/performance/optimize-images', [\App\Controllers\Admin\PerformanceController::class, 'optimizeImages']);
$router->post('/admin/performance/fix-permissions', [\App\Controllers\Admin\PerformanceController::class, 'fixPermissions']);
$router->post('/admin/cloudflare/verify', [\App\Controllers\Admin\PerformanceController::class, 'cloudflareVerify']);
$router->post('/admin/cloudflare/purge', [\App\Controllers\Admin\PerformanceController::class, 'cloudflarePurge']);

// --- Admin: база данных и миграции ---
$router->get('/admin/database', [\App\Controllers\Admin\DatabaseController::class, 'index']);
$router->post('/admin/database/migrate', [\App\Controllers\Admin\DatabaseController::class, 'migrate']);
$router->post('/admin/database/optimize', [\App\Controllers\Admin\DatabaseController::class, 'optimize']);

// --- Admin: боковые виджеты ---
$router->get('/admin/goals', [AdminGoalController::class, 'index']);
$router->get('/admin/goals/create', [AdminGoalController::class, 'create']);
$router->post('/admin/goals/create', [AdminGoalController::class, 'store']);
$router->get('/admin/goals/{id}/edit', [AdminGoalController::class, 'edit']);
$router->post('/admin/goals/{id}/edit', [AdminGoalController::class, 'update']);
$router->post('/admin/goals/{id}/delete', [AdminGoalController::class, 'destroy']);

$router->get('/admin/widgets', [AdminWidgetController::class, 'index']);
$router->get('/admin/widgets/create', [AdminWidgetController::class, 'create']);
$router->post('/admin/widgets/create', [AdminWidgetController::class, 'store']);
$router->get('/admin/widgets/{id}/edit', [AdminWidgetController::class, 'edit']);
$router->post('/admin/widgets/{id}/edit', [AdminWidgetController::class, 'update']);
$router->post('/admin/widgets/{id}/delete', [AdminWidgetController::class, 'destroy']);
$router->post('/admin/widgets/{id}/move', [AdminWidgetController::class, 'move']);

// --- Admin: корзина (soft deletes) ---
$router->get('/admin/trash', [\App\Controllers\Admin\TrashController::class, 'index']);
$router->post('/admin/trash/empty', [\App\Controllers\Admin\TrashController::class, 'emptyAll']);
$router->post('/admin/trash/{type}/{id}/restore', [\App\Controllers\Admin\TrashController::class, 'restore']);
$router->post('/admin/trash/{type}/{id}/force-delete', [\App\Controllers\Admin\TrashController::class, 'forceDelete']);

// --- Admin: пользователи (только супер-администратор) ---
$router->get('/admin/audit', [\App\Controllers\Admin\AuditController::class, 'index']);
$router->get('/admin/audit/errors', [\App\Controllers\Admin\AuditController::class, 'errors']);
$router->post('/admin/audit/errors/clear', [\App\Controllers\Admin\AuditController::class, 'errorsClear']);
$router->get('/admin/subscribers', [\App\Controllers\Admin\SubscriberController::class, 'index']);
$router->post('/admin/subscribers/send-digest', [\App\Controllers\Admin\SubscriberController::class, 'sendDigest']);
$router->post('/admin/subscribers/{id}/delete', [\App\Controllers\Admin\SubscriberController::class, 'destroy']);
$router->get('/admin/albums', [\App\Controllers\Admin\AlbumController::class, 'index']);
$router->post('/admin/albums/create', [\App\Controllers\Admin\AlbumController::class, 'store']);
$router->get('/admin/albums/{id}/edit', [\App\Controllers\Admin\AlbumController::class, 'edit']);
$router->post('/admin/albums/{id}/update', [\App\Controllers\Admin\AlbumController::class, 'update']);
$router->post('/admin/albums/{id}/delete', [\App\Controllers\Admin\AlbumController::class, 'destroy']);
$router->post('/admin/albums/{id}/images/add', [\App\Controllers\Admin\AlbumController::class, 'addImage']);
$router->post('/admin/albums/{id}/images/{imageId}/delete', [\App\Controllers\Admin\AlbumController::class, 'deleteImage']);
$router->get('/admin/videos', [\App\Controllers\Admin\VideoController::class, 'index']);
$router->post('/admin/videos/create', [\App\Controllers\Admin\VideoController::class, 'store']);
$router->post('/admin/videos/bulk', [\App\Controllers\Admin\VideoController::class, 'bulk']);
$router->post('/admin/videos/youtube/settings', [\App\Controllers\Admin\VideoController::class, 'importSettings']);
$router->post('/admin/videos/youtube/sync', [\App\Controllers\Admin\VideoController::class, 'importRun']);
$router->get('/admin/videos/{id}/edit', [\App\Controllers\Admin\VideoController::class, 'edit']);
$router->post('/admin/videos/{id}/update', [\App\Controllers\Admin\VideoController::class, 'update']);
$router->post('/admin/videos/{id}/delete', [\App\Controllers\Admin\VideoController::class, 'destroy']);
$router->get('/admin/redirects', [\App\Controllers\Admin\RedirectController::class, 'index']);
$router->post('/admin/redirects/create', [\App\Controllers\Admin\RedirectController::class, 'store']);
$router->post('/admin/redirects/import', [\App\Controllers\Admin\RedirectController::class, 'import']);
$router->post('/admin/redirects/{id}/toggle', [\App\Controllers\Admin\RedirectController::class, 'toggle']);
$router->post('/admin/redirects/{id}/delete', [\App\Controllers\Admin\RedirectController::class, 'destroy']);
$router->post('/admin/redirects/404/{id}/delete', [\App\Controllers\Admin\RedirectController::class, 'dismissNotFound']);
$router->get('/admin/users', [\App\Controllers\Admin\UserController::class, 'index']);
$router->post('/admin/users/create', [\App\Controllers\Admin\UserController::class, 'store']);
$router->post('/admin/users/{id}/delete', [\App\Controllers\Admin\UserController::class, 'destroy']);

// --- Admin: конструктор типов контента (супер-админ) ---
$router->get('/admin/content-types', [\App\Controllers\Admin\ContentTypeController::class, 'index']);
$router->post('/admin/content-types/create', [\App\Controllers\Admin\ContentTypeController::class, 'store']);
$router->get('/admin/content-types/{id}/fields', [\App\Controllers\Admin\ContentTypeController::class, 'fields']);
$router->post('/admin/content-types/{id}/fields', [\App\Controllers\Admin\ContentTypeController::class, 'saveFields']);
$router->post('/admin/content-types/{id}/delete', [\App\Controllers\Admin\ContentTypeController::class, 'destroy']);

// --- Admin: авто-CRUD записей типов контента ---
$router->get('/admin/content/{type}', [\App\Controllers\Admin\ContentEntryController::class, 'index']);
$router->get('/admin/content/{type}/create', [\App\Controllers\Admin\ContentEntryController::class, 'create']);
$router->post('/admin/content/{type}/create', [\App\Controllers\Admin\ContentEntryController::class, 'store']);
$router->get('/admin/content/{type}/{id}/edit', [\App\Controllers\Admin\ContentEntryController::class, 'edit']);
$router->post('/admin/content/{type}/{id}/edit', [\App\Controllers\Admin\ContentEntryController::class, 'update']);
$router->post('/admin/content/{type}/{id}/delete', [\App\Controllers\Admin\ContentEntryController::class, 'destroy']);

// --- Admin: тема-билдер (дизайн сайта, супер-админ) ---
$router->get('/admin/design', [\App\Controllers\Admin\DesignController::class, 'index']);
$router->post('/admin/design', [\App\Controllers\Admin\DesignController::class, 'update']);
$router->post('/admin/design/preset', [\App\Controllers\Admin\DesignController::class, 'applyPreset']);
$router->post('/admin/design/preset/save', [\App\Controllers\Admin\DesignController::class, 'savePreset']);
$router->post('/admin/design/preset/delete', [\App\Controllers\Admin\DesignController::class, 'deletePreset']);
$router->get('/admin/design/preview', [\App\Controllers\Admin\DesignController::class, 'preview']);

// --- Admin: настройки дизайна ---
$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'update']);
$router->post('/admin/settings/test-email', [SettingsController::class, 'testMail']);
$router->post('/admin/settings/demo-content', [SettingsController::class, 'seedDemo']);
$router->post('/admin/settings/demo-reset', [SettingsController::class, 'resetDemo']);

// --- Admin: авто-публикация в соцсети (только супер-админ) ---
$router->get('/admin/social', [\App\Controllers\Admin\SocialController::class, 'index']);
$router->post('/admin/social', [\App\Controllers\Admin\SocialController::class, 'update']);
$router->post('/admin/social/run', [\App\Controllers\Admin\SocialController::class, 'runNow']);
$router->post('/admin/social/retry', [\App\Controllers\Admin\SocialController::class, 'retry']);

// --- Admin: Telegram — бот, коды входа, канал, уведомления (супер-админ) ---
$router->get('/admin/telegram', [\App\Controllers\Admin\TelegramController::class, 'index']);
$router->post('/admin/telegram/bot', [\App\Controllers\Admin\TelegramController::class, 'saveBot']);
$router->post('/admin/telegram/bot/check', [\App\Controllers\Admin\TelegramController::class, 'checkBot']);
$router->post('/admin/telegram/link', [\App\Controllers\Admin\TelegramController::class, 'link']);
$router->post('/admin/telegram/channel', [\App\Controllers\Admin\TelegramController::class, 'saveChannel']);
$router->post('/admin/telegram/channel/check', [\App\Controllers\Admin\TelegramController::class, 'checkChannel']);
$router->post('/admin/telegram/channel/detect', [\App\Controllers\Admin\TelegramController::class, 'detectChannel']);
$router->post('/admin/telegram/extras', [\App\Controllers\Admin\TelegramController::class, 'saveExtras']);
$router->post('/admin/telegram/roundup/send', [\App\Controllers\Admin\TelegramController::class, 'sendRoundup']);
$router->post('/admin/telegram/extras/check', [\App\Controllers\Admin\TelegramController::class, 'checkExtras']);

// --- Admin: исходящие вебхуки (только супер-админ) ---
$router->get('/admin/webhooks', [\App\Controllers\Admin\WebhookController::class, 'index']);
$router->post('/admin/webhooks/create', [\App\Controllers\Admin\WebhookController::class, 'store']);
$router->post('/admin/webhooks/{id}/edit', [\App\Controllers\Admin\WebhookController::class, 'update']);
$router->post('/admin/webhooks/{id}/delete', [\App\Controllers\Admin\WebhookController::class, 'destroy']);
$router->post('/admin/webhooks/{id}/test', [\App\Controllers\Admin\WebhookController::class, 'test']);
$router->post('/admin/webhooks/deliveries/{id}/retry', [\App\Controllers\Admin\WebhookController::class, 'retry']);
$router->post('/admin/webhooks/run', [\App\Controllers\Admin\WebhookController::class, 'runNow']);

// --- Admin: Центр безопасности ---
$router->get('/admin/security', [\App\Controllers\Admin\SecurityController::class, 'index']);

// --- Admin: резервное копирование ---
$router->post('/admin/backup', [\App\Controllers\Admin\BackupController::class, 'create']);

// --- Admin: файловый менеджер ---
$router->get('/admin/files', [AdminFileController::class, 'index']);
$router->get('/admin/media/list', [AdminFileController::class, 'library']);
$router->post('/admin/files/upload', [AdminFileController::class, 'upload']);
$router->post('/admin/files/chunk', [\App\Controllers\Admin\ChunkedUploadController::class, 'chunk']);
$router->post('/admin/files/{id}/delete', [AdminFileController::class, 'destroy']);
$router->post('/admin/files/{id}/regenerate-token', [AdminFileController::class, 'regenerateToken']);
$router->post('/admin/files/bulk-delete', [AdminFileController::class, 'bulkDelete']);

// --- Admin: защищённое файловое хранилище (супер-админ) ---
$router->get('/admin/repository', [\App\Controllers\Admin\RepositoryController::class, 'files']);
$router->post('/admin/repository/upload', [\App\Controllers\Admin\RepositoryController::class, 'upload']);
$router->post('/admin/repository/settings', [\App\Controllers\Admin\RepositoryController::class, 'saveSettings']);
$router->get('/admin/repository/categories', [\App\Controllers\Admin\RepositoryController::class, 'categories']);
$router->post('/admin/repository/categories/create', [\App\Controllers\Admin\RepositoryController::class, 'storeCategory']);
$router->post('/admin/repository/categories/{id}/rename', [\App\Controllers\Admin\RepositoryController::class, 'renameCategory']);
$router->post('/admin/repository/categories/{id}/delete', [\App\Controllers\Admin\RepositoryController::class, 'destroyCategory']);
$router->post('/admin/repository/{id}/update', [\App\Controllers\Admin\RepositoryController::class, 'updateFile']);
$router->post('/admin/repository/{id}/approve', [\App\Controllers\Admin\RepositoryController::class, 'approveFile']);
$router->post('/admin/repository/{id}/delete', [\App\Controllers\Admin\RepositoryController::class, 'destroyFile']);
$router->get('/admin/repository/users', [\App\Controllers\Admin\RepositoryController::class, 'users']);
$router->post('/admin/repository/users/create', [\App\Controllers\Admin\RepositoryController::class, 'storeUser']);
$router->post('/admin/repository/users/{id}/toggle', [\App\Controllers\Admin\RepositoryController::class, 'toggleUser']);
$router->post('/admin/repository/users/{id}/reset-password', [\App\Controllers\Admin\RepositoryController::class, 'resetUserPassword']);
$router->post('/admin/repository/users/{id}/delete', [\App\Controllers\Admin\RepositoryController::class, 'destroyUser']);

// --- Портал файлового хранилища (собственная авторизация, /repo) ---
$router->get('/repo/login', [\App\Controllers\Repo\AuthController::class, 'showLogin']);
$router->post('/repo/login', [\App\Controllers\Repo\AuthController::class, 'login']);
$router->get('/repo/login/2fa', [\App\Controllers\Repo\AuthController::class, 'showTwoFactor']);
$router->post('/repo/login/2fa', [\App\Controllers\Repo\AuthController::class, 'verifyTwoFactor']);
$router->post('/repo/login/2fa/resend', [\App\Controllers\Repo\AuthController::class, 'resendTelegramCode']);
$router->post('/repo/logout', [\App\Controllers\Repo\AuthController::class, 'logout']);
$router->get('/repo', [\App\Controllers\Repo\PortalController::class, 'index']);
$router->post('/repo/upload', [\App\Controllers\Repo\PortalController::class, 'upload']);
$router->get('/repo/download/{id}', [\App\Controllers\Repo\PortalController::class, 'download']);
$router->get('/repo/preview/{id}', [\App\Controllers\Repo\PortalController::class, 'preview']);
$router->post('/repo/download-zip', [\App\Controllers\Repo\PortalController::class, 'downloadZip']);
$router->get('/repo/security', [\App\Controllers\Repo\PortalController::class, 'security']);
$router->post('/repo/security/2fa/enable', [\App\Controllers\Repo\PortalController::class, 'enableTotp']);
$router->post('/repo/security/2fa/disable', [\App\Controllers\Repo\PortalController::class, 'disableTotp']);
$router->post('/repo/security/telegram/verify', [\App\Controllers\Repo\PortalController::class, 'telegramVerify']);
$router->post('/repo/security/telegram/disable', [\App\Controllers\Repo\PortalController::class, 'telegramDisable']);

// --- Health-check (мониторинг) ---
$router->get('/health', [\App\Controllers\Site\HealthController::class, 'index']);
// Приём Core Web Vitals: sendBeacon шлёт их при закрытии вкладки.
$router->post('/_vitals', [\App\Controllers\Site\VitalsController::class, 'store']);

// --- PWA-манифест ---
$router->get('/manifest.webmanifest', [\App\Controllers\Site\ManifestController::class, 'webmanifest']);

// --- SEO & RSS ---
$router->get('/sitemap.xml', [\App\Controllers\Site\SitemapController::class, 'sitemap']);
$router->get('/rss.xml', [\App\Controllers\Site\SitemapController::class, 'rss']);
$router->get('/rss/{lang}', [\App\Controllers\Site\SitemapController::class, 'rss']);
$router->get('/robots.txt', [\App\Controllers\Site\SitemapController::class, 'robots']);

// --- Письменность узбекского текста (латиница ↔ кириллица) ---
$router->get('/goals/random', [SiteGoalController::class, 'random']);
$router->get('/script/{code}', [\App\Controllers\Site\ScriptController::class, 'switch']);

// --- Публичный сайт ---
$router->get('/', [SitePageController::class, 'home']);
$router->get('/news', [SiteNewsController::class, 'index']);
$router->get('/projects', [\App\Controllers\Site\ProjectController::class, 'index']);
$router->get('/projects/{slug}', [\App\Controllers\Site\ProjectController::class, 'show']);
$router->get('/news/rss.xml', [SiteNewsController::class, 'feed']);
$router->get('/news/{slug}/photos.zip', [SiteNewsController::class, 'photosZip']);
$router->get('/news/{slug}', [SiteNewsController::class, 'show']);
$router->get('/search', [\App\Controllers\Site\SearchController::class, 'index']);
$router->get('/search/suggest', [\App\Controllers\Site\SearchController::class, 'suggest']);
$router->get('/calendar', [\App\Controllers\Site\CalendarController::class, 'index']);
$router->get('/albums', [\App\Controllers\Site\AlbumController::class, 'index']);
$router->get('/albums/{slug}', [\App\Controllers\Site\AlbumController::class, 'show']);
$router->post('/subscribe', [\App\Controllers\Site\SubscribeController::class, 'subscribe']);
$router->post('/api/polls/{id}/vote', [\App\Controllers\Site\PollController::class, 'vote']);
$router->get('/captcha.png', [\App\Controllers\Site\CaptchaController::class, 'image']);
$router->get('/push/key', [\App\Controllers\Site\PushController::class, 'key']);
$router->post('/push/subscribe', [\App\Controllers\Site\PushController::class, 'subscribe']);
$router->post('/push/unsubscribe', [\App\Controllers\Site\PushController::class, 'unsubscribe']);
$router->get('/unsubscribe', [\App\Controllers\Site\SubscribeController::class, 'unsubscribe']);
$router->get('/opendata', [\App\Controllers\Site\OpenDataController::class, 'index']);
$router->get('/opendata/{dataset}', [\App\Controllers\Site\OpenDataController::class, 'dataset']);
$router->get('/catalog/{type}', [\App\Controllers\Site\ContentController::class, 'index']);
$router->get('/catalog/{type}/{slug}', [\App\Controllers\Site\ContentController::class, 'show']);
$router->post('/forms/{slug}/submit', [SiteFormController::class, 'submit']);
$router->get('/{slug}', [SitePageController::class, 'show']);

// Раздел каталога, у которого снят префикс `/catalog`: его запись живёт на
// `/<type>/<entry>`. Маршрут объявлен ПОСЛЕДНИМ намеренно — это шаблон из двух
// произвольных сегментов, и объявленный выше он перехватывал бы `/news/...`,
// `/projects/...` и всё остальное. Новый двухсегментный маршрут добавляется
// перед ним, иначе он никогда не сработает (стережёт тест 353).
$router->get('/{type}/{slug}', [\App\Controllers\Site\ContentController::class, 'rootShow']);

// Журнал действий администраторов: центральная запись изменяющих запросов
// панели (кто/что/когда/откуда; тело запроса не сохраняется).
if (\App\Core\Session::hasCookie()) {
    \App\Models\AuditLog::record();
}

// После успешных изменений публичного контента сбрасываем файловый кеш и
// Cloudflare. Shutdown-регистрация срабатывает и при redirect/exit контроллера.
\App\Core\PublicResponseCache::registerContentInvalidation();

// Onboarding второго фактора: после корректного пароля пользователь без
// Telegram получает ограниченную сессию и может открыть только профиль,
// настройки доставки кода и выход. Остальная админка остаётся закрытой.
$guardPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (\App\Core\Session::hasCookie()
    && \App\Core\Auth::check()
    && \App\Core\Auth::requiresTwoFactorSetup()
    && (string) \App\Core\Config::get('app.env') !== 'development') {
    // Разрешены только операции, необходимые для подключения канала кодов.
    // Префиксы намеренно не используются: иначе onboarding-сессия получала
    // доступ к смене пароля, отзыву сессий и настройкам Telegram-канала.
    $allowedSetupPaths = [
        '/admin/profile',
        '/admin/profile/phone',
        '/admin/profile/telegram/link',
        // Без этого пункта админ без Telegram не смог бы подключить
        // приложение-аутентификатор: ограниченная сессия не пускает дальше.
        '/admin/profile/totp/enable',
        '/admin/telegram',
        '/admin/telegram/bot',
        '/admin/telegram/bot/check',
        '/admin/telegram/link',
        '/admin/logout',
    ];
    $allowed = in_array($guardPath, $allowedSetupPaths, true);
    if (!$allowed && str_starts_with($guardPath, '/admin')) {
        header('Location: /admin/profile');
        exit;
    }
}

if ($requestMethod === 'GET' && !str_starts_with($guardPath, '/admin') && !str_starts_with($guardPath, '/install') && !str_starts_with($guardPath, '/api')) {
    header('Link: </assets/css/public.min.css>; rel=preload; as=style', false);
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
