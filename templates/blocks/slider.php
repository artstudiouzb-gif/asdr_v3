<?php
/** @var array $data */
/** @var int $blockId */
$slides = is_array($data['slides'] ?? null) ? $data['slides'] : [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$ratio = (string) $data['ratio'];
// Автопрокрутка: секунды между слайдами, 0 — выключено. Логику берёт на себя
// общий скрипт слайдера (он же обслуживает обложку), ему нужен только атрибут.
$autoplay = (int) $data['autoplay'];
$head = \App\Core\SectionHead::render(['title' => (string) ($data['title'] ?? '')]);
?>
<div class="block-slider-wrap">
    <?= $head ?>
    <div class="block-slider block-slider--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?>" data-block-id="<?= (int) $blockId ?>"<?= $autoplay > 0 ? ' data-autoplay="' . $autoplay . '"' : '' ?> role="region" aria-roledescription="<?= htmlspecialchars(t('Карусель'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Слайдер изображений'), ENT_QUOTES) ?>" tabindex="0">
        <div class="block-slider__track">
            <?php foreach ($slides as $index => $slide): ?>
                <?php
                $url = trim((string) ($slide['url'] ?? ''));
                if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                    $url = '';
                }
                ?>
                <div class="block-slider__slide<?= $index === 0 ? ' is-active' : '' ?>" role="group" aria-roledescription="<?= htmlspecialchars(t('Слайд'), ENT_QUOTES) ?>" aria-label="<?= ($index + 1) . ' ' . htmlspecialchars(t('из'), ENT_QUOTES) . ' ' . count($slides) ?>" aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                    <?php // Неактивный слайд скрыт display:none — из порядка обхода он выпадает сам, tabindex не нужен. ?>
                    <?php if ($url !== ''): ?><a class="block-slider__link" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"><?php endif; ?>
                    <?php if (!empty($slide['image'])): ?>
                        <?= \App\Core\Media::picture((string) $slide['image'], (string) ($slide['alt'] ?? ''), null, null, '', $index !== 0, '100vw') ?>
                    <?php endif; ?>
                    <?php if (!empty($slide['caption'])): ?>
                        <div class="block-slider__caption"><?= htmlspecialchars($slide['caption'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($url !== ''): ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
            <div class="block-slider__nav">
                <button type="button" class="block-slider__prev" aria-label="<?= htmlspecialchars(t('Предыдущий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-left', 22) ?></button>
                <button type="button" class="block-slider__next" aria-label="<?= htmlspecialchars(t('Следующий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-right', 22) ?></button>
            </div>
            <div class="block-slider__dots" role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>">
                <?php foreach ($slides as $index => $_slide): ?>
                    <button type="button" class="block-slider__dot<?= $index === 0 ? ' is-active' : '' ?>" data-slide-index="<?= $index ?>" aria-label="<?= htmlspecialchars(t('Перейти к слайду'), ENT_QUOTES) . ' ' . ($index + 1) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
                <?php endforeach; ?>
            </div>
            <span class="visually-hidden" data-slider-status aria-live="polite"></span>
        <?php endif; ?>
    </div>
</div>
