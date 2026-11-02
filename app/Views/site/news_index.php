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
/** @var array<int, list<int>> $archive год → месяцы с новостями */
$archive = $archive ?? [];
$year = (int) ($year ?? 0);
$month = (int) ($month ?? 0);

/** @var array|null $sectionPage шапка раздела из админки (может отсутствовать) */
$sectionPage = $sectionPage ?? null;
$sectionTitle = (string) ($sectionPage['title'] ?? '');
$sectionLead = (string) ($sectionPage['lead'] ?? '');
$sectionMetaTitle = (string) ($sectionPage['meta_title'] ?? '');
$metaTitle = $sectionMetaTitle !== '' ? $sectionMetaTitle : ($sectionTitle !== '' ? $sectionTitle : t('Новости'));
// У каждого месяца архива свой адрес в поиске — и свой заголовок, иначе
// десятки страниц с одним <title> поиск считает дублями.
if ($year > 0) {
    $metaTitle .= ' — ' . ($month > 0 ? \App\Core\NewsArchive::monthName($month) . ' ' : '') . $year;
}
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
$pagerLink = static fn (int $p): string => \App\Core\NewsArchive::url($category, $year, $month, $p);
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
        <?php require __DIR__ . '/_page_mark.php'; ?>
        <h1 class="listing__title"><?= htmlspecialchars($sectionTitle !== '' ? $sectionTitle : t('Новости и аналитика'), ENT_QUOTES) ?></h1>
        <p class="listing__lead"><?= htmlspecialchars($sectionLead !== '' ? $sectionLead : t('Официальные сообщения, события и аналитические материалы Агентства.'), ENT_QUOTES) ?></p>
    </div>

    <?php if ($categories !== []): ?>
        <nav class="listing-filter" aria-label="<?= htmlspecialchars(t('Рубрики'), ENT_QUOTES) ?>">
            <a class="listing-filter__item<?= $category === '' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url('', $year, $month), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Все материалы'), ENT_QUOTES) ?></a>
            <?php foreach ($categories as $c): ?>
                <?php // Иконка рубрики — украшение рядом с названием: название
                      // читается и без неё, поэтому значок скрыт от диктора. ?>
                <?php $icon = \App\Core\Icon::cleanName($c['icon'] ?? ''); ?>
                <a class="listing-filter__item<?= (string) $c['slug'] === $category ? ' is-active' : '' ?><?= $icon !== '' ? ' listing-filter__item--icon' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url((string) $c['slug'], $year, $month), ENT_QUOTES) ?>"><?php if ($icon !== ''): ?><span class="listing-filter__icon" aria-hidden="true"><?= \App\Core\Icon::render($icon, 17, '', 1.8) ?></span><?php endif; ?><?= htmlspecialchars((string) $c['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>


    <?php // Архив по годам и месяцам. Ссылки обычные, без перехвата
          // скриптом: список месяцев меняется вместе с выбранным годом.
          // Года одного — архив не нужен, его заменяет сама лента. ?>
    <?php if (count($archive) > 1 || ($archive !== [] && count(reset($archive)) > 1)): ?>
        <?php // Годы и месяцы — одна панель из двух рядов: порознь каждый ряд
              // получал полный промежуток секций ленты (до 44px), и месяцы
              // висели отдельной полосой, не связанной с выбранным годом. ?>
        <div class="listing-archive">
            <nav class="listing-archive__row" aria-label="<?= htmlspecialchars(t('Архив новостей'), ENT_QUOTES) ?>">
                <span class="listing-archive__label"><?= htmlspecialchars(t('Архив'), ENT_QUOTES) ?></span>
                <span class="listing-archive__list">
                    <a class="listing-archive__item<?= $year === 0 ? ' is-active' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url($category), ENT_QUOTES) ?>"<?= $year === 0 ? ' aria-current="page"' : '' ?>><?= htmlspecialchars(t('За все годы'), ENT_QUOTES) ?></a>
                    <?php foreach (array_keys($archive) as $y): ?>
                        <a class="listing-archive__item<?= $y === $year ? ' is-active' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url($category, $y), ENT_QUOTES) ?>"<?= $y === $year && $month === 0 ? ' aria-current="page"' : '' ?>><?= $y ?></a>
                    <?php endforeach; ?>
                </span>
            </nav>
            <?php if ($year > 0): ?>
                <nav class="listing-archive__row listing-archive__row--months" aria-label="<?= htmlspecialchars(t('Архив новостей') . ' ' . $year, ENT_QUOTES) ?>">
                    <span class="listing-archive__label"><?= $year ?></span>
                    <span class="listing-archive__list">
                        <a class="listing-archive__item<?= $month === 0 ? ' is-active' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url($category, $year), ENT_QUOTES) ?>"<?= $month === 0 ? ' aria-current="page"' : '' ?>><?= htmlspecialchars(t('Весь год'), ENT_QUOTES) ?></a>
                        <?php foreach (array_reverse($archive[$year] ?? []) as $m): ?>
                            <a class="listing-archive__item<?= $m === $month ? ' is-active' : '' ?>" href="<?= htmlspecialchars(\App\Core\NewsArchive::url($category, $year, $m), ENT_QUOTES) ?>"<?= $m === $month ? ' aria-current="page"' : '' ?>><?= htmlspecialchars(\App\Core\NewsArchive::monthName($m), ENT_QUOTES) ?></a>
                        <?php endforeach; ?>
                    </span>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // Область результатов: её же отдаёт контроллер как фрагмент при
          // AJAX-фильтрации, поэтому разметка живёт в одном партиале. ?>
    <div class="listing__results" data-listing-results>
        <?= \App\Core\View::renderPartial('site/_news_list', compact('items', 'page', 'pages', 'category', 'year', 'month')) ?>
    </div>

    <?php // Блоки страницы-раздела — под лентой: наверху уже её шапка. ?>
    <?php if (!empty($sectionPage['content'])): ?>
        <div class="listing__section-blocks"><?= $sectionPage['content'] ?></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>