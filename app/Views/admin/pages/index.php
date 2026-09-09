<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Страницы';
$activeNav = 'pages';
$pageActions = '<a href="/admin/pages/create" class="btn btn--primary">' . AdminUi::icon('plus') . 'Добавить страницу</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $filters */
/** @var array $filterParams */
/** @var int $total */
/** @var int $pages */
/** @var array $langCounts */
$langs = Language::active();
?>

<?= AdminUi::renderLangSubsubsub($filters['lang'], $langCounts ?? [], '/admin/pages', $filterParams) ?>

<form method="get" action="/admin/pages" class="list-filters list-filters--panel">
    <div class="list-filter list-filter--search"><label for="pages_q">Поиск</label><input type="search" id="pages_q" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Заголовок или slug"></div>
    <div class="list-filter"><label for="pages_status">Статус</label><select id="pages_status" name="status"><option value="">Все статусы</option><option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Опубликованные</option><option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Черновики</option></select></div>
    <div class="list-filter"><label for="pages_lang">Язык</label><select id="pages_lang" name="lang"><option value="all" <?= $filters['lang'] === 'all' ? 'selected' : '' ?>>Все языки</option><?php foreach ($langs as $l): ?><option value="<?= htmlspecialchars($l['code'], ENT_QUOTES) ?>" <?= $filters['lang'] === $l['code'] ? 'selected' : '' ?>><?= $l['code'] === Language::defaultCode() ? 'Основной: ' : '' ?><?= htmlspecialchars($l['name'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
    <div class="list-filter"><label for="pages_sort">Сортировка</label><select id="pages_sort" name="sort"><option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Сначала новые</option><option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Сначала старые</option><option value="title_asc" <?= $filters['sort'] === 'title_asc' ? 'selected' : '' ?>>Название А–Я</option><option value="title_desc" <?= $filters['sort'] === 'title_desc' ? 'selected' : '' ?>>Название Я–А</option></select></div>
    <div class="list-filter list-filter--compact"><label for="pages_per_page">На странице</label><select id="pages_per_page" name="per_page"><?php foreach ([20, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <div class="list-filters__actions"><button type="submit" class="btn btn--primary"><?= AdminUi::icon('filter') ?>Применить</button><a href="/admin/pages" class="btn"><?= AdminUi::icon('reset') ?>Сбросить</a></div>
</form>

<p class="list-results">Найдено: <strong><?= (int) $total ?></strong></p>

<form id="bulkform" method="post" action="/admin/bulk/pages" class="bulk-bar" data-bulk-form>
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

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr>
            <th class="u-inline-5aec6ffae3"><input type="checkbox" data-select-all form="bulkform" aria-label="Выбрать все"></th>
            <th>Заголовок</th>
            <th>Родитель</th>
            <th>URL</th>
            <th>Языки</th>
            <th>Статус</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="7" class="data-table__empty">Страниц не найдено.</td></tr>
        <?php endif; ?>
        <?php
        // Языки контента для всех строк одним запросом (без N+1) и список
        // активных языков сайта — чтобы показать и недостающие переводы.
        $langMap = \App\Models\Page::availableLangsForIds(array_map(static fn ($i): int => (int) $i['id'], $items), true);
        $siteLangs = array_map(static fn (array $l): string => (string) $l['code'], $langs);
        ?>
        <?php foreach ($items as $item): ?>
            <?php $isItemHome = \App\Models\Page::isHomePage($item); ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" form="bulkform" data-bulk-item></td>
                <td>
                    <a class="data-table__primary" href="/admin/pages/<?= (int) $item['id'] ?>/edit"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></a>
                    <?php if ($isItemHome): ?>
                        <span class="badge badge--accent badge--home" title="<?= htmlspecialchars(t('Главная страница'), ENT_QUOTES) ?>"><?= AdminUi::icon('home', 14) ?></span>
                    <?php endif; ?>
                    <?php $itemSection = (string) ($item['section'] ?? ''); ?>
                    <?php if (isset(\App\Models\Page::SECTIONS[$itemSection])): ?>
                        <span class="badge" title="Шапка раздела: страница отдаёт разделу заголовок, лид и SEO">Раздел: <?= htmlspecialchars(\App\Models\Page::SECTIONS[$itemSection], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($item['parent_title'])): ?>
                        <a href="/admin/pages/<?= (int) $item['parent_id'] ?>/edit">
                            <?= htmlspecialchars((string) $item['parent_title'], ENT_QUOTES) ?>
                        </a>
                    <?php else: ?>
                        <span class="form-hint">Верхний уровень</span>
                    <?php endif; ?>
                </td>
                <td>/<?= htmlspecialchars($item['slug'], ENT_QUOTES) ?></td>
                <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', ['siteLangs' => $siteLangs, 'has' => $langMap[(int) $item['id']] ?? [], 'module' => 'pages', 'origId' => (int) $item['id']]) ?></td>
                <td>
                    <span class="badge badge--<?= $item['status'] ?>">
                        <?= $item['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?>
                    </span>
                </td>
                <td class="data-table__actions">
                    <a class="btn btn--small btn--icon" href="/admin/pages/<?= (int) $item['id'] ?>/edit" title="Редактировать" aria-label="Редактировать"><?= AdminUi::icon('edit') ?></a>
                    <form method="post" action="/admin/pages/<?= (int) $item['id'] ?>/duplicate">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--icon" title="Дублировать" aria-label="Дублировать"><?= AdminUi::icon('copy') ?></button>
                    </form>
                    <form method="post" action="/admin/pages/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить страницу «<?= htmlspecialchars($item['title'], ENT_QUOTES) ?>» вместе со всеми блоками?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
                        <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= AdminUi::icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= \App\Core\View::renderPartial('admin/layout/pagination', ['paginationPath' => '/admin/pages', 'filterParams' => $filterParams, 'page' => $filters['page'], 'pages' => $pages, 'total' => $total]) ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>