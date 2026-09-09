<?php

use App\Core\DateFormatter;
use App\Core\Locale;
use App\Models\News;

/**
 * Область результатов списка новостей: крупная новость, сетка и пагинация.
 * Подключается и целой страницей (news_index.php), и отдельно — как фрагмент
 * для AJAX-фильтрации (NewsController::index). Поэтому всё, что нужно для
 * вывода, считается здесь, а не в родительском шаблоне.
 *
 * @var array $items
 * @var int $page
 * @var int $pages
 * @var string $category slug выбранной рубрики ('' — все)
 */
$page = $page ?? 1;
$pages = $pages ?? 1;
$category = $category ?? '';

$lang = Locale::current();
// Дата — единым числовым форматом на всех языках: 19.07.2026.
$fmt = static fn (string $d): string => DateFormatter::short($d);
$pageUrl = static fn (int $p): string => Locale::url('news')
    . (($p > 1 || $category !== '') ? '?' . http_build_query(array_filter(['category' => $category, 'page' => $p > 1 ? $p : null])) : '');

// Названия рубрик для всех карточек одним запросом.
$categoryNames = \App\Models\NewsCategory::namesForIds(
    array_map(static fn (array $item): int => (int) ($item['category_id'] ?? 0), $items),
    $lang
);
$categoryOf = static function (array $item) use ($categoryNames): string {
    $id = (int) ($item['category_id'] ?? 0);

    return $id > 0 ? (string) ($categoryNames[$id] ?? '') : '';
};
?>
<?php if (empty($items)): ?>
    <p class="listing__empty"><?= htmlspecialchars(t('Пока нет опубликованных новостей.'), ENT_QUOTES) ?></p>
<?php else: ?>
    <div class="newslist-grid">
        <?php foreach (array_values($items) as $index => $item): ?>
            <?php
            $c = News::getCoverImage($item);
            // Ритм ленты: четыре ряда — «обложка плюс две компактные» → ряд из
            // четырёх компактных → «две компактные плюс широкая» → ряд из
            // четырёх компактных. Ряд одинаковых карточек читался бы как
            // таблица (App\Core\NewsFeedRhythm).
            $slot = \App\Core\NewsFeedRhythm::slot($index);
            $isHero = $slot === \App\Core\NewsFeedRhythm::SLOT_HERO;
            $isWide = $slot === \App\Core\NewsFeedRhythm::SLOT_WIDE;
            // Анонс — только у крупных: в компактной карточке ему нет места,
            // а обрезанный до строки он не сообщает ничего.
            $excerpt = $isHero || $isWide ? trim((string) ($item['excerpt'] ?? '')) : '';
            // Кадр обложки лежит подложкой всей карточки, поэтому и просят его
            // во всю ширину колонки, а не под размер ячейки.
            $sizes = $isHero
                ? '(max-width: 560px) 100vw, 50vw'
                : ($isWide ? '(max-width: 560px) 100vw, 30vw' : '(max-width: 700px) 100vw, 25vw');
            ?>
            <a class="relnews-card relnews-card--<?= $slot ?>" href="<?= htmlspecialchars(Locale::url('news/' . $item['slug']), ENT_QUOTES) ?>">
                <span class="news-cover">
                    <?php if ($c !== null): ?>
                        <?= \App\Core\Media::picture($c, (string) $item['title'], null, null, 'relnews-card__img', !$isHero, $sizes, $isHero, 'relnews-card__media') ?>
                    <?php else: ?>
                        <span class="relnews-card__media relnews-card__media--empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <?= \App\Core\NewsBadge::renderOverlay($item['badge'] ?? '', $item['badge_color'] ?? null) ?>
                </span>
                <span class="relnews-card__body">
                    <span class="news-meta">
                        <?php if (!empty($item['published_at'])): ?>
                            <time class="relnews-card__date"><?= \App\Core\Icon::render('calendar', 15, 'ui-icon', 1.7) ?><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time>
                        <?php endif; ?>
                        <?php if ($categoryOf($item) !== ''): ?><span class="news-category"><?= htmlspecialchars($categoryOf($item), ENT_QUOTES) ?></span><?php endif; ?>
                    </span>
                    <h3 class="relnews-card__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                    <?php if ($excerpt !== ''): ?>
                        <span class="relnews-card__excerpt"><?= htmlspecialchars($excerpt, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <?php // «Читать подробнее» в ленте нет: карточка сама является
                          // ссылкой, и надпись ничего не добавляла — диктору она
                          // была скрыта (aria-hidden), а глазу повторяла то, что
                          // и так очевидно, зато на каждой из четырнадцати
                          // карточек рисовала лишнюю строку и рвала низ ряда.
                          // В блоках подборок (news_feature) она остаётся: там
                          // карточек три-четыре и ссылка отделяет их от текста. ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php require __DIR__ . '/_pager.php'; ?>
<?php endif; ?>
