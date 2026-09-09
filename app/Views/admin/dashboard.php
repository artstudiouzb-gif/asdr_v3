<?php

$pageTitle = t('Дашборд');
$activeNav = 'dashboard';
require __DIR__ . '/layout/header.php';

/** @var array $user */
/** @var array $counts */
/** @var array<string, int> $chartData */
/** @var array<int, array<string, mixed>> $recentLogs */
/** @var array<int, array<string, mixed>> $recentItems */
/** @var array<int, array<string, mixed>> $recentSubmissions */
/** @var array<string, mixed> $systemHealth */
/** @var bool $canManageSubmissions */
/** @var bool $canManageAudit */
?>
<section class="admin-welcome" aria-labelledby="admin-welcome-title">
    <div>
        <h2 id="admin-welcome-title"><?= htmlspecialchars(t('Добро пожаловать'), ENT_QUOTES) ?>, <?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?></h2>
        <p><?= htmlspecialchars(t('Управляйте содержимым сайта и быстро переходите к основным действиям.'), ENT_QUOTES) ?></p>
    </div>
    <div class="admin-welcome__actions">
        <a href="/admin/news/create" class="btn btn--primary"><?= \App\Core\AdminUi::icon('plus') ?> <?= htmlspecialchars(t('Добавить новость'), ENT_QUOTES) ?></a>
        <a href="/admin/pages/create" class="btn"><?= htmlspecialchars(t('Добавить страницу'), ENT_QUOTES) ?></a>
        <a href="/" target="_blank" rel="noopener" class="btn"><?= htmlspecialchars(t('Открыть сайт'), ENT_QUOTES) ?> ↗</a>
    </div>
</section>

<div class="stat-grid">
    <a href="/admin/news" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['news'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Новости'), ENT_QUOTES) ?></span>
        <?php if (!empty($counts['news_drafts'])): ?>
            <span class="form-hint u-inline-a3a5568692"><?= (int) $counts['news_drafts'] ?> <?= htmlspecialchars(t('черновиков'), ENT_QUOTES) ?></span>
        <?php endif; ?>
    </a>
    <a href="/admin/pages" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['pages'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Страницы'), ENT_QUOTES) ?></span>
    </a>
    <a href="/admin/projects" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['projects'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Проекты'), ENT_QUOTES) ?></span>
    </a>
    <a href="/admin/team" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['team'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Сотрудники'), ENT_QUOTES) ?></span>
    </a>
    <a href="/admin/forms" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['forms'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Формы'), ENT_QUOTES) ?></span>
    </a>
    <?php if ($canManageSubmissions): ?>
        <a href="/admin/forms/submissions?status=unread" class="stat-card<?= $counts['submissions_unread'] > 0 ? ' stat-card--highlight' : '' ?>">
            <span class="stat-card__value"><?= (int) $counts['submissions_unread'] ?></span>
            <span class="stat-card__label"><?= htmlspecialchars(t('Непрочитанные заявки'), ENT_QUOTES) ?></span>
        </a>
    <?php endif; ?>
    <a href="/admin/files" class="stat-card">
        <span class="stat-card__value"><?= (int) $counts['files'] ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Медиафайлы'), ENT_QUOTES) ?></span>
    </a>
    <a href="/admin/languages" class="stat-card">
        <span class="stat-card__value"><?= (int) ($systemHealth['active_langs_count'] ?? 1) ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Активные языки'), ENT_QUOTES) ?></span>
    </a>
    <a href="/admin/repository" class="stat-card">
        <span class="stat-card__value"><?= (int) ($counts['repo_downloads'] ?? 0) ?></span>
        <span class="stat-card__label"><?= htmlspecialchars(t('Скачиваний репозитория'), ENT_QUOTES) ?></span>
        <span class="form-hint u-inline-a3a5568692"><?= (int) ($counts['repo_files'] ?? 0) ?> <?= htmlspecialchars(t('файлов'), ENT_QUOTES) ?></span>
    </a>
</div>

<div class="dashboard-grid u-inline-8b9688e6e0">
    <!-- Виджет: Статус и безопасность системы -->
    <div class="form-card">
        <div class="u-inline-359c202582">
            <h3 class="u-inline-1da9facb4d"><?= htmlspecialchars(t('Статус системы'), ENT_QUOTES) ?></h3>
            <?php if ($canManageAudit): ?>
                <a href="/admin/security" class="btn btn--small u-inline-e71ae94b55"><?= htmlspecialchars(t('Безопасность'), ENT_QUOTES) ?> →</a>
            <?php endif; ?>
        </div>
        <div class="u-inline-6435a88594">
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('Версия PHP'), ENT_QUOTES) ?></div>
                <strong class="u-inline-ca55bb2c16"><?= htmlspecialchars((string) ($systemHealth['php_version'] ?? PHP_VERSION), ENT_QUOTES) ?></strong>
            </div>
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('База данных'), ENT_QUOTES) ?></div>
                <span class="badge badge--published badge--small"><?= \App\Core\AdminUi::icon('check', 13) ?> <?= htmlspecialchars(t('Подключена'), ENT_QUOTES) ?></span>
            </div>
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('Защита 2FA / Telegram'), ENT_QUOTES) ?></div>
                <?php if (!empty($systemHealth['telegram_linked'])): ?>
                    <span class="badge badge--published badge--small"><?= \App\Core\AdminUi::icon('check', 13) ?> <?= htmlspecialchars(t('Активна'), ENT_QUOTES) ?></span>
                <?php else: ?>
                    <span class="badge badge--draft badge--small"><?= htmlspecialchars(t('Не настроена'), ENT_QUOTES) ?></span>
                <?php endif; ?>
            </div>
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('Очередь задач'), ENT_QUOTES) ?></div>
                <span class="badge badge--published badge--small"><?= \App\Core\AdminUi::icon('check', 13) ?> <?= (int) ($systemHealth['queue_pending'] ?? 0) ?> <?= htmlspecialchars(t('в очереди'), ENT_QUOTES) ?></span>
            </div>
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('Ошибки очереди'), ENT_QUOTES) ?></div>
                <?php $queueFailed = (int) ($systemHealth['queue_failed'] ?? 0); ?>
                <span class="badge <?= $queueFailed > 0 ? 'badge--draft' : 'badge--published' ?> badge--small"><?= \App\Core\AdminUi::icon($queueFailed > 0 ? 'alert-triangle' : 'check', 13) ?> <?= $queueFailed ?></span>
            </div>
            <div class="u-inline-4588dc62ed">
                <div class="form-hint u-inline-48b8779b18"><?= htmlspecialchars(t('Обслуживание'), ENT_QUOTES) ?></div>
                <?php if (!empty($systemHealth['maintenance'])): ?>
                    <span class="badge badge--draft badge--small"><?= htmlspecialchars(t('Включён'), ENT_QUOTES) ?></span>
                <?php else: ?>
                    <span class="badge badge--published badge--small"><?= htmlspecialchars(t('Выключен'), ENT_QUOTES) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($canManageSubmissions): ?>
    <!-- Виджет: Последние поступившие заявки с сайта -->
    <div class="form-card">
        <div class="u-inline-359c202582">
            <h3 class="u-inline-1da9facb4d"><?= htmlspecialchars(t('Последние заявки'), ENT_QUOTES) ?></h3>
            <a href="/admin/forms/submissions" class="btn btn--small u-inline-e71ae94b55"><?= htmlspecialchars(t('Все заявки'), ENT_QUOTES) ?> →</a>
        </div>
        <?php if (empty($recentSubmissions)): ?>
            <p class="form-hint u-inline-45517d35ab"><?= htmlspecialchars(t('Заявок пока не поступало.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="u-inline-1745561f5c">
                <?php foreach ($recentSubmissions as $sub): ?>
                    <?php
                    $isUnread = (int) ($sub['is_read'] ?? 0) === 0;
                    $data = json_decode((string) ($sub['data_json'] ?? '{}'), true) ?: [];
                    $previewValues = array_map(
                        static fn (mixed $value): string => is_array($value)
                            ? implode(', ', array_map('strval', $value))
                            : (string) $value,
                        array_slice(array_values($data), 0, 2)
                    );
                    $previewText = implode(' • ', $previewValues);
                    ?>
                    <a class="u-inline-729013516a" href="/admin/forms/submissions/<?= (int) $sub['id'] ?>">
                        <div class="u-inline-4e8f89004d">
                            <strong class="u-inline-ffcf89af9c"><?= htmlspecialchars((string) ($sub['form_title'] ?? t('Форма')), ENT_QUOTES) ?></strong>
                            <span class="form-hint u-inline-33d0b17b27"><?= htmlspecialchars($previewText !== '' ? $previewText : '—', ENT_QUOTES) ?></span>
                        </div>
                        <div class="u-inline-a527bac1ee">
                            <?php if ($isUnread): ?>
                                <span class="badge badge--draft badge--small"><?= htmlspecialchars(t('Новая'), ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <span class="form-hint u-inline-083bdc9269"><?= \App\Core\DateFormatter::format((string) $sub['created_at'], 'd.m H:i') ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Виджет: Поисковые запросы по сайту за последние 30 дней -->
<?php if (!empty($popularSearches)): ?>
<div class="form-card u-inline-8b9688e6e0">
    <h3 class="u-inline-291b7bbb01"><?= htmlspecialchars(t('Популярные поиски на сайте'), ENT_QUOTES) ?></h3>
    <p class="form-hint"><?= htmlspecialchars(t('Что посетители чаще всего ищут через внутренний поиск за 30 дней.'), ENT_QUOTES) ?></p>
    <div class="u-inline-d15aa4f40a">
        <?php foreach ($popularSearches as $s): ?>
            <?php
            $q = (string) $s['query'];
            $cnt = (int) $s['searches_count'];
            $resCnt = (int) $s['last_results_count'];
            ?>
            <div class="u-inline-3df38c3cc4">
                <?= \App\Core\AdminUi::icon('search', 13, 'btn__icon', 2.5) ?>
                <strong>«<?= htmlspecialchars($q, ENT_QUOTES) ?>»</strong>
                <span class="badge badge--small"><?= $cnt ?> <?= htmlspecialchars(t('запросов'), ENT_QUOTES) ?></span>
                <?php if ($resCnt === 0): ?>
                    <span class="badge badge--draft badge--small"><?= htmlspecialchars(t('0 результатов'), ENT_QUOTES) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Виджет: Самые читаемые новости за период -->
<?php if (!empty($topReadNews)): ?>
<div class="form-card u-inline-8b9688e6e0">
    <div class="u-inline-359c202582">
        <div>
            <h3 class="u-inline-1da9facb4d"><?= htmlspecialchars(t('Самые читаемые новости'), ENT_QUOTES) ?></h3>
            <p class="form-hint u-inline-6749c87a10"><?= htmlspecialchars(t('Наибольшее число просмотров среди читателей за последние 30 дней.'), ENT_QUOTES) ?></p>
        </div>
        <a href="/admin/news" class="btn btn--small u-inline-e71ae94b55"><?= htmlspecialchars(t('Все новости'), ENT_QUOTES) ?> →</a>
    </div>
    <div class="u-inline-1745561f5c">
        <?php foreach ($topReadNews as $idx => $n): ?>
            <a class="u-inline-232f3d8dee" href="/admin/news/<?= (int) $n['id'] ?>/edit">
                <div class="u-inline-c76ba7ebe2">
                    <span class="u-inline-ad45a8dba2">#<?= $idx + 1 ?></span>
                    <div class="u-inline-1a3ecb21b1">
                        <strong class="u-inline-86c549d021"><?= htmlspecialchars((string) $n['title'], ENT_QUOTES) ?></strong>
                        <span class="form-hint u-inline-33d0b17b27"><?= \App\Core\DateFormatter::format((string) ($n['published_at'] ?? 'now'), 'd.m.Y') ?></span>
                    </div>
                </div>
                <div>
                    <span class="badge badge--published u-inline-5888fddfc8">
                        <?= \App\Core\AdminUi::icon('eye', 14) ?> <?= number_format((int) ($n['period_views'] ?? 0), 0, '.', ' ') ?> <?= htmlspecialchars(t('просмотров'), ENT_QUOTES) ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($recentItems)): ?>
<div class="form-card continue-card u-inline-8b9688e6e0">
    <h3 class="u-inline-291b7bbb01"><?= htmlspecialchars(t('Продолжить работу'), ENT_QUOTES) ?></h3>
    <p class="form-hint"><?= htmlspecialchars(t('Последние материалы, которые редактировались.'), ENT_QUOTES) ?></p>
    <div class="continue-list">
        <?php foreach ($recentItems as $item): ?>
            <?php
            $isNews = ($item['kind'] ?? '') === 'news';
            $editUrl = ($isNews ? '/admin/news/' : '/admin/pages/') . (int) $item['id'] . '/edit';
            $isDraft = ($item['status'] ?? '') === 'draft';
            ?>
            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES) ?>" class="continue-item">
                <span class="continue-item__kind"><?= $isNews ? htmlspecialchars(t('Новость'), ENT_QUOTES) : htmlspecialchars(t('Страница'), ENT_QUOTES) ?></span>
                <span class="continue-item__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                <span class="badge <?= $isDraft ? 'badge--draft' : 'badge--published' ?>"><?= $isDraft ? htmlspecialchars(t('Черновик'), ENT_QUOTES) : htmlspecialchars(t('Опубликовано'), ENT_QUOTES) ?></span>
                <span class="continue-item__time"><?= \App\Core\DateFormatter::format((string) $item['updated_at'], 'd.m.Y H:i') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageSubmissions || $canManageAudit): ?>
<?php
$maxVal = max(1, ...array_values($chartData));
$width = 500;
$height = 220;
$padding = 30;
$chartWidth = $width - 2 * $padding;
$chartHeight = $height - 2 * $padding;

$points = [];
$xStep = count($chartData) > 1 ? $chartWidth / (count($chartData) - 1) : $chartWidth;
$i = 0;
foreach ($chartData as $date => $count) {
    $x = $padding + $i * $xStep;
    $y = $padding + $chartHeight - ($count / $maxVal) * $chartHeight;
    $points[] = "$x,$y";
    $i++;
}
$pointsStr = implode(' ', $points);
$fillPointsStr = "$padding," . ($height - $padding) . " $pointsStr " . ($width - $padding) . "," . ($height - $padding);
?>
<div class="dashboard-grid">
    <?php if ($canManageSubmissions): ?>
    <div class="form-card">
        <h3 class="u-inline-291b7bbb01"><?= htmlspecialchars(t('Активность заявок'), ENT_QUOTES) ?></h3>
        <p class="form-hint"><?= htmlspecialchars(t('Число заполненных форм обратной связи за последние 7 дней.'), ENT_QUOTES) ?></p>
        <div class="u-inline-4238d06251">
            <svg class="u-inline-c842ae9ef5" viewBox="0 0 500 220" width="100%" height="100%">
                <defs>
                    <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--admin-accent)" stop-opacity="0.3"></stop>
                        <stop offset="100%" stop-color="var(--admin-accent)" stop-opacity="0"></stop>
                    </linearGradient>
                </defs>
                <!-- Grid Lines -->
                <?php for ($grid = 0; $grid <= 4; $grid++): ?>
                    <?php $gy = $padding + ($chartHeight / 4) * $grid; ?>
                    <line x1="<?= $padding ?>" y1="<?= $gy ?>" x2="<?= $width - $padding ?>" y2="<?= $gy ?>" stroke="var(--admin-border)" stroke-width="1" stroke-dasharray="4,4"></line>
                <?php endfor; ?>
                <!-- Filled Area -->
                <polygon points="<?= $fillPointsStr ?>" fill="url(#chartGrad)"></polygon>
                <!-- Line -->
                <polyline points="<?= $pointsStr ?>" fill="none" stroke="var(--admin-accent)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                <!-- Data Points -->
                <?php $i = 0; foreach ($chartData as $date => $count): ?>
                    <?php 
                    $parts = explode(',', $points[$i]); 
                    $cx = (float) $parts[0];
                    $cy = (float) $parts[1];
                    ?>
                    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="5" fill="var(--admin-surface)" stroke="var(--admin-accent)" stroke-width="2"></circle>
                    <text x="<?= $cx ?>" y="<?= $cy - 10.0 ?>" font-size="10" font-weight="700" fill="var(--admin-text)" text-anchor="middle"><?= $count ?></text>
                <?php $i++; endforeach; ?>
                <!-- X Labels -->
                <?php $i = 0; foreach ($chartData as $date => $count): ?>
                    <?php 
                    $parts = explode(',', $points[$i]); 
                    $cx = $parts[0];
                    $label = \App\Core\DateFormatter::format($date, 'd.m');
                    ?>
                    <text x="<?= $cx ?>" y="<?= $height - $padding + 18 ?>" font-size="10" fill="var(--admin-muted)" text-anchor="middle"><?= $label ?></text>
                <?php $i++; endforeach; ?>
            </svg>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canManageAudit): ?>
    <div class="form-card">
        <h3 class="u-inline-291b7bbb01"><?= htmlspecialchars(t('Журнал действий'), ENT_QUOTES) ?></h3>
        <p class="form-hint"><?= htmlspecialchars(t('Последние действия администраторов в панели управления.'), ENT_QUOTES) ?></p>
        <div class="activity-feed u-inline-8a359a76eb">
            <?php if (empty($recentLogs)): ?>
                <p class="form-hint u-inline-1da9facb4d"><?= htmlspecialchars(t('Действий пока нет.'), ENT_QUOTES) ?></p>
            <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                    <div class="activity-item">
                        <div class="activity-item__meta">
                            <strong><?= htmlspecialchars((string) ($log['username'] ?? 'System'), ENT_QUOTES) ?></strong>
                            <span class="activity-item__time"><?= \App\Core\DateFormatter::format((string) $log['created_at'], 'H:i d.m.Y') ?></span>
                        </div>
                        <div class="activity-item__desc">
                            <?php $m = strtoupper((string) ($log['method'] ?? '')); ?>
                            <span class="activity-item__badge activity-item__badge--<?= strtolower($m) ?>"><?= htmlspecialchars($m, ENT_QUOTES) ?></span>
                            <?php if ($m === 'AUTH'): ?>
                                <?php $authMeta = \App\Models\AuditLog::authEventMeta((string) ($log['path'] ?? '')); ?>
                                <span><?= htmlspecialchars($authMeta['label'], ENT_QUOTES) ?></span>
                            <?php else: ?>
                                <code><?= htmlspecialchars((string) ($log['path'] ?? ''), ENT_QUOTES) ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($topRepoDownloads)): ?>
<div class="form-card u-inline-e6e3fab9a5">
    <div class="form-card__header">
        <h3><?= \App\Core\AdminUi::icon('download', 18) ?> <?= htmlspecialchars(t('Популярные файлы репозитория'), ENT_QUOTES) ?></h3>
        <a href="/admin/repository" class="btn btn--small"><?= htmlspecialchars(t('Перейти в репозиторий'), ENT_QUOTES) ?> →</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t('Название документа'), ENT_QUOTES) ?></th>
                    <th><?= htmlspecialchars(t('Скачиваний'), ENT_QUOTES) ?></th>
                    <th><?= htmlspecialchars(t('Дата публикации'), ENT_QUOTES) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topRepoDownloads as $doc): ?>
                    <tr>
                        <td>
                            <strong><a href="/repo/download/<?= (int) $doc['id'] ?>" target="_blank"><?= htmlspecialchars((string) $doc['title'], ENT_QUOTES) ?></a></strong>
                            <br><small class="text-muted"><?= htmlspecialchars((string) $doc['original_name'], ENT_QUOTES) ?></small>
                        </td>
                        <td>
                            <span class="badge badge--success"><?= (int) $doc['download_count'] ?></span>
                        </td>
                        <td class="text-muted">
                            <?= \App\Core\DateFormatter::format((string) $doc['created_at'], 'd.m.Y') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
