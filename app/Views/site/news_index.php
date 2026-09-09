<?php

use App\Core\AssetCollector;
use App\Core\Locale;

/** @var array $items */
/** @var int $page */
/** @var int $pages */
/** @var list<array<string,mixed>> $categories */
/** @var string $category slug выбранной рубрики */
$page = $page ?? 1;
$pages = $pages ?? 1;
$categories = $categories ?? [];
$category = $category ?? '';

/** @var array|null $sectionPage шапка раздела из админки (может отсутствовать) */
$sectionPage = $sectionPage ?? null;
$sectionTitle = (string) ($sectionPage['title'] ?? '');
$sectionLead = (string) ($sectionPage['lead'] ?? '');
$sectionMetaTitle = (string) ($sectionPage['meta_title'] ?? '');
$metaTitle = $sectionMetaTitle !== '' ? $sectionMetaTitle : ($sectionTitle !== '' ? $sectionTitle : t('Новости'));
$metaDescription = (string) ($sectionPage['meta_description'] ?? '');
if ($metaDescription === '') {
    $metaDescription = $sectionLead !== ''
    ? $sectionLead
    : t('Официальные новости и аналитические материалы Агентства.');
}
// Стили блоков раздела уезжают в <head> тем же путём, что и у страницы.
$extraHeadCss = (string) ($sectionPage['css'] ?? '');

// Соседние страницы ленты для <link rel="prev|next">: адрес тот же, что у
// полосы страниц, поэтому строим его тем же способом.
$pagerLink = static fn (int $p): string => Locale::url('news')
    . (($p > 1 || $category !== '') ? '?' . http_build_query(array_filter(['category' => $category, 'page' => $p > 1 ? $p : null])) : '');
['prev' => $pagerPrev, 'next' => $pagerNext] = \App\Core\Pager::relLinks($page, $pages, $pagerLink);
AssetCollector::requireJs('news');
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Новости')],
];
require __DIR__ . '/_crumbs.php';

?>
<div class="listing" data-listing>
    <div class="listing__head">
        <h1 class="listing__title"><?= htmlspecialchars($sectionTitle !== '' ? $sectionTitle : t('Новости и аналитика'), ENT_QUOTES) ?></h1>
        <p class="listing__lead"><?= htmlspecialchars($sectionLead !== '' ? $sectionLead : t('Официальные сообщения, события и аналитические материалы Агентства.'), ENT_QUOTES) ?></p>
    </div>

    <?php if ($categories !== []): ?>
        <nav class="listing-filter" aria-label="<?= htmlspecialchars(t('Рубрики'), ENT_QUOTES) ?>">
            <a class="listing-filter__item<?= $category === '' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(Locale::url('news'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Все материалы'), ENT_QUOTES) ?></a>
            <?php foreach ($categories as $c): ?>
                <?php // Иконка рубрики — украшение рядом с названием: название
                      // читается и без неё, поэтому значок скрыт от диктора. ?>
                <?php $icon = \App\Core\Icon::cleanName($c['icon'] ?? ''); ?>
                <a class="listing-filter__item<?= (string) $c['slug'] === $category ? ' is-active' : '' ?><?= $icon !== '' ? ' listing-filter__item--icon' : '' ?>" href="<?= htmlspecialchars(Locale::url('news') . '?category=' . rawurlencode((string) $c['slug']), ENT_QUOTES) ?>"><?php if ($icon !== ''): ?><span class="listing-filter__icon" aria-hidden="true"><?= \App\Core\Icon::render($icon, 17, '', 1.8) ?></span><?php endif; ?><?= htmlspecialchars((string) $c['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>


    <?php // Область результатов: её же отдаёт контроллер как фрагмент при
          // AJAX-фильтрации, поэтому разметка живёт в одном партиале. ?>
    <div class="listing__results" data-listing-results>
        <?= \App\Core\View::renderPartial('site/_news_list', compact('items', 'page', 'pages', 'category')) ?>
    </div>

    <?php // Блоки страницы-раздела — под лентой: наверху уже её шапка. ?>
    <?php if (!empty($sectionPage['content'])): ?>
        <div class="listing__section-blocks"><?= $sectionPage['content'] ?></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>