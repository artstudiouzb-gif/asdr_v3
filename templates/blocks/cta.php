<?php

use App\Core\Icon;
use App\Core\MediaPosition;
use App\Core\UrlGuard;

/** @var array $data */
$title = trim((string) ($data['title'] ?? ''));
$text = trim((string) ($data['text'] ?? ''));
$buttonText = trim((string) ($data['button_text'] ?? ''));
$buttonUrl = trim((string) ($data['button_url'] ?? ''));
$icon = trim((string) ($data['icon_svg'] ?? ''));
$image = trim((string) ($data['image'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$mediaClasses = MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);

if ($buttonUrl !== '' && !UrlGuard::isSafeLink($buttonUrl)) {
    $buttonUrl = '';
}
if ($image !== '' && !UrlGuard::isSafeMedia($image)) {
    $image = '';
}

$color = static function (string $key, string $variable) use ($data): string {
    $value = (string) ($data[$key] ?? '');

    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $variable . ':' . $value . ';' : '';
};
$scope = '#block-' . (int) $blockId;
$templateCss = '';

if ($variant === 'band') {
    $vars = $color('bg_color', '--ctaband-bg') . $color('text_color', '--ctaband-text') . $color('button_color', '--ctaband-btn');
    $templateCss = $vars !== '' ? $scope . ' .block-ctaband{' . $vars . '}' : '';
} elseif (str_starts_with($variant, 'media-')) {
    $vars = $color('bg_color', '--banner-bg') . $color('text_color', '--banner-text') . $color('button_color', '--banner-btn');
    $safeImage = str_replace(["\\", "'"], ["\\\\", "\\'"], $image);
    if ($variant === 'media-light') {
        if ($vars !== '') {
            $templateCss .= $scope . ' .block-banner{' . $vars . '}';
        }
        if ($safeImage !== '') {
            $templateCss .= "\n" . $scope . " .block-banner__photo{--block-banner-image:url('" . $safeImage . "')}";
        }
    } else {
        $background = $safeImage !== ''
            ? "--block-banner-image:linear-gradient(rgba(15,23,42,.58),rgba(15,23,42,.58)),url('" . $safeImage . "');"
            : '';
        if ($background !== '' || $vars !== '') {
            $templateCss = $scope . ' .block-banner{' . $background . $vars . '}';
        }
    }
} else {
    $vars = $color('bg_color', '--cta-bg') . $color('text_color', '--cta-text') . $color('button_color', '--cta-btn');
    $templateCss = $vars !== '' ? $scope . ' .block-cta{' . $vars . '}' : '';
}
?>
<?php if ($variant === 'band'): ?>
    <div class="block-ctaband">
        <div class="ctaband__lead">
            <span class="ctaband__icon" aria-hidden="true"><?= Icon::render($icon !== '' ? $icon : 'mail', 40, '', 1.5) ?></span>
            <span class="ctaband__body">
                <?php if ($title !== ''): ?><span class="ctaband__title"><?= \App\Core\TitleMarkup::html($title) ?></span><?php endif; ?>
                <?php if ($text !== ''): ?><span class="ctaband__text"><?= htmlspecialchars($text, ENT_QUOTES) ?></span><?php endif; ?>
            </span>
        </div>
        <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
            <a class="ctaband__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?> →</a>
        <?php endif; ?>
    </div>
<?php elseif (str_starts_with($variant, 'media-')): ?>
    <div class="block-banner<?= $variant === 'media-light' ? ' block-banner--light' : ($image !== '' ? ' block-banner--image' : '') ?> <?= $mediaClasses ?>">
        <div class="block-banner__inner">
            <?php if ($title !== ''): ?><h2 class="block-banner__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
            <?php if ($text !== ''): ?><p class="block-banner__text"><?= htmlspecialchars($text, ENT_QUOTES) ?></p><?php endif; ?>
            <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
                <a class="block-banner__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
            <?php endif; ?>
        </div>
        <?php if ($variant === 'media-light' && $image !== ''): ?><span class="block-banner__photo <?= $mediaClasses ?>"></span><?php endif; ?>
    </div>
<?php else: ?>
    <div class="block-cta">
        <?php if ($title !== ''): ?><h2><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><p><?= htmlspecialchars($text, ENT_QUOTES) ?></p><?php endif; ?>
        <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
            <a class="block-cta__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>
