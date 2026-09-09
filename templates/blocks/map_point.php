<?php
/** @var array $data */
$title = trim((string) ($data['title'] ?? ''));
$image = trim((string) ($data['image'] ?? ''));
$embedUrl = \App\Core\MapEmbedUrl::normalize($data['embed_url'] ?? '');
$cardTitle = trim((string) ($data['card_title'] ?? ''));
$address = trim((string) ($data['address'] ?? ''));
$btnText = trim((string) ($data['button_text'] ?? ''));
$btnUrl = trim((string) ($data['button_url'] ?? ''));
// Значение проверено схемой полей (BlockFieldSchema) — читаем как есть.
$loadMode = (string) $data['load_mode'];
$copyEnabled = !array_key_exists('copy_enabled', $data) || !empty($data['copy_enabled']);

if ($image !== '' && !\App\Core\UrlGuard::isSafeMedia($image)) {
    $image = '';
}

$imageCss = str_replace(["\\", "'"], ["\\\\", "\\'"], $image);
$templateCss = $imageCss !== ''
    ? '#block-' . $blockId . " .block-map__image{--block-map-image:url('" . $imageCss . "')}"
    : '';
?>
<div class="block-map">
    <?php if ($title !== ''): ?><h2 class="block-map__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
    <div class="block-map__canvas">
        <?php if ($embedUrl !== '' && $loadMode === 'immediate'): ?>
            <iframe class="block-map__frame" src="<?= htmlspecialchars($embedUrl, ENT_QUOTES) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= htmlspecialchars($title !== '' ? \App\Core\TitleMarkup::plain($title) : t('Карта'), ENT_QUOTES) ?>"></iframe>
        <?php elseif ($embedUrl !== ''): ?>
            <div class="block-map__embed" data-map-embed data-map-src="<?= htmlspecialchars($embedUrl, ENT_QUOTES) ?>">
                <span class="block-map__image<?= $image === '' ? ' block-map__image--empty' : '' ?>"></span>
                <button type="button" class="block-map__load" data-map-load>
                    <?= \App\Core\Icon::render('map-2', 24, 'block-map__load-icon', 1.8) ?>
                    <span><?= htmlspecialchars(t('Показать интерактивную карту'), ENT_QUOTES) ?></span>
                </button>
            </div>
        <?php elseif ($image !== ''): ?>
            <span class="block-map__image"></span>
        <?php else: ?>
            <span class="block-map__image block-map__image--empty"></span>
        <?php endif; ?>
        <span class="block-map__pin" aria-hidden="true">
            <?= \App\Core\Icon::render('map-pin', 52, 'block-map__pin-svg', 1.8) ?>
            <span class="block-map__pin-ripple"></span>
        </span>
        <?php if ($cardTitle !== '' || $address !== ''): ?>
            <div class="block-map__card">
                <?php if ($cardTitle !== ''): ?><span class="block-map__card-title"><?= htmlspecialchars($cardTitle, ENT_QUOTES) ?></span><?php endif; ?>
                <?php if ($address !== ''): ?><span class="block-map__card-address"><?= nl2br(htmlspecialchars($address, ENT_QUOTES)) ?></span><?php endif; ?>
                <span class="block-map__card-actions">
                    <?php if ($copyEnabled && $address !== ''): ?>
                        <button type="button" class="block-map__card-link" data-copy-text="<?= htmlspecialchars($address, ENT_QUOTES) ?>" data-copy-success="<?= htmlspecialchars(t('Адрес скопирован'), ENT_QUOTES) ?>">
                            <?= \App\Core\Icon::render('copy', 16, '', 1.8) ?>
                            <span><?= htmlspecialchars(t('Скопировать адрес'), ENT_QUOTES) ?></span>
                        </button>
                    <?php endif; ?>
                    <?php if ($btnText !== '' && $btnUrl !== ''): ?>
                        <a class="block-map__card-link" href="<?= htmlspecialchars($btnUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">
                            <?= \App\Core\Icon::render('route', 16, '', 1.8) ?>
                            <span><?= htmlspecialchars($btnText, ENT_QUOTES) ?></span>
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
