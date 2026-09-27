<?php

use App\Core\AccentContrast;
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

$button2Text = trim((string) ($data['button2_text'] ?? ''));
$button2Url = trim((string) ($data['button2_url'] ?? ''));

if ($buttonUrl !== '' && !UrlGuard::isSafeLink($buttonUrl)) {
    $buttonUrl = '';
}
if ($button2Url !== '' && !UrlGuard::isSafeLink($button2Url)) {
    $button2Url = '';
}
if ($image !== '' && !UrlGuard::isSafeMedia($image)) {
    $image = '';
}

$hex = static function (string $key) use ($data): string {
    $value = trim((string) ($data[$key] ?? ''));

    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : '';
};
$bgColor = $hex('bg_color');
$textColor = $hex('text_color');
$btnColor = $hex('button_color');

// Свой фон без своего цвета текста — цвет считается по контрасту, тем же
// помощником, что у акцентной цитаты: белый заголовок на светлой заливке не
// читается вовсе, а узнать об этом редактору было неоткуда.
if ($textColor === '' && $bgColor !== '') {
    $textColor = strtolower(AccentContrast::onFill($bgColor));
}
// Подпись кнопки — так же: на светлой кнопке белый текст пропадает.
$btnTextColor = $btnColor !== '' ? strtolower(AccentContrast::onFill($btnColor)) : '';

/**
 * Свои цвета блока: объявления переменных для scoped CSS и классы-признаки
 * на самом элементе.
 *
 * Класс обязателен: правила темы включаются именно по нему. Прежде они ловили
 * инлайн-стиль (`.block-cta[style*="--cta-bg"]`), а инлайн-стилей в блоках
 * нет — их запрещают тесты, и переменные давно уезжают в scoped CSS. То есть
 * ни фон, ни цвет текста, ни цвет кнопки не действовали ни в одном варианте
 * блока, хотя поля в форме сохранялись.
 *
 * @return array{0: string, 1: string} объявления и список классов
 */
$custom = static function (string $prefix, string $block) use ($bgColor, $textColor, $btnColor, $btnTextColor): array {
    $vars = '';
    $classes = [];
    if ($bgColor !== '') {
        $vars .= '--' . $prefix . '-bg:' . $bgColor . ';';
        $classes[] = $block . '--custom-bg';
    }
    if ($textColor !== '') {
        $vars .= '--' . $prefix . '-text:' . $textColor . ';';
        $classes[] = $block . '--custom-text';
    }
    if ($btnColor !== '') {
        $vars .= '--' . $prefix . '-btn:' . $btnColor . ';--' . $prefix . '-btn-fg:' . $btnTextColor . ';';
        $classes[] = $block . '--custom-btn';
    }

    return [$vars, implode(' ', $classes)];
};
$scope = '#block-' . (int) $blockId;
$templateCss = '';

if ($variant === 'band') {
    [$vars, $customClasses] = $custom('ctaband', 'block-ctaband');
    $templateCss = $vars !== '' ? $scope . ' .block-ctaband{' . $vars . '}' : '';
} elseif (str_starts_with($variant, 'media-')) {
    [$vars, $customClasses] = $custom('banner', 'block-banner');
    $safeImage = str_replace(["\\", "'"], ["\\\\", "\\'"], $image);
    if ($variant === 'media-light') {
        // Фото — настоящая картинка, а не фон: у фона не бывает уменьшенных
        // копий и ленивой загрузки. Прежде оно и не показывалось вовсе —
        // сокращённый `background` в теме обнулял `background-image`.
        if ($vars !== '') {
            $templateCss .= $scope . ' .block-banner{' . $vars . '}';
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
    [$vars, $customClasses] = $custom('cta', 'block-cta');
    $templateCss = $vars !== '' ? $scope . ' .block-cta{' . $vars . '}' : '';
}
$customClasses = $customClasses !== '' ? ' ' . $customClasses : '';
?>
<?php if ($variant === 'band'): ?>
    <div class="block-ctaband<?= $customClasses ?>">
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
<?php elseif ($variant === 'media-light'): ?>
    <?php
    $splitClasses = 'block-banner block-banner--light block-banner--scheme-' . (string) $data['split_scheme']
        . ' block-banner--photo-' . (string) $data['image_side'] . ($image !== '' ? ' block-banner--has-photo' : '');
    ?>
    <div class="<?= $splitClasses ?><?= $customClasses ?>">
        <div class="block-banner__inner">
            <?php if ($title !== ''): ?><h2 class="block-banner__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
            <?php if ($text !== ''): ?><p class="block-banner__text"><?= htmlspecialchars($text, ENT_QUOTES) ?></p><?php endif; ?>
            <?php if (($buttonText !== '' && $buttonUrl !== '') || ($button2Text !== '' && $button2Url !== '')): ?>
                <div class="block-banner__actions">
                    <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
                        <a class="block-banner__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
                    <?php endif; ?>
                    <?php if ($button2Text !== '' && $button2Url !== ''): ?>
                        <a class="block-banner__button block-banner__button--ghost" href="<?= htmlspecialchars($button2Url, ENT_QUOTES) ?>"><?= htmlspecialchars($button2Text, ENT_QUOTES) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($image !== ''): ?>
            <div class="block-banner__media">
                <?= \App\Core\Media::picture($image, '', null, null, 'block-banner__img ' . $mediaClasses, true, '(max-width: 900px) 100vw, 50vw') ?>
            </div>
        <?php endif; ?>
    </div>
<?php elseif (str_starts_with($variant, 'media-')): ?>
    <div class="block-banner<?= $image !== '' ? ' block-banner--image' : '' ?><?= $customClasses ?> <?= $mediaClasses ?>">
        <div class="block-banner__inner">
            <?php if ($title !== ''): ?><h2 class="block-banner__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
            <?php if ($text !== ''): ?><p class="block-banner__text"><?= htmlspecialchars($text, ENT_QUOTES) ?></p><?php endif; ?>
            <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
                <a class="block-banner__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="block-cta<?= $customClasses ?>">
        <?php if ($title !== ''): ?><h2><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><p><?= htmlspecialchars($text, ENT_QUOTES) ?></p><?php endif; ?>
        <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
            <a class="block-cta__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>
