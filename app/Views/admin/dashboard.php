<?php

use App\Core\AdminUi;
use App\Core\DateFormatter;

$pageTitle = t('Дашборд');
$activeNav = 'dashboard';
require __DIR__ . '/layout/header.php';

/** @var array $user */
/** @var array $counts */
/** @var array<int, array<string, mixed>> $recentLogs */
/** @var array<int, array<string, mixed>> $recentItems */
/** @var list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}> $attention */
/** @var int $attentionTotal */
/** @var array<int, array<string, mixed>> $brokenLinks */
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

/**
 * Строка «Требует внимания»: значок состояния, название факта, ответ и что
 * с ним делать. Разметка одна на все строки — они приходят из одного списка
 * (`SystemHealth`), и вторая форма строки читалась бы как другой вид факта.
 */
$attentionRow = static function (array $check) use ($esc): string {
    $fail = ($check['state'] ?? '') === \App\Core\SystemHealth::FAIL;
    $id = (string) ($check['id'] ?? 'unknown');
    $anchor = 'health-' . preg_replace('/[^a-z0-9_-]+/i', '-', $id);
    $solution = \App\Core\SystemHealth::solution($id);
    $href = $solution['href'] !== '' ? $solution['href'] : '/admin/health#' . $anchor;

    return '<div class="dash-status__row dash-status__row--' . ($fail ? 'fail' : 'warn') . '">'
        . '<span class="dash-status__icon" aria-hidden="true">'
        . AdminUi::icon($fail ? 'alert-triangle' : 'alert-circle', 18) . '</span>'
        . '<span class="dash-status__body">'
        . '<span class="dash-status__label">' . $esc($check['title'] ?? '') . '</span>'
        . '<span class="dash-status__value">' . $esc($check['value'] ?? '') . '</span>'
        . (($check['hint'] ?? '') !== ''
            ? '<span class="dash-status__hint">' . $esc($check['hint']) . '</span>'
            : '')
        . '</span>'
        . '<a class="btn btn--small dash-status__action" href="' . $esc($href) . '">'
        . AdminUi::icon('arrow-right', 14) . ' ' . $esc($solution['label']) . '</a>'
        . '</div>';
};
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
    <?php /* Плиток «Формы» и «Активные языки» здесь нет: первая считала
             определения форм, вторая — настройку. Оба числа месяцами не
             меняются, то есть занимали место, ничего не сообщая. */ ?>
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
    <?php if ((int) ($counts['repo_files'] ?? 0) > 0): ?>
        <?php /* Портал файлов включён не везде: пустая плитка «0 скачиваний
                 из 0 файлов» сообщает только о том, что раздел не используют. */ ?>
        <?= $statCard('/admin/repository', 'download', 'success', (int) ($counts['repo_downloads'] ?? 0), t('Скачиваний репозитория'),
            (int) ($counts['repo_files'] ?? 0) . ' ' . t('файлов')) ?>
    <?php endif; ?>
</div>

<div class="dashboard-grid">
    <!-- Виджет: что в системе требует вмешательства -->
    <div class="form-card">
        <?= AdminUi::cardHeader(
            $attentionTotal > 0 ? t('Требует внимания') : t('Состояние системы'),
            $attentionTotal > 0 ? 'alert-triangle' : 'circle-check',
            $attentionTotal > 0 ? 'var(--admin-danger)' : 'var(--admin-success)',
            '<a href="/admin/health" class="btn btn--small">' . $esc(t('Все проверки')) . ' →</a>'
        ) ?>
        <?php if ($attention === []): ?>
            <div class="dash-status">
                <div class="dash-status__row dash-status__row--ok">
                    <span class="dash-status__icon" aria-hidden="true"><?= AdminUi::icon('circle-check', 18) ?></span>
                    <span class="dash-status__body">
                        <span class="dash-status__label"><?= $esc(t('Всё в порядке')) ?></span>
                        <span class="dash-status__value"><?= $esc(t('Проверки состояния не нашли, чем заняться.')) ?></span>
                    </span>
                </div>
            </div>
        <?php else: ?>
            <div class="dash-status dash-status--attention">
                <?php foreach ($attention as $check): ?>
                    <?= $attentionRow($check) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($attentionTotal > count($attention)): ?>
                <p class="form-hint">
                    <?= $esc(t('И ещё')) ?> <?= $attentionTotal - count($attention) ?> —
                    <a href="/admin/health"><?= $esc(t('в разделе «Состояние системы»')) ?></a>.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

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

<?php if ($canManageAudit): ?>
<div class="dashboard-grid">
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

    <?php if (!empty($brokenLinks)): ?>
    <?php /* Битые ссылки: посетитель по ним уже приходил и ничего не нашёл.
             Починка — один редирект, и она в двух шагах отсюда. Пустого
             списка не бывает: карточка появляется только при находках. */ ?>
    <div class="form-card">
        <?= AdminUi::cardHeader(
            t('Битые ссылки'),
            'unlink',
            'var(--admin-danger)',
            '<a href="/admin/redirects" class="btn btn--small">' . $esc(t('Редиректы')) . ' →</a>'
        ) ?>
        <p class="form-hint"><?= $esc(t('Адреса, по которым посетители получили «страница не найдена».')) ?></p>
        <div class="dash-list">
            <?php foreach ($brokenLinks as $nf): ?>
                <a class="dash-list__item" href="/admin/redirects">
                    <span class="dash-list__main">
                        <span class="dash-list__text">
                            <span class="dash-list__title"><?= $esc($nf['path']) ?></span>
                            <?php if (!empty($nf['last_referer'])): ?>
                                <span class="dash-list__meta"><?= $esc(t('Ссылаются с')) ?>: <?= $esc($nf['last_referer']) ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="dash-list__side">
                        <span class="badge badge--draft badge--small"
                              aria-label="<?= $esc((int) $nf['hits'] . ' ' . t('обращений')) ?>">
                            <?= (int) $nf['hits'] ?>
                        </span>
                        <span class="dash-list__time"><?= DateFormatter::format((string) $nf['last_hit_at'], 'd.m H:i') ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
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
