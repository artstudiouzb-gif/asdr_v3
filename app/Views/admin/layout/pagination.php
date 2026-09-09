<?php

/** @var string $paginationPath */
/** @var array $filterParams */
/** @var int $page */
/** @var int $pages */
/** @var int $total */

if ($pages <= 1) {
    return;
}

$pageUrl = static function (int $target) use ($paginationPath, $filterParams): string {
    $params = $filterParams;
    if ($target > 1) {
        $params['page'] = $target;
    } else {
        unset($params['page']);
    }
    $query = http_build_query($params);

    return $paginationPath . ($query !== '' ? '?' . $query : '');
};

$numbers = array_values(array_unique(array_filter([
    1,
    $page - 2,
    $page - 1,
    $page,
    $page + 1,
    $page + 2,
    $pages,
], static fn (int $number): bool => $number >= 1 && $number <= $pages)));
sort($numbers);
$previous = 0;
?>
<nav class="admin-pagination" aria-label="Навигация по страницам">
    <span class="admin-pagination__summary"><?= (int) $total ?> записей</span>
    <?php if ($page > 1): ?>
        <a class="btn btn--small" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES) ?>" rel="prev">←</a>
    <?php endif; ?>
    <?php foreach ($numbers as $number): ?>
        <?php if ($previous !== 0 && $number - $previous > 1): ?><span class="admin-pagination__gap">…</span><?php endif; ?>
        <?php if ($number === $page): ?>
            <span class="btn btn--small btn--primary" aria-current="page"><?= $number ?></span>
        <?php else: ?>
            <a class="btn btn--small" href="<?= htmlspecialchars($pageUrl($number), ENT_QUOTES) ?>"><?= $number ?></a>
        <?php endif; ?>
        <?php $previous = $number; ?>
    <?php endforeach; ?>
    <?php if ($page < $pages): ?>
        <a class="btn btn--small" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES) ?>" rel="next">→</a>
    <?php endif; ?>
</nav>
