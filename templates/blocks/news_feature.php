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
$badgeOverlay = static fn (array $i): string => NewsBadge::renderOverlay($i['badge'] ?? '', $i['badge_color'] ?? null);
$category = static fn (array $i): string => trim((string) ($i['category'] ?? ''));
// «Читать подробнее» остаётся только у «Карточек»: там материалов три-четыре и
// ссылка отделяет их от текста. В мозаике карточка сама является ссылкой, и
// подпись повторяла бы очевидное на каждой из шести.
$more = '<span class="card-more">' . htmlspecialchars(t('Читать подробнее'), ENT_QUOTES)
    . '<span class="card-more__arrow" aria-hidden="true">→</span></span>';

// Раскладку мозаики задаёт сетка ленты (.newslist-grid) — та же, что на
// /news, поэтому scoped-правило только мешало бы: оно грузится последним и
// перебивало бы общие колонки.
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
        // Макет «мозаика»: тот же ритм и то же семейство карточек, что и в
        // ленте /news (App\Core\NewsFeedRhythm) — обложка, четыре компактные и
        // широкая, зигзагом. Шесть карточек и восемь ячеек, поэтому ряд без
        // дыры получается на любой ширине.
        //
        // Прежде у мозаики было своё семейство (newsfeat-lead / -mini /
        // -text), и четыре карточки нижнего ряда шли **без обложек** по
        // замыслу. На главной ряд из четырёх текстовых строк под рядом с
        // фотографиями читается как «картинки не загрузились», поэтому вид
        // сведён к ленте: у каждой новости есть кадр, а вес карточкам задаёт
        // размер плитки, а не наличие снимка.
        $mosaicDate = $fmt;
        ?>
        <div class="newslist-grid">
            <?php foreach (array_slice($news, 0, \App\Core\NewsFeedRhythm::BLOCK_SIZE) as $mosaicIndex => $item): ?>
                <?php
                $slot = \App\Core\NewsFeedRhythm::blockSlot($mosaicIndex);
                $card = $item;
                $cardDate = $mosaicDate;
                require APP_ROOT . '/app/Views/site/_news_rhythm_card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
