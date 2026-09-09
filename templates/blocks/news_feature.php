<?php

use App\Core\DateFormatter;
use App\Core\NewsBadge;

/** @var array $data */
$title = $data['title'] ?? '';
$allText = trim((string) ($data['all_text'] ?? ''));
$allUrl = trim((string) ($data['all_url'] ?? ''));
$news = $data['news'] ?? [];
// «Колонки» — равные карточки; прежние «Карточки» и «Мозаика» сохраняют
// собственную композицию. Все настройки проверены схемой полей.
// Значение проверено схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];

$featured = $news[0] ?? null;
$rest = array_slice($news, 1);

// Дата — единым числовым форматом на всех языках: 19.07.2026.
$fmt = static fn (string $d): string => DateFormatter::short($d);
// Метка — необязательная пометка со своим цветом; рубрику показывает категория.
$badge = static fn (array $i): string => NewsBadge::render($i['badge'] ?? '', $i['badge_color'] ?? null);
$badgeOverlay = static fn (array $i): string => NewsBadge::renderOverlay($i['badge'] ?? '', $i['badge_color'] ?? null);
$badgeOnMedia = static fn (array $i): string => NewsBadge::render($i['badge'] ?? '', $i['badge_color'] ?? null, true);
$category = static fn (array $i): string => trim((string) ($i['category'] ?? ''));
$more = '<span class="card-more">' . htmlspecialchars(t('Читать подробнее'), ENT_QUOTES)
    . '<span class="card-more__arrow" aria-hidden="true">→</span></span>';
// В мозаике вся карточка — ссылка, поэтому подпись «Читать подробнее» лишняя:
// остаётся одна стрелка как знак перехода. Диктору она не нужна — имя ссылке
// даёт заголовок внутри неё, — поэтому целиком aria-hidden.
if ($variant === 'mosaic') {
    $more = '<span class="card-more card-more--arrow" aria-hidden="true">'
        . '<span class="card-more__arrow">→</span></span>';
}

// Колонки мозаики задаёт тема (.block-newsfeat--mosaic): раскладка одна для
// всех блоков этого варианта, поэтому scoped-правило только мешало бы — оно
// грузится последним и перебивало общую сетку в четыре колонки.
$templateCss = '';
?>
<div class="block-newsfeat block-newsfeat--<?= $variant ?>">
    <div class="section-head">
        <?php if ($title !== ''): ?><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
        <?php if ($allText !== '' && $allUrl !== ''): ?><a class="section-head__all" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($allText, ENT_QUOTES) ?> →</a><?php endif; ?>
    </div>

    <?php if ($featured === null): ?>
        <p class="block-newsfeat__empty"><?= htmlspecialchars(t('Новостей пока нет.'), ENT_QUOTES) ?></p>
    <?php elseif ($variant === 'columns'): ?>
        <div class="news-columns news-columns--<?= (int) $data['columns'] ?>">
            <?php foreach ($news as $item): ?>
                <a class="news-column" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                    <span class="news-column__cover news-cover">
                        <?php if (!empty($item['cover'])): ?>
                            <?= \App\Core\Media::picture((string) $item['cover'], '', null, null, 'news-column__image', true, '(max-width: 560px) 100vw, (max-width: 1000px) 50vw, ' . (int) ceil(100 / $data['columns']) . 'vw') ?>
                        <?php else: ?>
                            <span class="news-column__empty" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?= $badgeOverlay($item) ?>
                    </span>
                    <span class="news-column__body">
                        <span class="news-meta">
                            <?php if (!empty($item['published_at'])): ?><time class="news-column__date"><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                            <?php if ($category($item) !== ''): ?><span class="news-category"><?= htmlspecialchars($category($item), ENT_QUOTES) ?></span><?php endif; ?>
                        </span>
                        <span class="news-column__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                        <span class="news-column__arrow" aria-hidden="true">→</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php elseif ($variant === 'cards'): ?>
        <?php // Макет «карточки»: тот же вид, что и на странице новостей. ?>
        <a class="newslist-lead" href="<?= htmlspecialchars((string) $featured['url'], ENT_QUOTES) ?>">
            <span class="news-cover">
                <?php if (!empty($featured['cover'])): ?>
                    <?= \App\Core\Media::picture((string) $featured['cover'], (string) $featured['title'], null, null, 'newslist-lead__img', false, '(max-width: 900px) 100vw, 55vw', false, 'newslist-lead__media') ?>
                <?php else: ?>
                    <span class="newslist-lead__media newslist-lead__media--empty" aria-hidden="true"></span>
                <?php endif; ?>
                <?= $badgeOverlay($featured) ?>
            </span>
            <span class="newslist-lead__body">
                <span class="news-meta">
                    <?php if (!empty($featured['published_at'])): ?><time class="newslist__date"><?= htmlspecialchars($fmt((string) $featured['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                    <?php if ($category($featured) !== ''): ?><span class="news-category"><?= htmlspecialchars($category($featured), ENT_QUOTES) ?></span><?php endif; ?>
                </span>
                <span class="newslist-lead__title"><?= htmlspecialchars((string) $featured['title'], ENT_QUOTES) ?></span>
                <?php if (!empty($featured['excerpt'])): ?><span class="newslist-lead__excerpt"><?= htmlspecialchars(excerpt((string) $featured['excerpt'], 260), ENT_QUOTES) ?></span><?php endif; ?>
                <?= $more ?>
            </span>
        </a>

        <?php if (!empty($rest)): ?>
            <div class="newslist-grid">
                <?php foreach ($rest as $item): ?>
                    <a class="relnews-card" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                        <span class="news-cover">
                            <?php if (!empty($item['cover'])): ?>
                                <?= \App\Core\Media::picture((string) $item['cover'], (string) $item['title'], null, null, 'relnews-card__img', true, '(max-width: 700px) 100vw, 25vw', false, 'relnews-card__media') ?>
                            <?php else: ?>
                                <span class="relnews-card__media relnews-card__media--empty" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?= $badgeOverlay($item) ?>
                        </span>
                        <span class="news-meta">
                            <?php if (!empty($item['published_at'])): ?><time class="relnews-card__date"><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                            <?php if ($category($item) !== ''): ?><span class="news-category"><?= htmlspecialchars($category($item), ENT_QUOTES) ?></span><?php endif; ?>
                        </span>
                        <span class="relnews-card__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                        <?php if (!empty($item['excerpt'])): ?><span class="relnews-card__excerpt"><?= htmlspecialchars(excerpt((string) $item['excerpt'], 150), ENT_QUOTES) ?></span><?php endif; ?>
                        <?= $more ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($allUrl !== ''): ?>
            <div class="newsfeat-more">
                <a class="newsfeat-more__btn" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>">
                    <?= htmlspecialchars(t('Перейти ко всем материалам'), ENT_QUOTES) ?>
                    <span class="card-more__arrow" aria-hidden="true">→</span>
                </a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        // Макет «мозаика»: крупная новость с текстом на обложке, справа две
        // карточки с фото и следом компактные строки без обложек.
        // Ряды мозаики фиксированы: две карточки с фото и четыре без.
        $withThumb = array_slice($rest, 0, 2);
        $textOnly = array_slice($rest, 2, 4);
        ?>
        <div class="newsfeat-grid">
            <a class="newsfeat-lead" href="<?= htmlspecialchars((string) $featured['url'], ENT_QUOTES) ?>">
                <span class="newsfeat-lead__frame">
                    <?php if (!empty($featured['cover'])): ?>
                        <?= \App\Core\Media::picture((string) $featured['cover'], (string) $featured['title'], null, null, 'newsfeat-lead__media', false, '(max-width: 900px) 100vw, 50vw') ?>
                    <?php else: ?>
                        <span class="newsfeat-lead__media newsfeat-lead__media--empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <?= $badgeOverlay($featured) ?>
                    <span class="newsfeat-lead__over">
                        <span class="news-meta">
                            <?php if (!empty($featured['published_at'])): ?><time class="newsfeat__date newsfeat__date--on-media"><?= htmlspecialchars($fmt((string) $featured['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                            <?php if ($category($featured) !== ''): ?><span class="news-category news-category--on-media"><?= htmlspecialchars($category($featured), ENT_QUOTES) ?></span><?php endif; ?>
                        </span>
                        <span class="newsfeat-lead__title"><?= htmlspecialchars((string) $featured['title'], ENT_QUOTES) ?></span>
                        <?php if (!empty($featured['excerpt'])): ?><span class="newsfeat-lead__excerpt"><?= htmlspecialchars(excerpt((string) $featured['excerpt'], 260), ENT_QUOTES) ?></span><?php endif; ?>
                        <span class="card-more card-more--on-media"><?= htmlspecialchars(t('Читать подробнее'), ENT_QUOTES) ?><span class="card-more__arrow" aria-hidden="true">→</span></span>
                    </span>
                </span>
            </a>

            <div class="newsfeat-side">
                <?php if (!empty($withThumb)): ?>
                    <div class="newsfeat-side__thumbs">
                        <?php foreach ($withThumb as $item): ?>
                            <a class="newsfeat-mini" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                                <span class="newsfeat-mini__thumb news-cover">
                                    <?php if (!empty($item['cover'])): ?>
                                        <?= \App\Core\Media::picture((string) $item['cover'], (string) $item['title'], null, null, 'newsfeat-mini__media', true, '(max-width: 700px) 100vw, 25vw') ?>
                                    <?php else: ?>
                                        <span class="newsfeat-mini__media newsfeat-mini__media--empty" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <?= $badgeOverlay($item) ?>
                                </span>
                                <span class="newsfeat-mini__body">
                                    <span class="news-meta">
                                        <?php if (!empty($item['published_at'])): ?><time class="newsfeat__date"><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                                        <?php if ($category($item) !== ''): ?><span class="news-category"><?= htmlspecialchars($category($item), ENT_QUOTES) ?></span><?php endif; ?>
                                    </span>
                                    <span class="newsfeat-mini__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                                    <?php if (!empty($item['excerpt'])): ?><span class="newsfeat-mini__excerpt"><?= htmlspecialchars(excerpt((string) $item['excerpt'], 120), ENT_QUOTES) ?></span><?php endif; ?>
                                    <?= $more ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($textOnly)): ?>
                    <div class="newsfeat-side__texts">
                        <?php foreach ($textOnly as $item): ?>
                            <a class="newsfeat-text" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                                <span class="news-meta">
                                    <?php if (!empty($item['published_at'])): ?><time class="newsfeat__date"><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                                    <?php if ($category($item) !== ''): ?><span class="news-category"><?= htmlspecialchars($category($item), ENT_QUOTES) ?></span><?php endif; ?>
                                    <?= $badge($item) ?>
                                </span>
                                <span class="newsfeat-text__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                                <span class="newsfeat-text__arrow" aria-hidden="true">→</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
