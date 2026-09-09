<?php

use App\Core\Locale;

/** @var string $query */
/** @var array $results */

$metaTitle = $query !== '' ? (t('Поиск:') . ' ' . $query) : t('Поиск по сайту');
$metaDescription = '';
$robotsNoindex = true; // страницы результатов поиска не индексируем
require __DIR__ . '/_header.php';
?>
<div class="site-search-page">
    <h1><?= htmlspecialchars(t('Поиск по сайту'), ENT_QUOTES) ?></h1>
    <form method="get" action="<?= htmlspecialchars(Locale::url('search'), ENT_QUOTES) ?>" class="site-search-page__form" role="search">
        <input type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('Что вы ищете?'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Поисковый запрос'), ENT_QUOTES) ?>" autofocus>
        <button type="submit"><?= htmlspecialchars(t('Найти'), ENT_QUOTES) ?></button>
    </form>

    <?php if ($query !== '' && mb_strlen($query) < 2): ?>
        <p class="site-search-page__hint"><?= htmlspecialchars(t('Введите не менее двух символов.'), ENT_QUOTES) ?></p>
    <?php elseif ($query !== ''): ?>
        <p class="site-search-page__count">
            <?php if (empty($results)): ?>
                <?= htmlspecialchars(t('По запросу'), ENT_QUOTES) ?> «<?= htmlspecialchars($query, ENT_QUOTES) ?>» <?= htmlspecialchars(t('ничего не найдено.'), ENT_QUOTES) ?>
            <?php else: ?>
                <?= htmlspecialchars(t('Найдено результатов:'), ENT_QUOTES) ?> <?= count($results) ?>
            <?php endif; ?>
        </p>
        <ul class="site-search-results">
            <?php foreach ($results as $r): ?>
                <li class="site-search-results__item">
                    <span class="site-search-results__type"><?= htmlspecialchars((string) $r['type'], ENT_QUOTES) ?></span>
                    <a class="site-search-results__link" href="<?= htmlspecialchars((string) $r['url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $r['title'], ENT_QUOTES) ?></a>
                    <?php if (!empty($r['excerpt'])): ?>
                        <p class="site-search-results__excerpt"><?= htmlspecialchars((string) $r['excerpt'], ENT_QUOTES) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>