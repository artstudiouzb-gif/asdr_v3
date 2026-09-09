<?php

use App\Core\Icon;
use App\Core\Media;
use App\Core\MediaPosition;

/** @var array $data */
$title = trim((string) ($data['title'] ?? ''));
$allText = trim((string) ($data['all_text'] ?? ''));
$allUrl = trim((string) ($data['all_url'] ?? ''));
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
// «Текст под фото» — та же карточка, только подпись выносится из-под градиента
// на подложку: длинный анонс на снимке читается плохо, что бы ни делали с
// затемнением.
$imageBelow = $variant === 'image_below';
$columns = (int) $data['columns'];
// Раскладка. «Слайдер» доступен любому варианту; «авто» — прежнее поведение
// карточек с фотографией: сетка, а когда карточек больше, чем колонок, полоса.
// Порог считается по настройке «Колонок», а не по числу 4: в сетке и в кадре
// слайдера обязано помещаться одно и то же число карточек, иначе настройка
// действует наполовину. У остальных вариантов «авто» — это сетка: включать им
// прокрутку задним числом значило бы поменять вид собранных страниц.
$layout = (string) $data['layout'];
$itemCount = count($items);
$explicitSlider = $layout === 'slider' && $itemCount > 1;
$mediaClasses = MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);
$cardBg = (string) $data['card_bg'];
$textColor = (string) $data['text_color'];
$visualStyle = (string) $data['card_style'];
$iconSize = (int) $data['icon_size'];
$iconBackground = (string) $data['icon_bg'];
$iconPosition = (string) $data['icon_position'];
$textAlign = (string) $data['text_align'];
$iconBoxSize = max(42, $iconSize + 18);
$cardStyle = '--feature-card-icon-size:' . $iconSize . 'px;'
    . ($cardBg !== '' ? '--card-bg:' . $cardBg . ';' : '')
    . ($textColor !== '' ? '--cards-text:' . $textColor . ';' : '');
$scope = '#block-' . (int) $blockId;
// Число колонок принадлежит блоку, а не одному его варианту: сетку карточек с
// фотографиями и кадр слайдера считает та же переменная.
$templateCss = $scope . '{--cards-cols:' . $columns . ';}';
$templateCss .= $scope . ' .block-cards{' . $cardStyle . '}';
$templateCss .= $scope . ' .feature-card__icon{width:' . $iconBoxSize . 'px;height:' . $iconBoxSize . 'px;}';
$cardClasses = ($cardBg !== '' ? ' block-cards--custom-bg' : '')
    . ($textColor !== '' ? ' block-cards--custom-text' : '')
    . ($iconBackground === 'off' ? ' block-cards--icons-no-bg' : '')
    . ' block-cards--icon-pos-' . $iconPosition
    . ' block-cards--text-align-' . $textAlign;

// Новый вид включается только у выбранного экземпляра cards_grid: базовые
// .feature-card и остальные карточки сайта остаются нетронутыми.
if ($variant === 'icon' && $visualStyle === 'new') {
    $cardClasses .= ' block-cards--style-new';
    $templateCss .= $scope . ' .block-cards--style-new .cards-grid{gap:clamp(20px,2.4vw,36px);}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card{background:var(--card-bg,transparent);border:0;border-top:1px solid rgba(23,58,99,.16);border-radius:0;box-shadow:none;min-height:0;padding:26px 0 30px;transform:none;overflow:visible;transition:border-color .18s ease,color .18s ease,background-color .18s ease;}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card::before{display:none;}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card:hover{background:var(--card-bg,transparent);border-top-color:var(--gov-accent,#17999b);box-shadow:none;transform:none;}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card__top{align-items:flex-start;margin-bottom:clamp(22px,2.3vw,34px);}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card__icon{width:' . $iconSize . 'px;height:' . $iconSize . 'px;border:0;border-radius:0;background:transparent;color:var(--gov-accent,#17999b);justify-content:flex-start;}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card__num{color:var(--gov-accent,#17999b);font-size:var(--font-size-meta,.75rem);font-weight:700;letter-spacing:var(--meta-letter-spacing,.12em);}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card__title{color:var(--cards-text,var(--gov-primary,#173a63));font-size:var(--font-size-h3,clamp(18px,1.35vw,21px));line-height:1.25;margin-bottom:10px;}';
    $templateCss .= $scope . ' .block-cards--style-new .feature-card__text{color:var(--cards-text,var(--gov-text,#334155));max-width:34ch;}';
}
?>
<?php if ($variant === 'image' || $imageBelow): ?>
    <?php
    $carousel = $layout !== 'grid' && $itemCount > 1;
    $desktopCarousel = $explicitSlider || ($layout === 'auto' && $itemCount > $columns);
    ?>
    <div class="block-imgcards<?= $imageBelow ? ' block-imgcards--below' : '' ?>"<?= $carousel ? ' data-carousel' : '' ?>>
        <div class="section-head">
            <?php if ($title !== ''): ?><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
            <div class="section-head__tools">
                <?php if ($allText !== '' && $allUrl !== ''): ?><a class="section-head__all" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($allText, ENT_QUOTES) ?> →</a><?php endif; ?>
                <?php if ($carousel): ?><?php include __DIR__ . '/partials/carousel_nav.php'; ?><?php endif; ?>
            </div>
        </div>
        <?php if ($items === []): ?>
            <p class="block-imgcards__empty"><?= htmlspecialchars(t('Карточки ещё не добавлены.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="imgcards-grid<?= $desktopCarousel ? ' imgcards-grid--carousel' : ($carousel ? ' imgcards-grid--mobile-carousel' : '') ?>"<?= $carousel ? ' data-carousel-track tabindex="0" role="group" aria-label="' . htmlspecialchars(t('Карточки — прокрутка вбок'), ENT_QUOTES) . '"' : '' ?>>
                <?php foreach ($items as $item): ?>
                    <?php $url = trim((string) ($item['url'] ?? '')); $image = trim((string) ($item['image'] ?? '')); ?>
                    <?php if ($url !== ''): ?>
                    <a class="imgcard<?= $imageBelow ? ' imgcard--below' : '' ?>"<?= $carousel ? ' data-carousel-item' : '' ?> href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                    <?php else: ?>
                    <div class="imgcard<?= $imageBelow ? ' imgcard--below' : '' ?>"<?= $carousel ? ' data-carousel-item' : '' ?>>
                    <?php endif; ?>
                        <?php if ($image !== ''): ?>
                            <?= Media::picture($image, (string) ($item['title'] ?? ''), null, null, 'imgcard__media ' . $mediaClasses, true, '(max-width: 700px) 100vw, ' . (int) round(100 / max(1, $columns)) . 'vw') ?>
                        <?php else: ?>
                            <span class="imgcard__media" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php if (!$imageBelow): ?><span class="imgcard__overlay"></span><?php endif; ?>
                        <span class="imgcard__body">
                            <span class="imgcard__title"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></span>
                            <?php if (!empty($item['text'])): ?><span class="imgcard__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span><?php endif; ?>
                        </span>
                    <?php if ($url !== ''): ?></a><?php else: ?></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($variant === 'compact'): ?>
    <div class="block-categories"<?= $explicitSlider ? ' data-carousel' : '' ?>>
        <?php if ($title !== '' || $explicitSlider): ?>
            <div class="block-categories__head">
                <?php if ($title !== ''): ?><h2 class="block-categories__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
                <?php if ($explicitSlider): ?><?php include __DIR__ . '/partials/carousel_nav.php'; ?><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($items === []): ?>
            <p class="block-categories__empty"><?= htmlspecialchars(t('Категории ещё не добавлены.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="cat-grid<?= $explicitSlider ? ' cards-track' : '' ?>"<?= $explicitSlider ? ' data-carousel-track tabindex="0" role="group" aria-label="' . htmlspecialchars(t('Категории — прокрутка вбок'), ENT_QUOTES) . '"' : '' ?>>
                <?php foreach ($items as $index => $item): ?>
                    <?php $url = trim((string) ($item['url'] ?? '')); ?>
                    <?php if ($url !== ''): ?>
                    <a class="cat-tile<?= $index === 0 ? ' is-active' : '' ?>"<?= $explicitSlider ? ' data-carousel-item' : '' ?> href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                    <?php else: ?>
                    <span class="cat-tile<?= $index === 0 ? ' is-active' : '' ?>"<?= $explicitSlider ? ' data-carousel-item' : '' ?>>
                    <?php endif; ?>
                        <?php if (!empty($item['icon_svg'])): ?><span class="cat-tile__icon" aria-hidden="true"><?= Icon::render($item['icon_svg'], 28) ?></span><?php endif; ?>
                        <span class="cat-tile__label"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></span>
                    <?php if ($url !== ''): ?></a><?php else: ?></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="block-cards<?= $cardClasses ?>"<?= $explicitSlider ? ' data-carousel' : '' ?>>
        <?php if ($title !== '' || ($allText !== '' && $allUrl !== '') || $explicitSlider): ?>
            <div class="section-head">
                <?php if ($title !== ''): ?><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
                <div class="section-head__tools">
                    <?php if ($allText !== '' && $allUrl !== ''): ?><a class="section-head__all" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($allText, ENT_QUOTES) ?> →</a><?php endif; ?>
                    <?php if ($explicitSlider): ?><?php include __DIR__ . '/partials/carousel_nav.php'; ?><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($items === []): ?>
            <p class="block-cards__empty"><?= htmlspecialchars(t('Пункты ещё не добавлены.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="cards-grid<?= $explicitSlider ? ' cards-track' : '' ?>"<?= $explicitSlider ? ' data-carousel-track tabindex="0" role="group" aria-label="' . htmlspecialchars(t('Карточки — прокрутка вбок'), ENT_QUOTES) . '"' : '' ?>>
                <?php foreach ($items as $index => $item): ?>
                    <?php $url = trim((string) ($item['url'] ?? '')); $hasIcon = !empty($item['icon_svg']); ?>
                    <?php if ($url !== '' && $hasIcon): ?>
                    <a class="feature-card feature-card--has-icon"<?= $explicitSlider ? ' data-carousel-item' : '' ?> href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                    <?php elseif ($url !== ''): ?>
                    <a class="feature-card"<?= $explicitSlider ? ' data-carousel-item' : '' ?> href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                    <?php elseif ($hasIcon): ?>
                    <article class="feature-card feature-card--has-icon"<?= $explicitSlider ? ' data-carousel-item' : '' ?>>
                    <?php else: ?>
                    <article class="feature-card"<?= $explicitSlider ? ' data-carousel-item' : '' ?>>
                    <?php endif; ?>
                        <div class="feature-card__top">
                            <?php if ($hasIcon): ?>
                                <span class="feature-card__icon" aria-hidden="true"><?= Icon::render($item['icon_svg'], $iconSize) ?></span>
                            <?php else: ?>
                                <span class="feature-card__spacer"></span>
                            <?php endif; ?>
                            <span class="feature-card__num" aria-hidden="true"><?= sprintf('%02d', $index + 1) ?></span>
                        </div>
                        <div class="feature-card__content">
                            <h3 class="feature-card__title"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></h3>
                            <?php if (!empty($item['text'])): ?><p class="feature-card__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></p><?php endif; ?>
                        </div>
                    <?php if ($url !== ''): ?></a><?php else: ?></article><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
