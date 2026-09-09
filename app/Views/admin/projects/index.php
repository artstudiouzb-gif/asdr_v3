<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Проекты';
$activeNav = 'projects';
$pageActions = '<a href="/admin/projects/create" class="btn btn--primary">' . AdminUi::icon('plus') . 'Добавить проект</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $filters */
/** @var array $filterParams */
/** @var int $total */
/** @var int $pages */
/** @var array $langCounts */
$langs = Language::active();
?>

<?= AdminUi::renderLangSubsubsub($filters['lang'], $langCounts ?? [], '/admin/projects', $filterParams) ?>
<?= \App\Core\View::renderPartial('admin/layout/section_page_hint', ['section' => 'projects']) ?>

<form method="get" action="/admin/projects" class="list-filters list-filters--panel">
    <div class="list-filter list-filter--search"><label for="projects_q">Поиск</label><input type="search" id="projects_q" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Название, slug или описание"></div>
    <div class="list-filter"><label for="projects_status">Статус</label><select id="projects_status" name="status"><option value="">Все статусы</option><option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Опубликованные</option><option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Черновики</option></select></div>
    <div class="list-filter"><label for="projects_sort">Сортировка</label><select id="projects_sort" name="sort"><option value="manual" <?= $filters['sort'] === 'manual' ? 'selected' : '' ?>>Заданный порядок</option><option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Сначала новые</option><option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Сначала старые</option><option value="title_asc" <?= $filters['sort'] === 'title_asc' ? 'selected' : '' ?>>Название А–Я</option><option value="title_desc" <?= $filters['sort'] === 'title_desc' ? 'selected' : '' ?>>Название Я–А</option></select></div>
    <div class="list-filter list-filter--compact"><label for="projects_per_page">На странице</label><select id="projects_per_page" name="per_page"><?php foreach ([20, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <div class="list-filters__actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('filter') ?>Применить</button><a href="/admin/projects" class="btn"><?= \App\Core\AdminUi::icon('reset') ?>Сбросить</a></div>
</form>

<p class="list-results">Найдено: <strong><?= (int) $total ?></strong></p>

<form id="bulkform" method="post" action="/admin/bulk/projects" class="bulk-bar" data-bulk-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
    <select name="bulk_action" required>
        <option value="">С выбранными…</option>
        <option value="publish">Опубликовать</option>
        <option value="unpublish">Снять с публикации</option>
        <option value="duplicate">Дублировать</option>
        <option value="trash">В корзину</option>
    </select>
    <button type="submit" class="btn">Применить</button>
    <span class="bulk-bar__count" data-bulk-count>0 выбрано</span>
</form>

<table class="data-table">
    <thead>
        <tr>
            <th class="u-inline-5aec6ffae3"><input type="checkbox" data-select-all form="bulkform" aria-label="Выбрать все"></th>
            <th>Название</th>
            <th>Языки</th>
            <th>Статус</th>
            <th>Порядок</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="6" class="data-table__empty">Проектов не найдено.</td></tr>
        <?php endif; ?>
        <?php
        // Языки контента для всех строк одним запросом (без N+1).
        $langMap = \App\Models\Project::availableLangsForIds(array_map(static fn ($i): int => (int) $i['id'], $items), true);
        $siteLangs = array_map(static fn (array $l): string => (string) $l['code'], $langs);
        ?>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" form="bulkform" data-bulk-item></td>
                <td>
                    <a class="data-table__primary" href="/admin/projects/<?= (int) $item['id'] ?>/edit"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></a>
                    <?php if (!empty($item['is_featured'])): ?><span class="badge badge--success" title="Показывается в блоке «Проекты» на главной"><?= \App\Core\AdminUi::icon('home', 13) ?> на главной</span><?php endif; ?>
                </td>
                <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', ['siteLangs' => $siteLangs, 'has' => $langMap[(int) $item['id']] ?? [], 'module' => 'projects', 'origId' => (int) $item['id']]) ?></td>
                <td>
                    <span class="badge badge--<?= $item['status'] ?>">
                        <?= $item['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?>
                    </span>
                </td>
                <td><?= (int) $item['sort_order'] ?></td>
                <td class="data-table__actions">
                    <a class="btn btn--small btn--icon" href="/admin/projects/<?= (int) $item['id'] ?>/edit" title="Редактировать" aria-label="Редактировать"><?= \App\Core\AdminUi::icon('edit') ?></a>
                    <form method="post" action="/admin/projects/<?= (int) $item['id'] ?>/duplicate">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--icon" title="Дублировать" aria-label="Дублировать"><?= \App\Core\AdminUi::icon('copy') ?></button>
                    </form>
                    <form method="post" action="/admin/projects/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить проект «<?= htmlspecialchars($item['title'], ENT_QUOTES) ?>»?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
                        <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= \App\Core\AdminUi::icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?= \App\Core\View::renderPartial('admin/layout/pagination', ['paginationPath' => '/admin/projects', 'filterParams' => $filterParams, 'page' => $filters['page'], 'pages' => $pages, 'total' => $total]) ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
