<?php

use App\Core\Locale;
use App\Models\ContentType;

/** @var array $type */
/** @var array $fields */
/** @var array $entries */
/** @var string $q */
/** @var string $sort */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var bool $hasDeadline */

$metaTitle = t((string) $type['name']);
$metaDescription = t((string) ($type['description'] ?? ''));

// Соседние страницы каталога для <link rel="prev|next">. Поиск и сортировка
// в адресе сохраняются — иначе ссылка вела бы на другой список.
$pagerQuery = static function (int $p) use ($q, $sort): string {
    $params = array_filter([
        'q' => $q !== '' ? $q : null,
        'sort' => $sort !== 'new' ? $sort : null,
        'page' => $p > 1 ? $p : null,
    ], static fn ($value): bool => $value !== null && $value !== '');

    return $params === [] ? '' : '?' . http_build_query($params);
};
['prev' => $pagerPrev, 'next' => $pagerNext] = \App\Core\Pager::relLinks(
    $page,
    $pages,
    static fn (int $p): string => Locale::url(ContentType::path($type)) . $pagerQuery($p)
);
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t((string) $type['name'])],
];
require __DIR__ . '/_crumbs.php';

$baseUrl = Locale::url(ContentType::path($type));
?>
<div class="listing" data-listing>
    <div class="listing__head">
        <h1 class="listing__title"><?= htmlspecialchars(t((string) $type['name']), ENT_QUOTES) ?></h1>
        <?php if (!empty($type['description'])): ?>
            <p class="listing__lead"><?= htmlspecialchars(t((string) $type['description']), ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>

    <form class="catlist-toolbar" method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" role="search" data-listing-form>
        <div class="catlist-toolbar__search">
            <?= \App\Core\Icon::render('search', 17) ?>
            <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('Поиск в разделе'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Поиск в разделе'), ENT_QUOTES) ?>">
        </div>
        <select class="catlist-toolbar__sort" name="sort" data-auto-submit aria-label="<?= htmlspecialchars(t('Сортировка'), ENT_QUOTES) ?>">
            <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>><?= htmlspecialchars(t('Сначала новые'), ENT_QUOTES) ?></option>
            <option value="old" <?= $sort === 'old' ? 'selected' : '' ?>><?= htmlspecialchars(t('Сначала старые'), ENT_QUOTES) ?></option>
            <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>><?= htmlspecialchars(t('По алфавиту'), ENT_QUOTES) ?></option>
        </select>
        <button class="catlist-toolbar__btn" type="submit"><?= htmlspecialchars(t('Найти'), ENT_QUOTES) ?></button>
        <a class="catlist-toolbar__reset" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" data-listing-reset<?= $q === '' ? ' hidden' : '' ?>><?= htmlspecialchars(t('Сбросить'), ENT_QUOTES) ?> <?= \App\Core\Icon::render('refresh', 15) ?></a>
    </form>

    <?php // Область результатов: её же отдаёт контроллер как фрагмент при
          // AJAX-фильтрации, поэтому разметка живёт в одном партиале. ?>
    <div class="listing__results" data-listing-results>
        <?= \App\Core\View::renderPartial('site/_catalog_list', compact('type', 'fields', 'entries', 'q', 'sort', 'page', 'pages', 'total', 'hasDeadline')) ?>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
