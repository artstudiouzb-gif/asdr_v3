<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;

/** @var string $pageTitle */
/** @var string $activeNav */

$pageTitle = (string) ($pageTitle ?? 'Панель управления');
$activeNav = (string) ($activeNav ?? 'dashboard');

$navIsSuper = Auth::isSuperAdmin();
$navUser = Auth::user();

// --- Разделы навигации, сгруппированные по секциям (стиль Statamic) ---
// ВАЖНО: все переменные заголовка имеют префикс nav*/tb*, чтобы не затирать
// переменные вьюхи (header.php подключается в общую область видимости).
$navContent = [
    'news' => ['/admin/news', t('Новости')],
    'news_categories' => ['/admin/news-categories', t('Категории новостей')],
    'pages' => ['/admin/pages', t('Страницы')],
    'heroes' => ['/admin/heroes', t('Обложки')],
    'projects' => ['/admin/projects', t('Проекты')],
    'team' => ['/admin/team', t('Команда')],
    'goals' => ['/admin/goals', t('Цели')],
];
try {
    foreach (\App\Models\ContentType::all() as $navCt) {
        // Иконка едет вместе с пунктом: у каждого типа она своя, и общий
        // значок списка остаётся только запасным вариантом.
        $navContent['content:' . $navCt['slug']] = [
            '/admin/content/' . $navCt['slug'],
            $navCt['name'],
            (string) ($navCt['icon'] ?? ''),
        ];
    }
} catch (\Throwable $e) {
    // миграция типов контента не накатана — пропускаем
}

$navTools = [
    'albums' => ['/admin/albums', t('Фотоальбомы')],
    'videos' => ['/admin/videos', t('Видео')],
    'forms' => ['/admin/forms', t('Формы')],
    'files' => ['/admin/files', t('Медиафайлы')],
    'trash' => ['/admin/trash', t('Корзина')],
];

// «Настройки» из 12 пунктов подряд читались как хаос — разбиты на две группы:
// «Внешний вид» (то, что видно на сайте) и «Система» (интеграции и служебное).
$navAppearance = [];
$navSystem = [];
$navUsersGroup = [];
if ($navIsSuper) {
    $navAppearance = [
        'design' => ['/admin/design', t('Дизайн')],
        'menu' => ['/admin/menu', t('Меню')],
        'widgets' => ['/admin/widgets', t('Виджеты')],
        'header' => ['/admin/header', t('Шапка сайта')],
        'footer' => ['/admin/footer', t('Подвал сайта')],
    ];
    $navSystem = [
        'languages' => ['/admin/languages', t('Языки')],
        'content_types' => ['/admin/content-types', t('Типы контента')],
        'telegram' => ['/admin/telegram', 'Telegram'],
        'social' => ['/admin/social', t('Соцсети')],
        'webhooks' => ['/admin/webhooks', t('Вебхуки')],
        'redirects' => ['/admin/redirects', t('Редиректы')],
        'seo' => ['/admin/seo', t('Поиск и индексация')],
        'performance' => ['/admin/performance', t('Производительность')],
        'database' => ['/admin/database', t('База данных')],
        'security' => ['/admin/security', t('Безопасность')],
        'update' => ['/admin/update', t('Обновление системы')],
        'settings' => ['/admin/settings', t('Настройки')],
    ];
    $navUsersGroup = [
        'users' => ['/admin/users', t('Пользователи')],
        'audit' => ['/admin/audit', t('Журнал действий')],
    ];
    $navTools['subscribers'] = ['/admin/subscribers', t('Подписчики')];
    $navTools['repository'] = ['/admin/repository', t('Репозиторий')];
}

$navPendingMigrationsCount = 0;
if ($navIsSuper && \App\Core\Database::isConnected()) {
    try {
        $navPendingMigrationsCount = \App\Core\MigrationRunner::pendingCount(\App\Core\Database::pdo(), APP_ROOT . '/database/migrations');
    } catch (\Throwable) {
        $navPendingMigrationsCount = 0;
    }
}

$navGroups = [
    t('Контент') => $navContent,
    t('Инструменты') => $navTools,
    t('Внешний вид') => $navAppearance,
    t('Система') => $navSystem,
    t('Пользователи') => $navUsersGroup,
];

// Крошка в топбаре: «Группа › Раздел» вместо дубля заголовка страницы (h1 ниже).
$navCrumb = t($pageTitle);
foreach ($navGroups as $navGroupLabel => $navGroupItems) {
    if (isset($navGroupItems[$activeNav])) {
        $navCrumb = $navGroupLabel . ' › ' . $navGroupItems[$activeNav][1];
        break;
    }
}

$navInitials = mb_strtoupper(mb_substr((string) ($navUser['username'] ?? 'A'), 0, 1));
$navRoleLabel = (string) ($navUser['role'] ?? 'editor') === 'admin' ? t('Администратор') : t('Редактор');
$activeLangs = \App\Models\Language::active();
$currentLang = \App\Core\Locale::current();
$navUserId = (int) ($navUser['id'] ?? 0);
$navAdminTheme = $_SESSION['admin_theme'] ?? (\App\Models\Setting::get('admin_theme_user_' . $navUserId, 'default'));
$navBrandHost = (string) (parse_url((string) \App\Core\Config::get('app.url', ''), PHP_URL_HOST) ?: '');
$navBrandSubtitle = $navBrandHost !== '' ? $navBrandHost : t('Панель управления');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES) ?>" data-theme="light" data-admin-theme="<?= htmlspecialchars((string) $navAdminTheme, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> — <?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url('/assets/vendor/coloris/coloris.min.css'), ENT_QUOTES) ?>">
<?= \App\Core\AdminUi::fontLinks() ?>
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url('/assets/css/admin.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url('/assets/css/admin-shell-v2.css'), ENT_QUOTES) ?>">
<?= \App\Core\Icon::browserConfigHtml() ?>
<?= \App\Core\AdminBrand::styleTag() ?>
<?= \App\Core\AdminBrand::faviconHtml() ?>
<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
try {
    if (localStorage.getItem('artstudio:admin-sidebar-collapsed') === '1') {
        document.documentElement.classList.add('admin-nav-collapsed');
    }
} catch (e) {}
</script>
</head>
<body class="admin-body">
<a class="admin-skip-link" href="#admin-content">Перейти к содержимому</a>
<header class="admin-topbar">
    <button type="button" class="admin-topbar__toggle" data-sidebar-toggle aria-label="Открыть меню" aria-controls="admin-sidebar" aria-expanded="false">
        <?= \App\Core\AdminUi::icon('menu', 20) ?>
    </button>
    <span class="admin-topbar__crumb"><?= htmlspecialchars($navCrumb, ENT_QUOTES) ?></span>
    <div class="admin-topbar__spacer"></div>
    <div class="admin-search" data-search>
        <?= \App\Core\AdminUi::icon('search', 16, 'admin-search__icon') ?>
        <input type="search" class="admin-search__input" data-search-input
               placeholder="Поиск по панели…" autocomplete="off" aria-label="Поиск по админке">
        <kbd class="admin-search__kbd">Ctrl K</kbd>
        <div class="admin-search__results" data-search-results hidden></div>
    </div>

    <div class="admin-tools">
        <?php if (count($activeLangs) > 1): ?>
            <details class="admin-menu admin-tools__lang">
                <summary class="admin-tbtn" title="<?= htmlspecialchars(t('Язык интерфейса'), ENT_QUOTES) ?>">
                    <?= \App\Core\AdminUi::icon('globe', 15) ?>
                    <span class="admin-tbtn__label"><?= strtoupper(htmlspecialchars($currentLang, ENT_QUOTES)) ?></span>
                </summary>
                <div class="admin-menu__list">
                    <div class="admin-menu__label"><?= htmlspecialchars(t('Язык интерфейса'), ENT_QUOTES) ?></div>
                    <?php foreach ($activeLangs as $l): ?>
                        <?php
                        $code = (string) $l['code'];
                        $name = (string) $l['name'];
                        $href = $_SERVER['REQUEST_URI'] ?? '/admin';
                        $href = preg_replace('/[?&]_lang=[^&]*/', '', $href);
                        $href .= (str_contains($href, '?') ? '&' : '?') . \App\Core\LocalePreference::QUERY . '=' . rawurlencode($code);
                        ?>
                        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="admin-user__link<?= $code === $currentLang ? ' is-active' : '' ?>">
                            <?= htmlspecialchars($name, ENT_QUOTES) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <details class="admin-menu admin-tools__quick">
            <summary class="admin-tbtn admin-tbtn--primary" aria-label="Быстрые действия" title="Быстрые действия">
                <?= \App\Core\AdminUi::icon('plus') ?>
                <span class="admin-tbtn__label">Создать</span>
            </summary>
            <div class="admin-menu__list">
                <div class="admin-menu__label">Быстрые действия</div>
                <a href="/admin/news/create" class="admin-user__link">Новость</a>
                <a href="/admin/pages/create" class="admin-user__link">Страницу</a>
                <a href="/admin/projects/create" class="admin-user__link">Проект</a>
                <a href="/admin/team/create" class="admin-user__link">Сотрудника</a>
                <a href="/admin/albums" class="admin-user__link">Фотоальбом</a>
                <a href="/admin/forms/create" class="admin-user__link">Форму</a>
                <?php foreach ($navContent as $navKey => [$navUrl, $navText]): ?>
                    <?php if (str_starts_with($navKey, 'content:')): ?>
                        <a href="<?= $navUrl ?>/create" class="admin-user__link"><?= htmlspecialchars($navText, ENT_QUOTES) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if ($navIsSuper): ?>
        <form method="post" action="/admin/performance/clear-cache" class="admin-tools__form">
            <?= Csrf::field() ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/admin', ENT_QUOTES) ?>">
            <button type="submit" class="admin-tbtn" title="Сбросить кэш страниц">
                <?= \App\Core\AdminUi::icon('reset') ?>
                <span class="admin-tbtn__label">Сброс кэша</span>
            </button>
        </form>
        <?php endif; ?>

        <a href="/" target="_blank" rel="noopener" class="admin-tbtn" title="Открыть сайт в новой вкладке">
            <?= \App\Core\AdminUi::icon('external') ?>
            <span class="admin-tbtn__label">Сайт</span>
        </a>
    </div>

    <details class="admin-user">
        <summary class="admin-user__btn" aria-label="Аккаунт">
            <span class="admin-user__avatar"><?= htmlspecialchars($navInitials, ENT_QUOTES) ?></span>
            <span class="admin-user__identity">
                <strong><?= htmlspecialchars((string) ($navUser['username'] ?? ''), ENT_QUOTES) ?></strong>
                <small><?= htmlspecialchars($navRoleLabel, ENT_QUOTES) ?></small>
            </span>
            <?= \App\Core\AdminUi::icon('chevron-down', 14, 'admin-user__chevron', 2) ?>
        </summary>
        <div class="admin-user__menu">
            <div class="admin-user__name">
                <?= htmlspecialchars((string) ($navUser['username'] ?? ''), ENT_QUOTES) ?>
                <small><?= htmlspecialchars($navRoleLabel, ENT_QUOTES) ?></small>
            </div>
            <a href="/admin/profile" class="admin-user__link">Профиль</a>
            <form method="post" action="/admin/logout">
                <?= Csrf::field() ?>
                <button type="submit" class="admin-user__link admin-user__logout">Выйти</button>
            </form>
        </div>
    </details>
</header>

<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar" data-sidebar aria-label="<?= htmlspecialchars(t('Основная навигация'), ENT_QUOTES) ?>">
        <a href="/admin" class="admin-sidebar__brand" aria-label="<?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?>">
            <span class="admin-sidebar__brand-mark">
                <?= \App\Core\AdminBrand::badgeHtml('admin-sidebar__logoimg', 'admin-sidebar__logo') ?>
            </span>
            <span class="admin-sidebar__brand-copy">
                <strong><?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></strong>
                <small><?= htmlspecialchars($navBrandSubtitle, ENT_QUOTES) ?></small>
            </span>
        </a>

        <div class="admin-sidebar__nav">
            <a href="/admin" class="admin-nav-item <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" title="<?= htmlspecialchars(t('Дашборд'), ENT_QUOTES) ?>"<?= $activeNav === 'dashboard' ? ' aria-current="page"' : '' ?>>
                <?= \App\Core\AdminUi::navigationIcon('dashboard') ?><span><?= htmlspecialchars(t('Дашборд'), ENT_QUOTES) ?></span>
            </a>
            <?php foreach ($navGroups as $navGroupLabel => $navGroupItems): ?>
                <?php if (empty($navGroupItems)) { continue; } ?>
                <div class="admin-nav-group" data-nav-group="<?= htmlspecialchars($navGroupLabel, ENT_QUOTES) ?>">
                    <button type="button" class="admin-sidebar__label admin-sidebar__label--toggle" data-nav-toggle aria-expanded="true">
                        <span><?= htmlspecialchars($navGroupLabel, ENT_QUOTES) ?></span>
                        <?= \App\Core\AdminUi::icon('chevron-down', 12, 'admin-sidebar__chevron', 2.5) ?>
                    </button>
                    <div class="admin-nav-group__items">
                        <?php foreach ($navGroupItems as $navKey => $navItem): ?>
                            <?php
                            [$navUrl, $navText] = $navItem;
                            $navIc = $navItem[2] ?? '';
                            if ($navIc === '') {
                                $navIc = str_starts_with($navKey, 'content:') ? 'content_types' : $navKey;
                            }
                            ?>
                            <a href="<?= $navUrl ?>" class="admin-nav-item <?= $activeNav === $navKey ? 'is-active' : '' ?>" title="<?= htmlspecialchars($navText, ENT_QUOTES) ?>"<?= $activeNav === $navKey ? ' aria-current="page"' : '' ?>>
                                <?= \App\Core\AdminUi::navigationIcon($navIc) ?><span><?= htmlspecialchars($navText, ENT_QUOTES) ?></span>
                                <?php if ($navKey === 'database' && $navPendingMigrationsCount > 0): ?>
                                    <span class="badge badge--danger badge--pill admin-ml-auto"><?= $navPendingMigrationsCount ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="admin-sidebar__label"><?= htmlspecialchars(t('Аккаунт'), ENT_QUOTES) ?></div>
            <a href="/admin/profile" class="admin-nav-item <?= $activeNav === 'profile' ? 'is-active' : '' ?>" title="<?= htmlspecialchars(t('Профиль'), ENT_QUOTES) ?>"<?= $activeNav === 'profile' ? ' aria-current="page"' : '' ?>>
                <?= \App\Core\AdminUi::navigationIcon('profile') ?><span><?= htmlspecialchars(t('Профиль'), ENT_QUOTES) ?></span>
            </a>
        </div>

        <button type="button" class="admin-sidebar__collapse" data-sidebar-collapse aria-expanded="true" title="<?= htmlspecialchars(t('Свернуть меню'), ENT_QUOTES) ?>">
            <?= \App\Core\AdminUi::icon('panel-left', 18, 'btn__icon', 1.8) ?>
            <span><?= htmlspecialchars(t('Свернуть меню'), ENT_QUOTES) ?></span>
        </button>
    </aside>
    <script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
    (function () {
        'use strict';
        var KEY = 'admin_nav_collapsed';
        var collapsed = [];
        try { collapsed = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) {}
        var groups = document.querySelectorAll('.admin-nav-group');
        function save() {
            var set = [];
            document.querySelectorAll('.admin-nav-group.is-collapsed').forEach(function (x) {
                set.push(x.getAttribute('data-nav-group'));
            });
            try { localStorage.setItem(KEY, JSON.stringify(set)); } catch (e) {}
        }
        groups.forEach(function (g) {
            var name = g.getAttribute('data-nav-group');
            var btn = g.querySelector('[data-nav-toggle]');
            if (collapsed.indexOf(name) >= 0) {
                g.classList.add('is-collapsed');
                if (btn) { btn.setAttribute('aria-expanded', 'false'); }
            }
            if (btn) {
                btn.addEventListener('click', function () {
                    var isColl = g.classList.toggle('is-collapsed');
                    btn.setAttribute('aria-expanded', isColl ? 'false' : 'true');
                    save();
                });
            }
        });
    })();
    </script>
    <button type="button" class="admin-sidebar-backdrop" data-sidebar-backdrop aria-label="Закрыть меню" tabindex="-1"></button>

    <main class="admin-main" id="admin-content" tabindex="-1">
        <?php if ($navPendingMigrationsCount > 0 && $activeNav !== 'database'): ?>
            <div class="alert alert--warning admin-mb-16" role="status">
                <div class="alert__content u-flex-between">
                    <span><strong>Доступны обновления базы данных:</strong> обнаружено <?= $navPendingMigrationsCount ?> неприменённых <?= $navPendingMigrationsCount === 1 ? 'миграция' : 'миграций' ?>.</span>
                    <a href="/admin/database" class="btn btn--small btn--primary admin-ml-12">Обновить БД</a>
                </div>
            </div>
        <?php endif; ?>
        <?php foreach (Flash::pull() as $flash): ?>
            <div class="alert alert--<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>"
                 role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"
                 aria-live="<?= ($flash['type'] ?? '') === 'error' ? 'assertive' : 'polite' ?>">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES) ?>
            </div>
        <?php endforeach; ?>
        <div class="admin-main__header">
            <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
            <?php if (!empty($pageActions)): ?>
                <div class="admin-main__actions"><?= $pageActions ?></div>
            <?php endif; ?>
        </div>