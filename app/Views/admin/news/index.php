<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Новости';
$activeNav = 'news';
$pageActions = '<a href="/admin/news/create" class="btn btn--primary">' . AdminUi::icon('plus') . 'Добавить новость</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $filters */
/** @var array $filterParams */
/** @var int $total */
/** @var int $pages */
/** @var array $langCounts */
/** @var array $categories список категорий для фильтра */
/** @var array<int,string> $categoryNames id категории → название на языке списка */
$categories = $categories ?? [];
$categoryNames = $categoryNames ?? [];
$langs = Language::active();
?>

<?= AdminUi::renderLangSubsubsub($filters['lang'], $langCounts ?? [], '/admin/news', $filterParams) ?>
<?= \App\Core\View::renderPartial('admin/layout/section_page_hint', ['section' => 'news']) ?>

<form method="get" action="/admin/news" class="list-filters list-filters--panel">
    <div class="list-filter list-filter--search"><label for="news_q">Поиск</label><input type="search" id="news_q" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Заголовок или slug"></div>
    <div class="list-filter"><label for="news_status">Статус</label><select id="news_status" name="status">
        <option value="">Все статусы</option><option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Опубликованные</option><option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Черновики</option>
    </select></div>
    <div class="list-filter"><label for="news_lang">Язык</label><select id="news_lang" name="lang">
        <option value="all" <?= $filters['lang'] === 'all' ? 'selected' : '' ?>>Все языки</option>
        <?php foreach ($langs as $l): ?><option value="<?= htmlspecialchars($l['code'], ENT_QUOTES) ?>" <?= $filters['lang'] === $l['code'] ? 'selected' : '' ?>><?= $l['code'] === Language::defaultCode() ? 'Основной: ' : '' ?><?= htmlspecialchars($l['name'], ENT_QUOTES) ?></option><?php endforeach; ?>
    </select></div>
    <div class="list-filter"><label for="news_category">Категория</label><select id="news_category" name="category">
        <?php $categoryFilter = (string) ($filters['category'] ?? ''); ?>
        <option value="">Все категории</option>
        <option value="none" <?= $categoryFilter === 'none' ? 'selected' : '' ?>>Без категории</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryFilter === (string) (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $c['name'], ENT_QUOTES) ?></option><?php endforeach; ?>
    </select></div>
    <div class="list-filter"><label for="news_from">Дата от</label><input type="date" id="news_from" name="from" value="<?= htmlspecialchars($filters['from'], ENT_QUOTES) ?>"></div>
    <div class="list-filter"><label for="news_to">Дата до</label><input type="date" id="news_to" name="to" value="<?= htmlspecialchars($filters['to'], ENT_QUOTES) ?>"></div>
    <div class="list-filter"><label for="news_sort">Сортировка</label><select id="news_sort" name="sort">
        <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Сначала новые</option><option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Сначала старые</option><option value="published_desc" <?= $filters['sort'] === 'published_desc' ? 'selected' : '' ?>>По дате публикации</option><option value="title_asc" <?= $filters['sort'] === 'title_asc' ? 'selected' : '' ?>>Название А–Я</option><option value="title_desc" <?= $filters['sort'] === 'title_desc' ? 'selected' : '' ?>>Название Я–А</option>
    </select></div>
    <div class="list-filter list-filter--compact"><label for="news_per_page">На странице</label><select id="news_per_page" name="per_page"><?php foreach ([20, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <div class="list-filters__actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('filter') ?>Применить</button><a href="/admin/news" class="btn"><?= \App\Core\AdminUi::icon('reset') ?>Сбросить</a></div>
</form>

<p class="list-results">Найдено: <strong><?= (int) $total ?></strong></p>

<form id="bulkform" method="post" action="/admin/bulk/news" class="bulk-bar" data-bulk-form>
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
            <th>Категория</th>
            <th>Языки</th>
            <th>Статус</th>
            <th>Дата публикации</th>
            <th>Соцсети</th>
            <th class="data-table__action-cell">Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="8" class="data-table__empty">Новостей не найдено.</td></tr>
        <?php endif; ?>
        <?php
        // Языки контента для всех строк одним запросом (без N+1) и список
        // активных языков сайта — чтобы показать и недостающие переводы.
        $itemIds = array_map(static fn ($i): int => (int) $i['id'], $items);
        $langMap = \App\Models\News::availableLangsForIds($itemIds, true);
        $siteLangs = array_map(static fn (array $l): string => (string) $l['code'], $langs);
        $readyNets = \App\Core\SocialSettings::readyNetworks();
        // Статус публикации в соцсети по всем строкам одним запросом (без N+1).
        $socialStatus = \App\Models\SocialPost::statusForNewsIds($itemIds);
        $socialNetNames = ['telegram' => 'Telegram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram'];
        ?>
        <?php foreach ($items as $item): ?>
            <tr>
                <td class="u-inline-5aec6ffae3"><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" form="bulkform" data-bulk-item aria-label="Выбрать новость"></td>
                <td class="data-table__flex">
                    <div>
                        <a class="data-table__primary" href="/admin/news/<?= (int) $item['id'] ?>/edit"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></a>
                        <?php if (trim((string) ($item['badge'] ?? '')) !== ''): ?>
                            <?php // Метка показывается своим цветом: иначе редактор увидит её только на сайте. ?>
                            <span class="news-mark"<?= \App\Core\NewsBadge::styleAttr($item['badge_color'] ?? null) ?>><?= htmlspecialchars((string) $item['badge'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="u-inline-a9efa5449f">
                    <?php $itemCategory = (int) ($item['category_id'] ?? 0); ?>
                    <?php if ($itemCategory > 0 && isset($categoryNames[$itemCategory])): ?>
                        <a href="/admin/news?<?= htmlspecialchars(http_build_query(array_merge($filterParams, ['category' => $itemCategory, 'page' => null])), ENT_QUOTES) ?>"><?= htmlspecialchars($categoryNames[$itemCategory], ENT_QUOTES) ?></a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', ['siteLangs' => $siteLangs, 'has' => $langMap[(int) $item['id']] ?? [], 'module' => 'news', 'origId' => (int) $item['id']]) ?></td>
                <td>
                    <span class="badge badge--<?= $item['status'] ?>">
                        <?= $item['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?>
                    </span>
                </td>
                <td><?= $item['published_at'] ? htmlspecialchars($item['published_at'], ENT_QUOTES) : '—' ?></td>
                <?php
                // Колонка «Соцсети»: статус прошлой публикации + кнопка отправки.
                $ss = $socialStatus[(int) $item['id']] ?? null;
                $sentNets = $ss ? array_values(array_unique(array_map(static fn (string $n): string => $socialNetNames[$n] ?? $n, $ss['networks']))) : [];
                $alreadySent = $ss !== null && $ss['sent'] > 0;
                $lastSent = $alreadySent && $ss['last_sent'] ? substr((string) $ss['last_sent'], 0, 16) : '';
                ?>
                <td class="news-social">
                    <?php if ($item['status'] !== 'published'): ?>
                        <span class="news-social__meta">—</span>
                    <?php else: ?>
                        <?php if (!empty($readyNets)): ?>
                            <div class="news-social__btns">
                                <?php foreach ($readyNets as $net): ?>
                                    <?php
                                    $netLabel = $socialNetNames[$net] ?? ucfirst($net);
                                    $netSent = $ss !== null && in_array($net, $ss['networks'], true);
                                    $netConfirm = $netSent
                                        ? 'Новость «' . $item['title'] . '» уже публиковалась в ' . $netLabel . '. Отправить повторно?'
                                        : 'Опубликовать «' . $item['title'] . '» в ' . $netLabel . '?';
                                    $netTitle = $netSent
                                        ? 'Опубликовано в ' . $netLabel . ' (' . ($lastSent !== '' ? $lastSent : 'успешно') . '). Кликните для повторной отправки'
                                        : 'Опубликовать в ' . $netLabel;
                                    ?>
                                    <form method="post" action="/admin/news/<?= (int) $item['id'] ?>/social" data-confirm="<?= htmlspecialchars($netConfirm, ENT_QUOTES) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="from" value="list">
                                        <input type="hidden" name="network" value="<?= htmlspecialchars($net, ENT_QUOTES) ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
                                        <button type="submit" class="btn btn--small btn--social btn--social--icon-only btn--social-<?= htmlspecialchars($net, ENT_QUOTES) ?> <?= $netSent ? 'is-published' : 'is-pending' ?>" title="<?= htmlspecialchars($netTitle, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($netTitle, ENT_QUOTES) ?>">
                                            <?= \App\Core\AdminUi::icon($net, 16) ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($ss !== null && !empty($ss['scheduled'])): ?>
                                <?php // Пост не завис — ждёт своего времени. ?>
                                <span class="news-social__meta" title="Отложенная публикация">
                                    ⏱ <?= htmlspecialchars(substr((string) $ss['scheduled'], 0, 16), ENT_QUOTES) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="news-social__meta">не настроено</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="data-table__action-cell">
                    <div class="data-table__actions">
                        <a class="btn btn--small btn--icon" href="/admin/news/<?= (int) $item['id'] ?>/edit" title="Редактировать" aria-label="Редактировать"><?= \App\Core\AdminUi::icon('edit') ?></a>
                        <form method="post" action="/admin/news/<?= (int) $item['id'] ?>/duplicate">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--icon" title="Дублировать" aria-label="Дублировать"><?= \App\Core\AdminUi::icon('copy') ?></button>
                        </form>
                        <form method="post" action="/admin/news/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить новость «<?= htmlspecialchars($item['title'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($filterParams), ENT_QUOTES) ?>">
                            <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= \App\Core\AdminUi::icon('trash') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= \App\Core\View::renderPartial('admin/layout/pagination', ['paginationPath' => '/admin/news', 'filterParams' => $filterParams, 'page' => $filters['page'], 'pages' => $pages, 'total' => $total]) ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>