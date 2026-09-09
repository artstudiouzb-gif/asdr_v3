<?php
/** @var array $data */
$title = $data['title'] ?? '';
$description = trim(\App\Core\HtmlSanitizer::sanitizeText((string) ($data['description'] ?? '')));
$items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
$allowedStatuses = ['done', 'active', 'planned'];
$hasExplicitStatuses = false;
foreach ($items as $item) {
    if (is_array($item) && in_array($item['status'] ?? '', $allowedStatuses, true)) {
        $hasExplicitStatuses = true;
        break;
    }
}
$statuses = [];
$lastItemIndex = count($items) - 1;
foreach ($items as $index => $item) {
    $savedStatus = is_array($item) ? ($item['status'] ?? '') : '';
    if (in_array($savedStatus, $allowedStatuses, true)) {
        $statuses[] = (string) $savedStatus;
    } elseif (!$hasExplicitStatuses) {
        // Старые записи не содержали статуса: предыдущие события считаются
        // завершёнными, последнее — текущим.
        $statuses[] = $index === $lastItemIndex ? 'active' : 'done';
    } else {
        $statuses[] = 'planned';
    }
}
$btnText = trim((string) ($data['button_text'] ?? ''));
$btnUrl = trim((string) ($data['button_url'] ?? ''));
$ctaTitle = trim((string) ($data['cta_title'] ?? ''));
$hasCta = $ctaTitle !== '';
$ctaImage = trim((string) ($data['cta_image'] ?? ''));
$ctaBtnText = trim((string) ($data['cta_button_text'] ?? ''));
$ctaBtnUrl = trim((string) ($data['cta_button_url'] ?? ''));
if ($ctaImage !== '' && !\App\Core\UrlGuard::isSafeMedia($ctaImage)) {
    $ctaImage = '';
}
$ctaImageCss = str_replace(["\\", "'"], ["\\\\", "\\'"], $ctaImage);
$templateCss = $ctaImageCss !== ''
    ? '#block-' . $blockId . " .timeline-cta{--timeline-cta-image:url('" . $ctaImageCss . "')}"
    : '';
?>
<div class="block-timeline<?= $hasCta ? ' block-timeline--with-cta' : '' ?>">
    <?php if ($title !== '' || $description !== ''): ?>
        <div class="block-timeline__head">
            <?php if ($title !== ''): ?><h2 class="block-timeline__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
            <?php if ($description !== ''): ?><div class="block-timeline__description rich-content"><?= $description ?></div><?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="block-timeline__layout">
        <div class="timeline-card">
            <?php if (empty($items)): ?>
                <p class="block-timeline__empty"><?= htmlspecialchars(t('События ещё не добавлены.'), ENT_QUOTES) ?></p>
            <?php else: ?>
                <ol class="timeline-list">
                    <?php foreach ($items as $index => $item): ?>
                        <?php
                        $status = $statuses[$index];
                        $nextStatus = $statuses[$index + 1] ?? '';
                        ?>
                        <li class="timeline-item timeline-item--<?= $status ?><?= $nextStatus !== '' ? ' timeline-item--next-' . $nextStatus : '' ?>">
                            <span class="timeline-item__year"><?= htmlspecialchars((string) ($item['year'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="timeline-item__text"><?= htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($btnText !== '' && $btnUrl !== ''): ?>
                <a class="timeline-card__button" href="<?= htmlspecialchars($btnUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($btnText, ENT_QUOTES) ?></a>
            <?php endif; ?>
        </div>
        <?php if ($hasCta): ?>
            <div class="timeline-cta">
                <span class="timeline-cta__overlay"></span>
                <div class="timeline-cta__body">
                    <h3 class="timeline-cta__title"><?= htmlspecialchars($ctaTitle, ENT_QUOTES) ?></h3>
                    <?php if (!empty($data['cta_text'])): ?><p class="timeline-cta__text"><?= htmlspecialchars((string) $data['cta_text'], ENT_QUOTES) ?></p><?php endif; ?>
                    <?php if ($ctaBtnText !== '' && $ctaBtnUrl !== ''): ?>
                        <a class="timeline-cta__button" href="<?= htmlspecialchars($ctaBtnUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($ctaBtnText, ENT_QUOTES) ?> →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
