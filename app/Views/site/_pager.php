<?php

use App\Core\Pager;

/**
 * Полоса страниц. Общая для ленты новостей и каталогов: расходясь, две копии
 * дали бы разное поведение на половинах сайта.
 *
 * @var int $page текущая страница
 * @var int $pages всего страниц
 * @var callable(int): string $pageUrl адрес страницы по номеру
 */
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));

if ($pages < 2) {
    return;
}

$items = Pager::items($page, $pages);
?>
<nav class="listing-pager" aria-label="<?= htmlspecialchars(t('Страницы'), ENT_QUOTES) ?>">
    <?php if ($page > 1): ?>
        <a class="listing-pager__item listing-pager__item--nav" rel="prev" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES) ?>">
            <span aria-hidden="true">←</span>
            <span class="visually-hidden"><?= htmlspecialchars(t('Предыдущая страница'), ENT_QUOTES) ?></span>
        </a>
    <?php endif; ?>

    <?php foreach ($items as $item): ?>
        <?php if ($item === null): ?>
            <?php // Разрыв — не кнопка: диктору о нём сообщать не о чем. ?>
            <span class="listing-pager__gap" aria-hidden="true">…</span>
        <?php elseif ($item === $page): ?>
            <span class="listing-pager__item is-active" aria-current="page"><?= (int) $item ?></span>
        <?php else: ?>
            <a class="listing-pager__item" href="<?= htmlspecialchars($pageUrl($item), ENT_QUOTES) ?>"><?= (int) $item ?></a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($page < $pages): ?>
        <a class="listing-pager__item listing-pager__item--nav" rel="next" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES) ?>">
            <span aria-hidden="true">→</span>
            <span class="visually-hidden"><?= htmlspecialchars(t('Следующая страница'), ENT_QUOTES) ?></span>
        </a>
    <?php endif; ?>
</nav>
