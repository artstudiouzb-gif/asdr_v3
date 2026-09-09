<?php

use App\Core\AdminUi;
use App\Core\DateFormatter;

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

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);

/**
 * Плитка счётчика: иконка, число, подпись и необязательное примечание.
 *
 * Собирается функцией, а не копируется девять раз: у карточек одинаковая
 * структура, и разъехавшаяся копия — это разный порядок элементов на одной
 * сетке. Тон плитки берётся из набора модификаторов (`--admin-success` и
 * соседние), поэтому цвет глифа и подложки задаётся одним словом.
 */
$statCard = static function (
    string $href,
    string $icon,
    string $tone,
    int|string $value,
    string $label,
    string $note = '',
    bool $highlight = false
) use ($esc): string {
    $html = '<a href="' . $esc($href) . '" class="stat-card' . ($highlight ? ' stat-card--highlight' : '') . '">';
    $html .= '<span class="stat-card__icon stat-card__icon--' . $esc($tone) . '" aria-hidden="true">'
        . AdminUi::icon($icon, 20) . '</span>';
    $html .= '<span class="stat-card__value">' . $esc($value) . '</span>';
    $html .= '<span class="stat-card__label">' . $esc($label) . '</span>';
    if ($note !== '') {
        $html .= '<span class="stat-card__note">' . $esc($note) . '</span>';
    }

    return $html . '</a>';
};

/** Строка «Статуса системы»: иконка, подпись и готовая разметка значения. */
$statusRow = static function (string $icon, string $label, string $valueHtml) use ($esc): string {
    return '<div class="dash-status__row">'
        . '<span class="dash-status__icon" aria-hidden="true">' . AdminUi::icon($icon, 18) . '</span>'
        . '<span class="dash-status__body">'
        . '<span class="dash-status__label">' . $esc($label) . '</span>'
        . '<span class="dash-status__value">' . $valueHtml . '</span>'
        . '</span></div>';
};

/** Бейдж состояния: зелёный, когда всё в порядке. */
$stateBadge = static function (bool $ok, string $text, string $okIcon = 'check') use ($esc): string {
    return '<span class="badge ' . ($ok ? 'badge--published' : 'badge--draft') . ' badge--small">'
        . AdminUi::icon($ok ? $okIcon : 'alert-triangle', 13) . ' ' . $esc($text) . '</span>';
};

$queueFailed = (int) ($systemHealth['queue_failed'] ?? 0);
?>
<div class="dash-page">
<section class="admin-welcome" aria-labelledby="admin-welcome-title">
    <div>
        <h2 id="admin-welcome-title"><?= $esc(t('Добро пожаловать')) ?>, <?= $esc($user['username'] ?? '') ?></h2>
        <p><?= $esc(t('Управляйте содержимым сайта и быстро переходите к основным действиям.')) ?></p>
    </div>
    <div class="admin-welcome__actions">
        <a href="/admin/news/create" class="btn btn--primary"><?= AdminUi::icon('plus', 16) ?> <?= $esc(t('Добавить новость')) ?></a>
        <a href="/admin/pages/create" class="btn"><?= AdminUi::icon('file-plus', 16) ?> <?= $esc(t('Добавить страницу')) ?></a>
        <a href="/" target="_blank" rel="noopener" class="btn"><?= AdminUi::icon('external-link', 16) ?> <?= $esc(t('Открыть сайт')) ?></a>
    </div>
</section>

<div class="stat-grid">
    <?= $statCard('/admin/news', 'news', 'accent', (int) $counts['news'], t('Новости'),
        !empty($counts['news_drafts']) ? (int) $counts['news_drafts'] . ' ' . t('черновиков') : '') ?>
    <?= $statCard('/admin/pages', 'file-text', 'info', (int) $counts['pages'], t('Страницы')) ?>
    <?= $statCard('/admin/projects', 'briefcase', 'violet', (int) $counts['projects'], t('Проекты')) ?>
    <?= $statCard('/admin/team', 'users', 'neutral', (int) $counts['team'], t('Сотрудники')) ?>
    <?= $statCard('/admin/forms', 'forms', 'neutral', (int) $counts['forms'], t('Формы')) ?>
    <?php if ($canManageSubmissions): ?>
        <?= $statCard(
            '/admin/forms/submissions?status=unread',
            'inbox',
            $counts['submissions_unread'] > 0 ? 'danger' : 'neutral',
            (int) $counts['submissions_unread'],
            t('Непрочитанные заявки'),
            '',
            $counts['submissions_unread'] > 0
        ) ?>
    <?php endif; ?>
    <?= $statCard('/admin/files', 'photo', 'info', (int) $counts['files'], t('Медиафайлы')) ?>
    <?= $statCard('/admin/languages', 'language', 'success', (int) ($systemHealth['active_langs_count'] ?? 1), t('Активные языки')) ?>
    <?= $statCard('/admin/repository', 'download', 'success', (int) ($counts['repo_downloads'] ?? 0), t('Скачиваний репозитория'),
        (int) ($counts['repo_files'] ?? 0) . ' ' . t('файлов')) ?>
</div>

<div class="dashboard-grid">
    <!-- Виджет: Статус и безопасность системы -->
    <div class="form-card">
        <?= AdminUi::cardHeader(
            t('Статус системы'),
            'server',
            'var(--admin-info)',
            $canManageAudit
                ? '<a href="/admin/security" class="btn btn--small">' . $esc(t('Безопасность')) . ' →</a>'
                : ''
        ) ?>
        <div class="dash-status">
            <?= $statusRow('brand-php', t('Версия PHP'), '<span>' . $esc((string) ($systemHealth['php_version'] ?? PHP_VERSION)) . '</span>') ?>
            <?= $statusRow('database', t('База данных'), $stateBadge(true, t('Подключена'))) ?>
            <?= $statusRow('shield-lock', t('Защита 2FA / Telegram'), $stateBadge(
                !empty($systemHealth['telegram_linked']),
                !empty($systemHealth['telegram_linked']) ? t('Активна') : t('Не настроена')
            )) ?>
            <?= $statusRow('list-check', t('Очередь задач'), $stateBadge(
                true,
                (int) ($systemHealth['queue_pending'] ?? 0) . ' ' . t('в очереди')
            )) ?>
            <?= $statusRow('alert-triangle', t('Ошибки очереди'), $stateBadge($queueFailed === 0, (string) $queueFailed)) ?>
            <?= $statusRow('tool', t('Обслуживание'), $stateBadge(
                empty($systemHealth['maintenance']),
                empty($systemHealth['maintenance']) ? t('Выключен') : t('Включён')
            )) ?>
        </div>
    </div>

    <?php if ($canManageSubmissions): ?>
    <!-- Виджет: Последние поступившие заявки с сайта -->
    <div class="form-card">
        <?= AdminUi::cardHeader(
            t('Последние заявки'),
            'inbox',
            'var(--admin-accent)',
            '<a href="/admin/forms/submissions" class="btn btn--small">' . $esc(t('Все заявки')) . ' →</a>'
        ) ?>
        <?php if (empty($recentSubmissions)): ?>
            <p class="form-hint"><?= $esc(t('Заявок пока не поступало.')) ?></p>
        <?php else: ?>
            <div class="dash-list">
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
                    <a class="dash-list__item" href="/admin/forms/submissions/<?= (int) $sub['id'] ?>">
                        <span class="dash-list__main">
                            <span class="dash-list__text">
                                <span class="dash-list__title"><?= $esc($sub['form_title'] ?? t('Форма')) ?></span>
                                <span class="dash-list__meta"><?= $esc($previewText !== '' ? $previewText : '—') ?></span>
                            </span>
                        </span>
                        <span class="dash-list__side">
                            <?php if ($isUnread): ?>
                                <span class="badge badge--draft badge--small"><?= $esc(t('Новая')) ?></span>
                            <?php endif; ?>
                            <span class="dash-list__time"><?= DateFormatter::format((string) $sub['created_at'], 'd.m H:i') ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($recentItems)): ?>
<div class="form-card continue-card">
    <?= AdminUi::cardHeader(t('Продолжить работу'), 'history', 'var(--admin-violet)') ?>
    <p class="form-hint"><?= $esc(t('Последние материалы, которые редактировались.')) ?></p>
    <div class="continue-list">
        <?php foreach ($recentItems as $item): ?>
            <?php
            $isNews = ($item['kind'] ?? '') === 'news';
            $editUrl = ($isNews ? '/admin/news/' : '/admin/pages/') . (int) $item['id'] . '/edit';
            $isDraft = ($item['status'] ?? '') === 'draft';
            ?>
            <a href="<?= $esc($editUrl) ?>" class="continue-item">
                <span class="continue-item__kind"><?= $isNews ? $esc(t('Новость')) : $esc(t('Страница')) ?></span>
                <span class="continue-item__title"><?= $esc($item['title']) ?></span>
                <span class="badge <?= $isDraft ? 'badge--draft' : 'badge--published' ?>"><?= $isDraft ? $esc(t('Черновик')) : $esc(t('Опубликовано')) ?></span>
                <span class="continue-item__time"><?= DateFormatter::format((string) $item['updated_at'], 'd.m.Y H:i') ?></span>
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
    $points[] = [$x, $y];
    $i++;
}
$pointsStr = implode(' ', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $points));
$fillPointsStr = $padding . ',' . ($height - $padding) . ' ' . $pointsStr . ' ' . ($width - $padding) . ',' . ($height - $padding);
?>
<div class="dashboard-grid">
    <?php if ($canManageSubmissions): ?>
    <div class="form-card">
        <?= AdminUi::cardHeader(t('Активность заявок'), 'chart-line', 'var(--admin-accent)') ?>
        <p class="form-hint"><?= $esc(t('Число заполненных форм обратной связи за последние 7 дней.')) ?></p>
        <div class="dash-chart">
            <svg class="dash-chart__svg" viewBox="0 0 500 220" role="img"
                 aria-label="<?= $esc(t('Число заполненных форм обратной связи за последние 7 дней.')) ?>">
                <defs>
                    <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--admin-accent)" stop-opacity="0.3"></stop>
                        <stop offset="100%" stop-color="var(--admin-accent)" stop-opacity="0"></stop>
                    </linearGradient>
                </defs>
                <?php for ($grid = 0; $grid <= 4; $grid++): ?>
                    <?php $gy = $padding + ($chartHeight / 4) * $grid; ?>
                    <line x1="<?= $padding ?>" y1="<?= $gy ?>" x2="<?= $width - $padding ?>" y2="<?= $gy ?>" stroke="var(--admin-border)" stroke-width="1" stroke-dasharray="4,4"></line>
                <?php endfor; ?>
                <polygon points="<?= $fillPointsStr ?>" fill="url(#chartGrad)"></polygon>
                <polyline points="<?= $pointsStr ?>" fill="none" stroke="var(--admin-accent)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                <?php $i = 0; foreach ($chartData as $date => $count): ?>
                    <?php [$cx, $cy] = $points[$i]; ?>
                    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="5" fill="var(--admin-surface)" stroke="var(--admin-accent)" stroke-width="2"></circle>
                    <text class="dash-chart__value" x="<?= $cx ?>" y="<?= $cy - 10.0 ?>" text-anchor="middle"><?= (int) $count ?></text>
                    <text class="dash-chart__axis" x="<?= $cx ?>" y="<?= $height - $padding + 18 ?>" text-anchor="middle"><?= $esc(DateFormatter::format((string) $date, 'd.m')) ?></text>
                <?php $i++; endforeach; ?>
            </svg>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canManageAudit): ?>
    <div class="form-card">
        <?= AdminUi::cardHeader(t('Журнал действий'), 'activity', 'var(--admin-neutral)') ?>
        <p class="form-hint"><?= $esc(t('Последние действия администраторов в панели управления.')) ?></p>
        <div class="activity-feed">
            <?php if (empty($recentLogs)): ?>
                <p class="form-hint"><?= $esc(t('Действий пока нет.')) ?></p>
            <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                    <div class="activity-item">
                        <div class="activity-item__meta">
                            <strong><?= $esc($log['username'] ?? 'System') ?></strong>
                            <span class="activity-item__time"><?= DateFormatter::format((string) $log['created_at'], 'H:i d.m.Y') ?></span>
                        </div>
                        <div class="activity-item__desc">
                            <?php $m = strtoupper((string) ($log['method'] ?? '')); ?>
                            <span class="activity-item__badge activity-item__badge--<?= strtolower($m) ?>"><?= $esc($m) ?></span>
                            <?php if ($m === 'AUTH'): ?>
                                <?php $authMeta = \App\Models\AuditLog::authEventMeta((string) ($log['path'] ?? '')); ?>
                                <span><?= $esc($authMeta['label']) ?></span>
                            <?php else: ?>
                                <code><?= $esc($log['path'] ?? '') ?></code>
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

<?php if (!empty($topReadNews)): ?>
<div class="form-card">
    <?= AdminUi::cardHeader(
        t('Самые читаемые новости'),
        'eye',
        'var(--admin-success)',
        '<a href="/admin/news" class="btn btn--small">' . $esc(t('Все новости')) . ' →</a>'
    ) ?>
    <p class="form-hint"><?= $esc(t('Наибольшее число просмотров среди читателей за последние 30 дней.')) ?></p>
    <div class="dash-list">
        <?php foreach ($topReadNews as $idx => $n): ?>
            <a class="dash-list__item" href="/admin/news/<?= (int) $n['id'] ?>/edit">
                <span class="dash-list__main">
                    <span class="dash-list__rank"><?= (int) $idx + 1 ?></span>
                    <span class="dash-list__text">
                        <span class="dash-list__title"><?= $esc($n['title']) ?></span>
                        <span class="dash-list__meta"><?= DateFormatter::format((string) ($n['published_at'] ?? 'now'), 'd.m.Y') ?></span>
                    </span>
                </span>
                <span class="dash-list__side">
                    <span class="badge badge--published badge--small"
                          title="<?= $esc(t('просмотров')) ?>"
                          aria-label="<?= $esc(number_format((int) ($n['period_views'] ?? 0), 0, '.', ' ') . ' ' . t('просмотров')) ?>">
                        <?= AdminUi::icon('eye', 13) ?> <?= $esc(number_format((int) ($n['period_views'] ?? 0), 0, '.', ' ')) ?>
                    </span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($popularSearches)): ?>
<div class="form-card">
    <?= AdminUi::cardHeader(t('Популярные поиски на сайте'), 'search', 'var(--admin-info)') ?>
    <p class="form-hint"><?= $esc(t('Что посетители чаще всего ищут через внутренний поиск за 30 дней.')) ?></p>
    <div class="dash-tags">
        <?php foreach ($popularSearches as $s): ?>
            <?php $resCnt = (int) $s['last_results_count']; ?>
            <span class="dash-tag">
                <span class="dash-tag__icon" aria-hidden="true"><?= AdminUi::icon('search', 16) ?></span>
                <strong>«<?= $esc($s['query']) ?>»</strong>
                <span class="badge badge--small"><?= (int) $s['searches_count'] ?> <?= $esc(t('запросов')) ?></span>
                <?php if ($resCnt === 0): ?>
                    <span class="badge badge--draft badge--small"><?= $esc(t('0 результатов')) ?></span>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($topRepoDownloads)): ?>
<div class="form-card">
    <?= AdminUi::cardHeader(
        t('Популярные файлы репозитория'),
        'download',
        'var(--admin-success)',
        '<a href="/admin/repository" class="btn btn--small">' . $esc(t('Перейти в репозиторий')) . ' →</a>'
    ) ?>
    <div class="dash-list">
        <?php foreach ($topRepoDownloads as $doc): ?>
            <a class="dash-list__item" href="/repo/download/<?= (int) $doc['id'] ?>" target="_blank" rel="noopener">
                <span class="dash-list__main">
                    <span class="dash-list__text">
                        <span class="dash-list__title"><?= $esc($doc['title']) ?></span>
                        <span class="dash-list__meta"><?= $esc($doc['original_name']) ?></span>
                    </span>
                </span>
                <span class="dash-list__side">
                    <span class="badge badge--published badge--small"
                          aria-label="<?= $esc((int) $doc['download_count'] . ' ' . t('скачиваний')) ?>">
                        <?= AdminUi::icon('download', 13) ?> <?= (int) $doc['download_count'] ?>
                    </span>
                    <span class="dash-list__time"><?= DateFormatter::format((string) $doc['created_at'], 'd.m.Y') ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<?php require __DIR__ . '/layout/footer.php'; ?>
