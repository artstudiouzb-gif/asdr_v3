<?php

use App\Core\AdminUi;

/** @var array<int,array<string,mixed>> $forms */
/** @var array<string,mixed>|null $selectedForm */
/** @var bool $fixedForm */
/** @var array<int,array<string,mixed>> $submissions */
/** @var array{total:int,unread:int,read:int} $summary */
/** @var array{form_id:int,status:string,q:string} $filters */
$forms = $forms ?? [];
$selectedForm = $selectedForm ?? null;
$fixedForm = $fixedForm ?? false;
$submissions = $submissions ?? [];
$summary = $summary ?? ['total' => 0, 'unread' => 0, 'read' => 0];
$filters = $filters ?? ['form_id' => 0, 'status' => '', 'q' => ''];
$total = (int) ($total ?? 0);
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);

$pageTitle = $selectedForm
    ? 'Заявки: ' . (string) $selectedForm['name']
    : 'Все заявки';
$activeNav = 'forms';
$pageActions = '<a href="/admin/forms" class="btn">'
    . AdminUi::icon('forms') . 'Управление формами</a>';
require __DIR__ . '/../layout/header.php';

$queryParams = array_filter([
    'q' => $filters['q'] !== '' ? $filters['q'] : null,
    'status' => $filters['status'] !== '' ? $filters['status'] : null,
    'form_id' => $filters['form_id'] > 0 ? $filters['form_id'] : null,
    'page' => $page > 1 ? $page : null,
], static fn (mixed $value): bool => $value !== null && $value !== '');
$returnQuery = http_build_query($queryParams);
$preview = static function (array $data): string {
    $values = [];
    foreach ($data as $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $values[] = $text;
        }
        if (count($values) === 2) {
            break;
        }
    }

    return mb_substr(implode(' · ', $values), 0, 180);
};
?>

<p class="form-hint admin-section-intro">
    Здесь находятся данные, отправленные посетителями через формы сайта.
    Новая заявка считается прочитанной только после открытия её карточки.
</p>

<nav class="settings-jump-nav" aria-label="Разделы форм">
    <a href="/admin/forms"><?= AdminUi::icon('forms', 16) ?>Формы</a>
    <a href="/admin/forms/submissions"><?= AdminUi::icon('inbox', 16) ?>Все заявки</a>
    <?php if ($selectedForm): ?>
        <a href="/admin/forms/<?= (int) $selectedForm['id'] ?>/edit">
            <?= AdminUi::icon('edit', 16) ?>Настроить эту форму
        </a>
    <?php endif; ?>
</nav>

<div class="submission-summary">
    <a href="<?= $selectedForm ? '/admin/forms/' . (int) $selectedForm['id'] . '/submissions' : '/admin/forms/submissions' ?>"
       class="submission-summary__card">
        <span>Всего</span>
        <strong><?= (int) $summary['total'] ?></strong>
    </a>
    <a href="?<?= http_build_query(array_filter(['form_id' => $filters['form_id'] ?: null, 'status' => 'unread'])) ?>"
       class="submission-summary__card submission-summary__card--new">
        <span>Новых</span>
        <strong><?= (int) $summary['unread'] ?></strong>
    </a>
    <a href="?<?= http_build_query(array_filter(['form_id' => $filters['form_id'] ?: null, 'status' => 'read'])) ?>"
       class="submission-summary__card">
        <span>Просмотрено</span>
        <strong><?= (int) $summary['read'] ?></strong>
    </a>
</div>

<form method="get" action="/admin/forms/submissions" class="admin-filter-bar form-card">
    <?php if ($fixedForm && $selectedForm): ?>
        <input type="hidden" name="form_id" value="<?= (int) $selectedForm['id'] ?>">
    <?php else: ?>
        <div class="form-field">
            <label for="submission_form">Форма</label>
            <select id="submission_form" name="form_id">
                <option value="">Все формы</option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?= (int) $form['id'] ?>" <?= (int) $filters['form_id'] === (int) $form['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $form['name'], ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="form-field">
        <label for="submission_status">Статус</label>
        <select id="submission_status" name="status">
            <option value="">Все</option>
            <option value="unread" <?= $filters['status'] === 'unread' ? 'selected' : '' ?>>Новые</option>
            <option value="read" <?= $filters['status'] === 'read' ? 'selected' : '' ?>>Просмотренные</option>
        </select>
    </div>
    <div class="form-field admin-filter-bar__search">
        <label for="submission_q">Поиск в данных заявки</label>
        <input type="search" id="submission_q" name="q" maxlength="120"
               value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>"
               placeholder="Имя, телефон, email…">
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= AdminUi::icon('search') ?>Найти</button>
        <a href="<?= $fixedForm && $selectedForm ? '/admin/forms/' . (int) $selectedForm['id'] . '/submissions' : '/admin/forms/submissions' ?>"
           class="btn">Сбросить</a>
    </div>
</form>

<div class="form-card">
    <div class="forms-list-heading">
        <div>
            <h2 class="u-inline-291b7bbb01">
                <?= $selectedForm ? htmlspecialchars((string) $selectedForm['name'], ENT_QUOTES) : 'Журнал заявок' ?>
            </h2>
            <p class="form-hint">Найдено записей: <?= $total ?></p>
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Статус</th>
                <th>Дата</th>
                <?php if (!$selectedForm): ?><th>Форма</th><?php endif; ?>
                <th>Краткое содержание</th>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($submissions)): ?>
                <tr>
                    <td colspan="<?= $selectedForm ? 4 : 5 ?>" class="data-table__empty">
                        Заявок с выбранными условиями пока нет.
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($submissions as $submission): ?>
                <?php $isUnread = (int) ($submission['is_read'] ?? 0) === 0; ?>
                <tr class="<?= $isUnread ? 'submission-row--unread' : '' ?>">
                    <td>
                        <span class="badge badge--<?= $isUnread ? 'danger' : 'success' ?>">
                            <?= $isUnread ? 'Новая' : 'Просмотрена' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $submission['created_at'], 'd.m.Y H:i'), ENT_QUOTES) ?></td>
                    <?php if (!$selectedForm): ?>
                        <td><?= htmlspecialchars((string) $submission['form_name'], ENT_QUOTES) ?></td>
                    <?php endif; ?>
                    <td class="submission-preview">
                        <?= htmlspecialchars($preview((array) $submission['data']), ENT_QUOTES) ?: '—' ?>
                    </td>
                    <td class="data-table__actions">
                        <a class="btn btn--small btn--<?= $isUnread ? 'primary' : 'outline' ?>"
                           href="/admin/forms/submissions/<?= (int) $submission['id'] ?>?return_query=<?= urlencode($returnQuery) ?>">
                            <?= AdminUi::icon('eye') ?>Открыть
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?= \App\Core\View::renderPartial('admin/layout/pagination', [
        'paginationPath' => '/admin/forms/submissions',
        'filterParams' => array_filter([
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
            'status' => $filters['status'] !== '' ? $filters['status'] : null,
            'form_id' => $filters['form_id'] > 0 ? $filters['form_id'] : null,
        ]),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
    ]) ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
