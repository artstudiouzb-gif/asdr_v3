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
            // Ритм ленты: четыре ряда — «обложка плюс две компактные» → ряд из
            // четырёх компактных → «две компактные плюс широкая» → ряд из
            // четырёх компактных. Ряд одинаковых карточек читался бы как
            // таблица (App\Core\NewsFeedRhythm).
            $slot = \App\Core\NewsFeedRhythm::slot($index);
            // Разметка карточки — общая с мозаикой блока подборки.
            $card = [
                'url' => Locale::url('news/' . $item['slug']),
                'title' => (string) $item['title'],
                'published_at' => (string) ($item['published_at'] ?? ''),
                'excerpt' => (string) ($item['excerpt'] ?? ''),
                'badge' => (string) ($item['badge'] ?? ''),
                'badge_color' => $item['badge_color'] ?? null,
                'category' => $categoryOf($item),
                'cover' => News::getCoverImage($item),
            ];
            $cardDate = $fmt;
            require __DIR__ . '/_news_rhythm_card.php';
            ?>
        <?php endforeach; ?>
    </div>

    <?php require __DIR__ . '/_pager.php'; ?>
<?php endif; ?>
