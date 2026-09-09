<?php

/** @var array $page */
/** @var string $content */
/** @var string $blockCss */
/** @var string $layoutType */
/** @var array|null $sidebar */

$metaTitle = $page['meta_title'] ?: $page['title'];
// Описание страницы: своё поле → лид → первый абзац содержимого. Пустым оно
// оставаться не должно — именно так главная годами уходила в поиск без
// <meta name="description">, хотя лид у неё был написан.
$metaDescription = trim((string) ($page['meta_description'] ?? ''));
if ($metaDescription === '') {
    $metaDescription = \App\Core\SeoHelper::clip(
        trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($page['lead'] ?? '')))),
        200
    );
}
if ($metaDescription === '') {
    $metaDescription = \App\Core\SeoHelper::autoDescription($content);
}
$extraHeadCss = $blockCss;
$hideChrome = !empty($page['hide_chrome']); // лендинг (группа 6)
// Флаг страницы «Прозрачная шапка» — активирует режим из конструктора.
$transparentHeader = !empty($page['transparent_header']);

// Визуальная система действует на всех страницах, включая главную: пока
// главная была исключением, один и тот же блок выглядел там иначе, чем во
// внутреннем разделе — у заголовков секций пропадала акцентная полоска.
$isHome = \App\Models\Page::isHomePage($page);
$visualSystemScope = true;
require __DIR__ . '/_header.php';

// Блоки, которые сами содержат заголовок страницы. Для них отдельный
// editorial pagehead не нужен, иначе на странице появятся два h1.
$firstIsHero = (bool) preg_match('/^\s*<section\b[^>]*\bcms-block--hero\b/', $content);
$firstOwnsHeading = (bool) preg_match(
    '/^\s*<section\b[^>]*\bcms-block--(?:hero|person_profile)\b/',
    $content
);
$pageLead = trim((string) ($page['lead'] ?? ''));

if ($isHome) {
    // На Главной странице сайта хлебные крошки строго запрещены на любых языках (RU, UZ, EN...).
    $content = preg_replace('/<nav\b[^>]*\bclass="[^"]*\b(?:site-crumbs|content-crumbs)\b[^"]*"[^>]*>.*?<\/nav>/si', '', $content);
}

$ancestorTrail = !$isHome
    ? \App\Models\Page::ancestorTrail($page, \App\Core\Locale::current())
    : [];
$crumbsHtml = '';

// Хлебные крошки для обычных страниц (не главная, не лендинг).
if (!$isHome && !$hideChrome) {
    $crumbs = [
        ['label' => \App\Core\Lang::t('Главная'), 'url' => \App\Core\Locale::url('/')],
    ];
    foreach ($ancestorTrail as $ancestor) {
        if (!empty($ancestor['is_home']) || (string) ($ancestor['slug'] ?? '') === 'home') {
            continue;
        }
        if ((string) ($ancestor['status'] ?? '') !== 'published') {
            continue;
        }
        $crumbs[] = [
            'label' => (string) ($ancestor['title'] ?? ''),
            'url' => \App\Core\Locale::url('/' . ltrim((string) ($ancestor['slug'] ?? ''), '/')),
        ];
    }
    $crumbs[] = ['label' => (string) ($page['title'] ?? '')];

    // Если первый блок страницы — hero (шапка-герой), крошки встраиваем внутрь
    // hero (поверх фона, сверху), а не отдельной полосой над ним.
    if ($firstIsHero) {
        $content = preg_replace('/(class="[^"]*\bcms-block--hero\b)/', '$1 cms-block--page-hero', $content, 1);
        $crumbsClass = 'content-crumbs--on-hero';
        ob_start();
        require __DIR__ . '/_crumbs.php';
        $crumbsHtml = (string) ob_get_clean();
        unset($crumbsClass);
        if ($crumbsHtml !== '') {
            $content = preg_replace('/(<div class="block-hero\b[^>]*>)/', '$1' . addcslashes($crumbsHtml, '\\$'), $content, 1);
        }
        $crumbsHtml = '';
    } else {
        ob_start();
        require __DIR__ . '/_crumbs.php';
        $crumbsHtml = (string) ob_get_clean();
    }
}

// Все обычные внутренние страницы получают единый editorial shell. Hero и
// лендинги сохраняют собственную композицию; профиль руководителя оставляет
// свой h1, но всё равно живёт внутри той же открытой сетки контента.
$editorialPage = !$isHome && !$hideChrome && !$firstIsHero;
$showPageHead = $editorialPage && !$firstOwnsHeading;


$hasSidebar = $sidebar !== null && trim($sidebar['html']) !== '';
?>

<?php if ($editorialPage): ?>
<div class="editorial-page<?= $firstOwnsHeading ? ' editorial-page--block-heading' : '' ?>">
    <?php if ($crumbsHtml !== ''): ?>
        <div class="editorial-page__crumbs"><?= $crumbsHtml ?></div>
    <?php endif; ?>

    <?php if ($showPageHead): ?>
        <header class="content-pagehead content-pagehead--editorial">
            <div class="content-pagehead__inner">
                <div class="content-pagehead__copy">
                    <h1 class="content-pagehead__title"><?= htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES) ?></h1>
                    <?php if ($pageLead !== ''): ?>
                        <p class="content-pagehead__lead"><?= nl2br(htmlspecialchars($pageLead, ENT_QUOTES)) ?></p>
                    <?php endif; ?>
                </div>
                <span class="content-pagehead__emblem" aria-hidden="true"></span>
            </div>
        </header>
    <?php endif; ?>

    <div class="editorial-page__content">
        <?php if ($hasSidebar): ?>
            <div class="layout layout--<?= htmlspecialchars($sidebar['position'], ENT_QUOTES) ?>">
                <?php if ($sidebar['position'] === 'left'): ?>
                    <aside class="layout__sidebar"><?= $sidebar['html'] ?></aside>
                    <div class="layout__main"><?= $content ?></div>
                <?php else: ?>
                    <div class="layout__main"><?= $content ?></div>
                    <aside class="layout__sidebar"><?= $sidebar['html'] ?></aside>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?= $content ?>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
    <?php if ($crumbsHtml !== ''): ?><?= $crumbsHtml ?><?php endif; ?>
    <?php if ($hasSidebar): ?>
        <div class="layout layout--<?= htmlspecialchars($sidebar['position'], ENT_QUOTES) ?>">
            <?php if ($sidebar['position'] === 'left'): ?>
                <aside class="layout__sidebar"><?= $sidebar['html'] ?></aside>
                <div class="layout__main"><?= $content ?></div>
            <?php else: ?>
                <div class="layout__main"><?= $content ?></div>
                <aside class="layout__sidebar"><?= $sidebar['html'] ?></aside>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?= $content ?>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
