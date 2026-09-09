<?php

use App\Core\Csrf;

$pageTitle = $type['name'] ?? 'Записи';
$activeNav = 'content:' . ($type['slug'] ?? '');
$pageActions = '<a href="/admin/content/' . htmlspecialchars((string) ($type['slug'] ?? ''), ENT_QUOTES)
    . '/create" class="btn btn--primary">' . \App\Core\AdminUi::icon('plus') . 'Добавить запись</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $type */
/** @var array $items */
/** @var array $filters */
/** @var array $filterParams */
/** @var int $total */
/** @var int $pages */
$contentPath = '/admin/content/' . rawurlencode((string) $type['slug']);
?>
<form method="get" action="<?= htmlspecialchars($contentPath, ENT_QUOTES) ?>" class="list-filters list-filters--panel">
    <div class="list-filter list-filter--search"><label for="content_q">Поиск</label><input type="search" id="content_q" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Заголовок, slug или содержимое"></div>
    <div class="list-filter"><label for="content_status">Статус</label><select id="content_status" name="status"><option value="">Все статусы</option><option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Опубликованные</option><option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Черновики</option></select></div>
    <div class="list-filter"><label for="content_sort">Сортировка</label><select id="content_sort" name="sort"><option value="manual" <?= $filters['sort'] === 'manual' ? 'selected' : '' ?>>Заданный порядок</option><option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Сначала новые</option><option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Сначала старые</option><option value="title_asc" <?= $filters['sort'] === 'title_asc' ? 'selected' : '' ?>>Название А–Я</option><option value="title_desc" <?= $filters['sort'] === 'title_desc' ? 'selected' : '' ?>>Название Я–А</option></select></div>
    <div class="list-filter list-filter--compact"><label for="content_per_page">На странице</label><select id="content_per_page" name="per_page"><?php foreach ([20, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <div class="list-filters__actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('filter') ?>Применить</button><a href="<?= htmlspecialchars($contentPath, ENT_QUOTES) ?>" class="btn"><?= \App\Core\AdminUi::icon('reset') ?>Сбросить</a></div>
</form>
<p class="list-results">Найдено: <strong><?= (int) $total ?></strong></p>

<table class="data-table">
    <thead><tr><th>Заголовок</th><th>Статус</th><th>Обновлено</th><th></th></tr></thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="4" class="data-table__empty">
                <?php if ($filters['q'] === '' && $filters['status'] === ''): ?>
                    Записей пока нет.<br><a href="<?= htmlspecialchars($contentPath, ENT_QUOTES) ?>/create" class="btn btn--small"><?= \App\Core\AdminUi::icon('plus') ?>Добавить первую запись</a>
                <?php else: ?>
                    По заданным фильтрам ничего не найдено.
                <?php endif; ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($items as $e): ?>
            <tr>
                <td><a class="data-table__primary" href="<?= htmlspecialchars($contentPath, ENT_QUOTES) ?>/<?= (int) $e['id'] ?>/edit"><?= htmlspecialchars((string) $e['title'], ENT_QUOTES) ?></a></td>
                <td><span class="badge badge--<?= $e['status'] ?>"><?= $e['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?></span></td>
                <td><?= htmlspecialchars((string) $e['updated_at'], ENT_QUOTES) ?></td>
                <td class="data-table__actions">
                    <a class="btn btn--small" href="/admin/content/<?= htmlspecialchars((string) $type['slug'], ENT_QUOTES) ?>/<?= (int) $e['id'] ?>/edit"><?= \App\Core\AdminUi::icon('edit') ?>Редактировать</a>
                    <form method="post" action="/admin/content/<?= htmlspecialchars((string) $type['slug'], ENT_QUOTES) ?>/<?= (int) $e['id'] ?>/delete" data-confirm="Удалить запись?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
                        <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?= \App\Core\View::renderPartial('admin/layout/pagination', ['paginationPath' => $contentPath, 'filterParams' => $filterParams, 'page' => $filters['page'], 'pages' => $pages, 'total' => $total]) ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>