<?php

use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Команда';
$activeNav = 'team';
$pageActions = '<a href="/admin/team/create" class="btn btn--primary">' . \App\Core\AdminUi::icon('plus') . 'Добавить сотрудника</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
$langs = Language::active();
?>

<form id="bulkform" method="post" action="/admin/bulk/team" class="bulk-bar" data-bulk-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="return_query" value="">
    <select name="bulk_action" required aria-label="Действие с выбранными">
        <option value="">С выбранными…</option>
        <?php foreach (\App\Controllers\Admin\BulkController::labels('team') as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Применить</button>
    <span class="bulk-bar__count" data-bulk-count>0 выбрано</span>
</form>

<table class="data-table">
    <thead>
        <tr>
            <th class="u-inline-5aec6ffae3"><input type="checkbox" data-select-all form="bulkform" aria-label="Выбрать все"></th>
            <th>Имя</th>
            <th>Должность</th>
            <th>Подразделение</th>
            <th>Языки</th>
            <th>Статус</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="7" class="data-table__empty">Сотрудников пока нет.<br><a href="/admin/team/create" class="btn btn--small"><?= \App\Core\AdminUi::icon('plus') ?>Добавить первого сотрудника</a></td></tr>
        <?php endif; ?>
        <?php
        // Языки контента для всех строк одним запросом (без N+1).
        $langMap = \App\Models\TeamMember::availableLangsForIds(array_map(static fn ($i): int => (int) $i['id'], $items));
        $siteLangs = array_map(static fn (array $l): string => (string) $l['code'], $langs);
        ?>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" form="bulkform" data-bulk-item aria-label="Выбрать сотрудника"></td>
                <td><?= htmlspecialchars($item['name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($item['position'] ?? '', ENT_QUOTES) ?></td>
                <td>
                    <?= htmlspecialchars($item['department'] ?? '', ENT_QUOTES) ?>
                    <?php if (trim((string) ($item['unit'] ?? '')) !== ''): ?>
                        <span class="text-muted">— <?= htmlspecialchars((string) $item['unit'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', ['siteLangs' => $siteLangs, 'has' => $langMap[(int) $item['id']] ?? []]) ?></td>
                <td>
                    <span class="badge badge--<?= $item['status'] ?>">
                        <?= $item['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?>
                    </span>
                </td>
                <td class="data-table__actions">
                    <a class="btn btn--small btn--icon" href="/admin/team/<?= (int) $item['id'] ?>/edit" title="Редактировать" aria-label="Редактировать"><?= \App\Core\AdminUi::icon('edit') ?></a>
                    <form method="post" action="/admin/team/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить сотрудника «<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>»?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= \App\Core\AdminUi::icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../layout/footer.php'; ?>