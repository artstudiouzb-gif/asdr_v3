<?php

use App\Core\AdminUi;
use App\Core\Csrf;

$pageTitle = 'Формы';
$activeNav = 'forms';
/** @var array<int,array<string,mixed>> $items */
/** @var bool $canManageSubmissions */
/** @var array{total:int,unread:int,read:int}|null $submissionSummary */
$items = $items ?? [];
$canManageSubmissions = $canManageSubmissions ?? false;
$submissionSummary = $submissionSummary ?? null;

$pageActions = '';
if ($canManageSubmissions) {
    $pageActions .= '<a href="/admin/forms/submissions" class="btn">'
        . AdminUi::icon('inbox') . 'Все заявки</a>';
}
$pageActions .= '<a href="/admin/forms/create" class="btn btn--primary">'
    . AdminUi::icon('plus') . 'Добавить форму</a>';
require __DIR__ . '/../layout/header.php';
?>

<p class="form-hint admin-section-intro">
    Формы определяют поля, которые видит посетитель сайта. Отправленные данные
    сохраняются отдельно в разделе «Заявки».
</p>

<nav class="settings-jump-nav" aria-label="Разделы форм">
    <a href="/admin/forms"><?= AdminUi::icon('forms', 16) ?>Формы</a>
    <?php if ($canManageSubmissions): ?>
        <a href="/admin/forms/submissions">
            <?= AdminUi::icon('inbox', 16) ?>Заявки
            <?php if (($submissionSummary['unread'] ?? 0) > 0): ?>
                <span class="badge badge--danger"><?= (int) $submissionSummary['unread'] ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</nav>

<?php if ($canManageSubmissions): ?>
    <div class="submission-summary">
        <a href="/admin/forms/submissions" class="submission-summary__card">
            <span>Всего заявок</span>
            <strong><?= (int) ($submissionSummary['total'] ?? 0) ?></strong>
        </a>
        <a href="/admin/forms/submissions?status=unread" class="submission-summary__card submission-summary__card--new">
            <span>Новых</span>
            <strong><?= (int) ($submissionSummary['unread'] ?? 0) ?></strong>
        </a>
        <a href="/admin/forms/submissions?status=read" class="submission-summary__card">
            <span>Просмотрено</span>
            <strong><?= (int) ($submissionSummary['read'] ?? 0) ?></strong>
        </a>
    </div>
<?php endif; ?>

<div class="form-card">
    <div class="forms-list-heading">
        <div>
            <h2 class="u-inline-291b7bbb01">Созданные формы</h2>
            <p class="form-hint">Откройте заявки нужной формы или измените её поля и уведомления.</p>
        </div>
        <span class="badge badge--draft"><?= count($items) ?> форм</span>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Форма</th>
                <th>Идентификатор</th>
                <th>Полученные заявки</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="4" class="data-table__empty">
                        Форм пока нет.<br>
                        <a href="/admin/forms/create" class="btn btn--small">Создать первую форму</a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?></strong>
                    </td>
                    <td><code><?= htmlspecialchars((string) $item['slug'], ENT_QUOTES) ?></code></td>
                    <td>
                        <?php if ($canManageSubmissions): ?>
                            <a class="submission-count-link" href="/admin/forms/<?= (int) $item['id'] ?>/submissions">
                                <strong><?= (int) ($item['submissions_total'] ?? 0) ?></strong>
                                <span>всего</span>
                                <?php if (($item['unread'] ?? 0) > 0): ?>
                                    <span class="badge badge--danger"><?= (int) $item['unread'] ?> новых</span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <span class="form-hint">Доступны только администратору</span>
                        <?php endif; ?>
                    </td>
                    <td class="data-table__actions">
                        <?php if ($canManageSubmissions): ?>
                            <a class="btn btn--small btn--primary" href="/admin/forms/<?= (int) $item['id'] ?>/submissions">
                                <?= AdminUi::icon('inbox') ?>Открыть заявки
                            </a>
                        <?php endif; ?>
                        <a class="btn btn--small" href="/admin/forms/<?= (int) $item['id'] ?>/edit">
                            <?= AdminUi::icon('edit') ?>Редактировать
                        </a>
                        <?php if ($canManageSubmissions): ?>
                            <form method="post" action="/admin/forms/<?= (int) $item['id'] ?>/delete"
                                  data-confirm="Удалить форму «<?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?>» вместе со всеми её заявками?">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--small btn--danger">
                                    <?= AdminUi::icon('trash') ?>Удалить
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
