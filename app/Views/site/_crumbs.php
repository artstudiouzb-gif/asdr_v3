<?php
/**
 * Хлебные крошки. Ожидает $crumbs = [['label'=>..,'url'=>..], ..]; у последнего
 * элемента url можно опустить (текущая страница). Разметка совместима с
 * .content-crumbs (frontend.css).
 * @var array $crumbs
 */
$crumbs = $crumbs ?? [];
if (count($crumbs) < 2) {
    return;
}

// SEO: та же навигация в разметке Schema.org BreadcrumbList — поисковики
// показывают крошки в выдаче. URL приводим к абсолютным (требование schema).
$crumbsBase = \App\Core\AppUrl::base();
$crumbsSchema = [];
foreach ($crumbs as $c) {
    $u = (string) ($c['url'] ?? '');
    if ($u !== '' && !preg_match('#^https?://#', $u)) {
        $u = $crumbsBase . $u;
    }
    $crumbsSchema[] = [(string) ($c['label'] ?? ''), $u];
}
echo \App\Core\SchemaOrg::render(\App\Core\SchemaOrg::breadcrumbs($crumbsSchema)), "\n";
?>
<nav class="content-crumbs<?= !empty($crumbsClass) ? ' ' . htmlspecialchars((string) $crumbsClass, ENT_QUOTES) : '' ?>" aria-label="<?= htmlspecialchars(t('Хлебные крошки'), ENT_QUOTES) ?>">
    <?php $last = count($crumbs) - 1; ?>
    <?php foreach ($crumbs as $i => $c): ?>
        <?php if (!empty($c['url']) && $i !== $last): ?>
            <a href="<?= htmlspecialchars((string) $c['url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $c['label'], ENT_QUOTES) ?></a>
            <span aria-hidden="true">/</span>
        <?php else: ?>
            <span><?= htmlspecialchars((string) $c['label'], ENT_QUOTES) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
