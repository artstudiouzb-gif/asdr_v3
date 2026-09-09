<?php

use App\Core\UrlGuard;
use App\Core\Video;
use App\Core\Media;
use App\Core\AppUrl;
use App\Core\MediaPosition;
use App\Core\Hero\HeroRenderer;

/** @var array $data */
/** @var int $blockId */

// Обложка выбрана в блоке (тип контента «Обложки»): содержимое и настройки
// приходят из отдельной записи, собственные поля блока не участвуют — иначе
// один и тот же текст пришлось бы держать в двух местах. Данные подставляет
// BlockRenderer::enrichData().
if (!empty($data['_hero']) && !empty($data['_hero_slides'])) {
    $rendered = HeroRenderer::render(
        (array) $data['_hero'],
        (array) $data['_hero_slides'],
        (array) ($data['_hero_settings'] ?? []),
        $blockId,
        (string) ($data['_heading_tag'] ?? 'h1')
    );
    $templateCss = $rendered['css'];
    echo $rendered['html'];

    return;
}

$title = $data['title'] ?? '';
$eyebrow = trim((string) ($data['eyebrow'] ?? ''));
$subtitle = $data['subtitle'] ?? '';
$image = trim((string) ($data['image'] ?? ''));
$textAlignY = (string) ($data['text_align_y'] ?? 'center');
if (!in_array($textAlignY, ['top', 'center', 'bottom'], true)) {
    $textAlignY = 'center';
}
// Картинка поверх фона (эмблема, логотип программы, иллюстрация): у неё своя
// позиция относительно текста и свой размер. К слайд-шоу не применяется —
// там у каждого слайда своё медиа.
$artImage = trim((string) ($data['art_image'] ?? ''));
$artPosition = (string) ($data['art_position'] ?? 'above');
if (!in_array($artPosition, ['above', 'left', 'right'], true)) {
    $artPosition = 'above';
}
$artSize = (string) ($data['art_size'] ?? 'medium');
if (!in_array($artSize, ['small', 'medium', 'large'], true)) {
    $artSize = 'medium';
}
// Явная высота обязательна: SVG без пиксельных размеров схлопывается, ширину
// браузер считает из viewBox.
$artHeights = ['small' => 64, 'medium' => 120, 'large' => 200];
$artAlt = trim((string) ($data['art_alt'] ?? ''));
// Одна сборка на обложку и на слайды: у слайда своя картинка, но правила те же.
$heroArt = static function (string $image, string $alt, string $size) use ($artHeights): string {
    if ($image === '' || !UrlGuard::isSafeMedia($image)) {
        return '';
    }

    // Без описания картинка декоративная и скрыта от скринридера; с описанием
    // это содержимое, и прятать его нельзя.
    return '<span class="block-hero__art"' . ($alt === '' ? ' aria-hidden="true"' : '')
        . '><img class="block-hero__art-img" src="' . htmlspecialchars($image, ENT_QUOTES)
        . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" loading="lazy" decoding="async" height="'
        . ($artHeights[$size] ?? $artHeights['medium']) . '"></span>';
};
$artHtml = $heroArt($artImage, $artAlt, $artSize);
$mediaPositionClasses = MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);

// Тип фона: none | image | video | youtube.
$bgType = (string) ($data['bg_type'] ?? 'none');
$bgType = in_array($bgType, ['none', 'image', 'video', 'youtube'], true) ? $bgType : 'none';
$videoFile = trim((string) ($data['video_url'] ?? ''));
$youtubeId = Video::youtubeId((string) ($data['youtube_url'] ?? ''));

$hasMedia = ($bgType === 'image' && $image !== '')
    || ($bgType === 'video' && $videoFile !== '')
    || ($bgType === 'youtube' && $youtubeId !== null);

// Overlay (полупрозрачная заливка поверх медиа) и подложка под текстом.
$hex2rgb = static function (string $hex): string {
    $hex = ltrim($hex, '#');
    return (int) hexdec(substr($hex, 0, 2)) . ',' . (int) hexdec(substr($hex, 2, 2)) . ',' . (int) hexdec(substr($hex, 4, 2));
};
$ovColor = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['overlay_color'] ?? '')) ? $data['overlay_color'] : '#0b1a30';
$overlayEnabled = !empty($data['overlay_enabled']);
$ovOpacity = max(0, min(100, (int) ($data['overlay_opacity'] ?? 35))) / 100;
$panelOn = !empty($data['panel_enabled']);
$panelColor = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['panel_color'] ?? '')) ? $data['panel_color'] : '#0b1a30';
$panelOpacity = max(0, min(100, (int) ($data['panel_opacity'] ?? 40))) / 100;
$textPos = in_array($data['text_position'] ?? 'left', ['left', 'center', 'right'], true) ? $data['text_position'] : 'left';
$heroText = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['text_color'] ?? '')) ? $data['text_color'] : '';
$heroBtn = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['button_color'] ?? '')) ? $data['button_color'] : '';
$rawOverlayDirection = (string) ($data['overlay_direction'] ?? 'auto');
$overlayMode = (string) ($data['overlay_mode'] ?? 'gradient');
$overlayMode = in_array($overlayMode, ['solid', 'gradient'], true) ? $overlayMode : 'gradient';
$overlayDirection = $rawOverlayDirection;
$overlayAngles = [
    'to_right' => '90deg',
    'to_left' => '270deg',
    'to_bottom' => '180deg',
    'to_top' => '0deg',
    'to_bottom_right' => '135deg',
    'to_bottom_left' => '225deg',
    'to_top_right' => '45deg',
    'to_top_left' => '315deg',
];
$overlaySolid = $overlayMode === 'solid';
if ($overlayDirection === 'auto' || !isset($overlayAngles[$overlayDirection])) {
    $overlayAngle = $textPos === 'right' ? '270deg' : ($textPos === 'center' ? '0deg' : '90deg');
} else {
    $overlayAngle = $overlayAngles[$overlayDirection];
}

// Инлайн-стиль контейнера текста: подложка + переопределения цветов через CSS-переменные.
$textStyle = ($panelOn ? '--hero-panel-bg:rgba(' . $hex2rgb($panelColor) . ', ' . $panelOpacity . ');' : '')
    . ($heroText !== '' ? '--hero-text:' . $heroText . ';' : '')
    . ($heroBtn !== '' ? '--hero-btn:' . $heroBtn . ';' : '');

// Свой цвет фона под текстом (для hero без фото/видео): полупрозрачный
// градиент выбранного цвета, не зависящий от светлой/тёмной темы. Отдаётся
// на корень hero — под медиа он не виден (там работает overlay), а на hero
// без медиа заменяет фон темы, который иначе менялся при переключении режима.
$heroBg = preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['bg_color'] ?? '')) ? $data['bg_color'] : '';
$heroRootStyle = '';
if ($heroBg !== '') {
    $rgb = $hex2rgb($heroBg);
    // Направление градиента — от стороны с текстом к прозрачному краю.
    $dir = $textPos === 'right' ? '270deg' : ($textPos === 'center' ? '180deg' : '90deg');
    $heroRootStyle = '--hero-background:linear-gradient(' . $dir . ', rgba(' . $rgb . ',.96) 0%, rgba(' . $rgb . ',.92) 42%, rgba(' . $rgb . ',.55) 72%, rgba(' . $rgb . ',.12) 100%)'
        // Инлайновый background перебивает navy темы, и сквозь полупрозрачные
        // участки градиента просвечивал белый фон страницы — hero выглядел
        // светлее задуманного. Под медиа подкладываем сплошной navy-слой;
        // hero без медиа не трогаем (там градиент поверх фона темы задуман).
        . ($hasMedia ? ', linear-gradient(var(--gov-navy, #173a63), var(--gov-navy, #173a63))' : '')
        . ';';
}

$btnText = trim((string) ($data['button_text'] ?? ''));
$btnUrl = trim((string) ($data['button_url'] ?? ''));
$btn2Text = trim((string) ($data['button2_text'] ?? ''));
$btn2Url = trim((string) ($data['button2_url'] ?? ''));
/**
 * Иконка кнопки: своя картинка (SVG из медиабиблиотеки) важнее ключа Tabler.
 * Без иконки возвращается пустая строка — разметка кнопки не меняется.
 */
$heroButtonIcon = static function (string $iconName, string $iconImage): string {
    $iconImage = trim($iconImage);
    if ($iconImage !== '' && UrlGuard::isSafeMedia($iconImage)) {
        return '<img class="block-hero__button-icon" src="' . htmlspecialchars($iconImage, ENT_QUOTES)
            . '" alt="" aria-hidden="true" width="46" height="46">';
    }
    $iconName = \App\Core\Icon::cleanName($iconName);

    return $iconName !== ''
        ? '<span class="block-hero__button-icon" aria-hidden="true">' . \App\Core\Icon::render($iconName, 46, '', 2) . '</span>'
        : '';
};
$btnIcon = $heroButtonIcon((string) ($data['button_icon'] ?? ''), (string) ($data['button_icon_image'] ?? ''));
// Левый отступ кнопки укорочен под зону иконки (46px). Без иконки его надо
// вернуть, иначе текст прилипает к левому краю.
$heroButtonClass = static fn (string $icon): string => $icon !== '' ? ' block-hero__button--with-icon' : '';
$btn2Icon = $heroButtonIcon((string) ($data['button2_icon'] ?? ''), (string) ($data['button2_icon_image'] ?? ''));

$vBtnText = trim((string) ($data['video_button_text'] ?? ''));
$vBtnUrl = trim((string) ($data['video_button_url'] ?? ''));

// Своя ширина текстовой колонки: px (200–2000) или %/vw (10–100).
// Пусто/невалидно — ширина темы (560/620px). Отдаётся CSS-переменной.
$textWidth = (string) ($data['text_width'] ?? '');
if ($textWidth !== '' && preg_match('/^(\d+(?:\.\d+)?)(px|%|vw)$/', $textWidth, $twParts)) {
    $twValue = (float) $twParts[1];
    $twLimits = $twParts[2] === 'px' ? [200.0, 2000.0] : [10.0, 100.0];
    $twValue = max($twLimits[0], min($twLimits[1], $twValue));
    $twNumber = rtrim(rtrim(number_format($twValue, 1, '.', ''), '0'), '.');
    $heroRootStyle .= '--hero-text-width:' . $twNumber . $twParts[2] . ';';
}

$heroWidth = ($data['width'] ?? 'full') === 'standard' ? 'standard' : 'full';
// Телефон: своя высота и свой кадр. Пусто — всё как на десктопе.
$mobileHeightMode = (string) ($data['height_mobile'] ?? '');
if (!in_array($mobileHeightMode, ['regular', 'full', 'custom'], true)) {
    $mobileHeightMode = '';
}
$mobileImage = trim((string) ($data['image_mobile'] ?? ''));
if ($mobileImage !== '' && !UrlGuard::isSafeMedia($mobileImage)) {
    $mobileImage = '';
}
$mobileVideo = (string) ($data['video_mobile'] ?? 'poster');
$mobileVideo = $mobileVideo === 'play' ? 'play' : 'poster';
$heroHeight = in_array($data['height'] ?? 'regular', ['regular', 'full', 'custom'], true) ? $data['height'] : 'regular';
$customHeight = (string) ($data['custom_height'] ?? '720px');
if ($heroHeight === 'custom' && preg_match('/^(\d+(?:\.\d+)?)(px|vh|dvh|rem)$/', $customHeight, $heightParts)) {
    $heightValue = (float) $heightParts[1];
    $heightUnit = $heightParts[2];
    $heightLimits = $heightUnit === 'px' ? [160.0, 2000.0]
        : ($heightUnit === 'rem' ? [10.0, 120.0] : [20.0, 150.0]);
    $heightValue = max($heightLimits[0], min($heightLimits[1], $heightValue));
    $heightNumber = rtrim(rtrim(number_format($heightValue, 1, '.', ''), '0'), '.');
    $heroRootStyle .= '--hero-custom-height:' . $heightNumber . $heightUnit . ';';
}
// --- Слайды обложки ---
// Оформление (высота, ширина, затемнение, панель, цвета) общее для всех
// слайдов; у слайда своё содержимое, картинка, кнопки и окно показа.
$slides = is_array($data['slides'] ?? null) ? $data['slides'] : [];
$slides = array_values(array_filter($slides, static function ($slide): bool {
    if (!is_array($slide)) {
        return false;
    }
    // Границу расписания сообщаем до проверки: слайд, который ещё не начался,
    // тоже обязан разморозить кэш страницы к своему старту.
    \App\Core\BlockRenderer::noteBoundary(\App\Core\BlockVisibility::boundary($slide));

    return \App\Core\BlockVisibility::isVisible($slide);
}));
$isSlider = $slides !== [];
$autoplay = max(0, (int) ($data['autoplay'] ?? 0));

$scrimStyle = '--hero-scrim-rgb:' . $hex2rgb($ovColor)
    . ';--hero-scrim-a:' . $ovOpacity
    . ';--hero-scrim-direction:' . $overlayAngle . ';';
// Мобильная высота — отдельным медиазапросом: класс общий, значение своё.
$mobileHeightCss = '';
if ($mobileHeightMode === 'custom') {
    $mobileCustom = (string) ($data['custom_height_mobile'] ?? '');
    if (preg_match('/^(\d+(?:\.\d+)?)(px|vh|dvh|rem)$/', $mobileCustom) === 1) {
        $mobileHeightCss = '@media (max-width:720px){#block-' . $blockId
            . ' .block-hero{min-height:' . $mobileCustom . '}}';
    }
} elseif ($mobileHeightMode === 'full') {
    $mobileHeightCss = '@media (max-width:720px){#block-' . $blockId
        . ' .block-hero{min-height:100dvh}}';
} elseif ($mobileHeightMode === 'regular') {
    $mobileHeightCss = '@media (max-width:720px){#block-' . $blockId
        . ' .block-hero{min-height:clamp(360px,70vw,460px)}}';
}

$templateCss = ($heroRootStyle !== '' ? '#block-' . $blockId . ' .block-hero{' . $heroRootStyle . '}' : '')
    . ($mobileHeightCss !== '' ? "\n" . $mobileHeightCss : '')
    . (($hasMedia || $isSlider) && $overlayEnabled ? "\n#block-" . $blockId . ' .block-hero__scrim{' . $scrimStyle . '}' : '')
    . ($textStyle !== '' ? "\n#block-" . $blockId . ' .block-hero__text{' . $textStyle . '}' : '');

$youtubeEmbed = static function (string $id): string {
    $youtubeParams = [
        'autoplay' => '1',
        'mute' => '1',
        'loop' => '1',
        'playlist' => $id,
        'controls' => '0',
        'playsinline' => '1',
        'disablekb' => '1',
        'fs' => '0',
        'enablejsapi' => '1',
    ];
    $youtubeOrigin = AppUrl::base();
    if ($youtubeOrigin !== '') {
        $youtubeParams['origin'] = $youtubeOrigin;
    }

    return 'https://www.youtube-nocookie.com/embed/' . $id
        . '?' . http_build_query($youtubeParams, '', '&', PHP_QUERY_RFC3986);
};
/**
 * Фон обложки: фото, mp4 или YouTube. Разметка одна и та же для одиночной
 * обложки и для слайда карусели — различаются только источники и приоритет
 * загрузки ($lazy у слайдов, кроме первого).
 */
$heroMedia = static function (
    string $type,
    string $image,
    string $videoFile,
    ?string $ytId,
    string $posClasses,
    bool $lazy,
    string $mobileImage = '',
    bool $videoPosterOnMobile = false
) use ($youtubeEmbed): string {
    if ($type === 'video' && $videoFile !== '') {
        // Отложенное видео стартует не по атрибуту autoplay, а из frontend.js,
        // когда слайд действительно показан: иначе карусель тянула бы все
        // ролики сразу.
        return '<video class="block-hero__video ' . $posClasses . '" data-hero-background-video'
            . ($lazy ? ' preload="none"' : ' autoplay preload="metadata"')
            . ' muted loop playsinline webkit-playsinline'
            . ' disablepictureinpicture disableremoteplayback'
            . ' controlslist="nodownload nofullscreen noremoteplayback noplaybackrate"'
            . ' tabindex="-1"' . ($image !== '' ? ' poster="' . htmlspecialchars($image, ENT_QUOTES) . '"' : '')
            // На телефоне ролик можно не проигрывать: остаётся постер, а сам
            // файл не скачивается — фон-видео на мобильном трафике стоит
            // дороже, чем даёт. Решение принимает frontend.js по ширине окна.
            . ($videoPosterOnMobile ? ' data-hero-video-mobile="poster"' : '')
            . ' aria-hidden="true"><source src="' . htmlspecialchars($videoFile, ENT_QUOTES) . '" type="video/mp4"></video>';
    }

    if ($type === 'youtube' && $ytId !== null) {
        $poster = '';
        if ($image !== '' && UrlGuard::isSafeMedia($image)) {
            $poster = '<img class="block-hero__youtube-poster ' . $posClasses . '"'
                . ' src="' . htmlspecialchars($image, ENT_QUOTES) . '" alt=""'
                . ($lazy ? ' loading="lazy" decoding="async"' : ' loading="eager" decoding="async" fetchpriority="high"')
                . ' aria-hidden="true">';
        }

        return $poster . '<div class="block-hero__yt" data-hero-youtube-container aria-hidden="true">'
            . '<iframe data-hero-youtube-background data-src="' . htmlspecialchars($youtubeEmbed($ytId), ENT_QUOTES) . '"'
            . ' tabindex="-1" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"'
            . ' allow="autoplay; encrypted-media" aria-hidden="true"></iframe></div>';
    }

    if ($type === 'image' && $image !== '') {
        return Media::picture(
            $image,
            '',
            null,
            null,
            'block-hero__image ' . $posClasses,
            $lazy,
            '100vw',
            true,
            'block-hero__media ' . $posClasses,
            $mobileImage !== '' ? $mobileImage : null
        );
    }

    return '';
};
?>
<?php if ($isSlider): ?>
<?php
    $slideCount = count($slides);
    $headingTag = $data['_heading_tag'] ?? 'h1';
?>
<div class="block-hero block-hero--media block-hero--slider block-hero--w-<?= $heroWidth ?> block-hero--h-<?= $heroHeight ?> block-hero--pos-<?= $textPos ?> block-hero--y-<?= $textAlignY ?>"
     data-hero-slider<?= $autoplay > 0 ? ' data-autoplay="' . $autoplay . '"' : '' ?>
     role="region" aria-roledescription="<?= htmlspecialchars(t('Карусель'), ENT_QUOTES) ?>"
     aria-label="<?= htmlspecialchars($title !== '' ? \App\Core\TitleMarkup::plain((string) $title) : t('Обложка'), ENT_QUOTES) ?>"<?= $slideCount > 1 ? ' tabindex="0"' : '' ?>>
    <div class="block-hero__slides">
        <?php foreach ($slides as $index => $slide): ?>
            <?php
            $slideImage = trim((string) ($slide['image'] ?? ''));
            $slideVideo = trim((string) ($slide['video_url'] ?? ''));
            $slideYoutubeId = Video::youtubeId((string) ($slide['youtube_url'] ?? ''));
            // Тип фона слайда обычно уже посчитан при сохранении; для старых
            // и вручную заведённых данных выводим его из заполненных полей.
            $slideMediaType = (string) ($slide['media_type'] ?? '');
            if (!in_array($slideMediaType, ['image', 'video', 'youtube'], true)) {
                $slideMediaType = $slideYoutubeId !== null ? 'youtube' : ($slideVideo !== '' ? 'video' : 'image');
            }
            $slidePos = in_array($slide['text_position'] ?? '', ['left', 'center', 'right'], true) ? $slide['text_position'] : $textPos;
            $slideLink = trim((string) ($slide['link_url'] ?? ''));
            $slideTitle = trim((string) ($slide['title'] ?? ''));
            $slideMediaClasses = MediaPosition::classes($slide['image_position'] ?? null, $slide['image_position'] ?? null);
            $slideBtnIcon = $heroButtonIcon((string) ($slide['button_icon'] ?? ''), '');
            $slideBtn2Icon = $heroButtonIcon((string) ($slide['button2_icon'] ?? ''), '');
            $slideBtnUrl = trim((string) ($slide['button_url'] ?? ''));
            $slideBtn2Url = trim((string) ($slide['button2_url'] ?? ''));
            // Оформление слайда: пусто — как у обложки. Светлому фото нужно
            // своё затемнение, тёмному — никакого, и раньше выбора не было.
            $slideOverlay = (string) ($slide['overlay'] ?? '');
            $slideOverlayOn = $slideOverlay === 'on' ? true : ($slideOverlay === 'off' ? false : $overlayEnabled);
            $slideOverlayMode = (string) ($slide['overlay_mode'] ?? '');
            $slideOverlaySolid = $slideOverlayMode === 'solid'
                ? true
                : ($slideOverlayMode === 'gradient' ? false : $overlaySolid);
            $slidePanel = (string) ($slide['panel'] ?? '');
            $slidePanelOn = $slidePanel === 'on' ? true : ($slidePanel === 'off' ? false : $panelOn);
            $slideArtImage = trim((string) ($slide['art_image'] ?? ''));
            $slideArtSize = (string) (($slide['art_size'] ?? '') ?: $artSize);
            $slideArtHtml = $slideArtImage !== ''
                ? $heroArt(
                    $slideArtImage,
                    trim((string) ($slide['art_alt'] ?? '')),
                    $slideArtSize
                )
                : $artHtml;
            $slideArtPosition = (string) (($slide['art_position'] ?? '') ?: $artPosition);
            ?>
            <div class="block-hero__slide block-hero--pos-<?= $slidePos ?><?= $index === 0 ? ' is-active' : '' ?>"
                 role="group" aria-roledescription="<?= htmlspecialchars(t('Слайд'), ENT_QUOTES) ?>"
                 aria-label="<?= ($index + 1) . ' ' . htmlspecialchars(t('из'), ENT_QUOTES) . ' ' . $slideCount ?>"
                 aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                <?= $heroMedia($slideMediaType, $slideImage, $slideVideo, $slideYoutubeId, $slideMediaClasses, $index !== 0) ?>
                <?php if ($slideOverlayOn): ?><div class="block-hero__scrim<?= $slideOverlaySolid ? ' block-hero__scrim--solid' : '' ?>" aria-hidden="true"></div><?php endif; ?>
                <?php if ($slideLink !== '' && UrlGuard::isSafeLink($slideLink)): ?>
                    <?php // Ссылка-подложка: кликабелен весь слайд, при этом кнопки
                          // остаются самостоятельными ссылками, а не вложенными. ?>
                    <a class="block-hero__slide-cover" href="<?= htmlspecialchars($slideLink, ENT_QUOTES) ?>"
                       tabindex="-1" aria-hidden="true"></a>
                <?php endif; ?>
                <div class="block-hero__inner<?= $slideArtHtml !== '' ? ' block-hero__inner--art block-hero__inner--art-' . $slideArtPosition . ' block-hero__inner--art-' . $slideArtSize : '' ?>">
                    <?php if ($slideArtHtml !== '' && $slideArtPosition !== 'right'): ?><?= $slideArtHtml ?><?php endif; ?>
                    <div class="block-hero__text<?= $slidePanelOn ? ' block-hero__text--panel' : '' ?>">
                        <?php if (!empty($slide['eyebrow'])): ?><span class="block-hero__eyebrow"><?= htmlspecialchars((string) $slide['eyebrow'], ENT_QUOTES) ?></span><?php endif; ?>
                        <?php if ($slideTitle !== ''): ?>
                            <?php $tag = $index === 0 ? $headingTag : 'h2'; ?>
                            <<?= $tag ?> class="block-hero__title">
                                <?php if ($slideLink !== '' && UrlGuard::isSafeLink($slideLink)): ?>
                                    <a class="block-hero__title-link" href="<?= htmlspecialchars($slideLink, ENT_QUOTES) ?>"><?= htmlspecialchars($slideTitle, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($slideTitle, ENT_QUOTES) ?>
                                <?php endif; ?>
                            </<?= $tag ?>>
                        <?php endif; ?>
                        <?php if (!empty($slide['subtitle'])): ?><p class="block-hero__subtitle"><?= htmlspecialchars((string) $slide['subtitle'], ENT_QUOTES) ?></p><?php endif; ?>
                        <?php if (!empty($slide['button_text']) && $slideBtnUrl !== '' && UrlGuard::isSafeLink($slideBtnUrl) || !empty($slide['button2_text']) && $slideBtn2Url !== '' && UrlGuard::isSafeLink($slideBtn2Url)): ?>
                        <div class="block-hero__actions">
                            <?php if (!empty($slide['button_text']) && $slideBtnUrl !== '' && UrlGuard::isSafeLink($slideBtnUrl)): ?>
                                <a class="block-hero__button<?= $heroButtonClass($slideBtnIcon) ?>" href="<?= htmlspecialchars($slideBtnUrl, ENT_QUOTES) ?>"><?= $slideBtnIcon ?><?= htmlspecialchars((string) $slide['button_text'], ENT_QUOTES) ?> →</a>
                            <?php endif; ?>
                            <?php if (!empty($slide['button2_text']) && $slideBtn2Url !== '' && UrlGuard::isSafeLink($slideBtn2Url)): ?>
                                <a class="block-hero__button block-hero__button--ghost<?= $heroButtonClass($slideBtn2Icon) ?>" href="<?= htmlspecialchars($slideBtn2Url, ENT_QUOTES) ?>"><?= $slideBtn2Icon ?><?= htmlspecialchars((string) $slide['button2_text'], ENT_QUOTES) ?> →</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($slideArtHtml !== '' && $slideArtPosition === 'right'): ?><?= $slideArtHtml ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($slideCount > 1): ?>
        <div class="block-hero__slider-nav">
            <button type="button" class="block-hero__slider-btn" data-hero-prev aria-label="<?= htmlspecialchars(t('Предыдущий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-left', 22) ?></button>
            <button type="button" class="block-hero__slider-btn" data-hero-next aria-label="<?= htmlspecialchars(t('Следующий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-right', 22) ?></button>
        </div>
        <div class="block-hero__dots" role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>">
            <?php foreach ($slides as $index => $_slide): ?>
                <button type="button" class="block-hero__dot<?= $index === 0 ? ' is-active' : '' ?>" data-slide-index="<?= $index ?>"
                        aria-label="<?= htmlspecialchars(t('Перейти к слайду'), ENT_QUOTES) . ' ' . ($index + 1) ?>"
                        aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
            <?php endforeach; ?>
        </div>
        <span class="visually-hidden" data-slider-status aria-live="polite"></span>
    <?php endif; ?>
</div>
<?php else: ?>
<?php // Без медиа и без своего фона hero — это просто шапка страницы:
      // карточка с рамкой и подложкой в этой роли читается как чужой блок. ?>
<div class="block-hero<?= $hasMedia ? ' block-hero--media' : '' ?><?= (!$hasMedia && $heroBg === '') ? ' block-hero--plain' : '' ?><?= $heroBg !== '' ? ' block-hero--bgcolor' : '' ?><?= ($bgType === 'video' || $bgType === 'youtube') ? ' block-hero--video' : '' ?> block-hero--w-<?= $heroWidth ?> block-hero--h-<?= $heroHeight ?> block-hero--pos-<?= $textPos ?> block-hero--y-<?= $textAlignY ?>">
    <?= $heroMedia($bgType, $image, $videoFile, $youtubeId, $mediaPositionClasses, false, $mobileImage, $mobileVideo === 'poster') ?>
    <?php if ($hasMedia && $overlayEnabled): ?><div class="block-hero__scrim<?= $overlaySolid ? ' block-hero__scrim--solid' : '' ?>" aria-hidden="true"></div><?php endif; ?>
    <div class="block-hero__inner<?= $artHtml !== '' ? ' block-hero__inner--art block-hero__inner--art-' . $artPosition . ' block-hero__inner--art-' . $artSize : '' ?>">
        <?php if ($artHtml !== '' && $artPosition !== 'right'): ?><?= $artHtml ?><?php endif; ?>
        <div class="block-hero__text<?= $panelOn ? ' block-hero__text--panel' : '' ?>">
            <?php if ($eyebrow !== ''): ?><span class="block-hero__eyebrow"><?= htmlspecialchars($eyebrow, ENT_QUOTES) ?></span><?php endif; ?>
            <?php if ($title !== ''): ?><?php $hTag = $data['_heading_tag'] ?? 'h1'; ?><<?= $hTag ?> class="block-hero__title"><?= \App\Core\TitleMarkup::html($title) ?></<?= $hTag ?>><?php endif; ?>
            <?php if ($subtitle !== ''): ?><p class="block-hero__subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES) ?></p><?php endif; ?>
            <?php if (($btnText !== '' && $btnUrl !== '') || ($btn2Text !== '' && $btn2Url !== '') || ($vBtnText !== '')): ?>
            <div class="block-hero__actions">
                <?php if ($btnText !== '' && $btnUrl !== '' && UrlGuard::isSafeLink($btnUrl)): ?>
                    <a class="block-hero__button<?= $heroButtonClass($btnIcon) ?>" href="<?= htmlspecialchars($btnUrl, ENT_QUOTES) ?>"><?= $btnIcon ?><?= htmlspecialchars($btnText, ENT_QUOTES) ?> →</a>
                <?php endif; ?>
                <?php if ($btn2Text !== '' && $btn2Url !== '' && UrlGuard::isSafeLink($btn2Url)): ?>
                    <a class="block-hero__button block-hero__button--ghost<?= $heroButtonClass($btn2Icon) ?>" href="<?= htmlspecialchars($btn2Url, ENT_QUOTES) ?>"><?= $btn2Icon ?><?= htmlspecialchars($btn2Text, ENT_QUOTES) ?> →</a>
                <?php endif; ?>
                <?php if ($vBtnText !== ''): ?>
                    <?php $vSafe = $vBtnUrl !== '' && UrlGuard::isSafeLink($vBtnUrl); ?>
                    <<?= $vSafe ? 'a' : 'span' ?> class="block-hero__play"<?= $vSafe ? ' href="' . htmlspecialchars($vBtnUrl, ENT_QUOTES) . '"' : '' ?>>
                        <span class="block-hero__play-icon" aria-hidden="true"><?= \App\Core\Icon::render('player-play', 24) ?></span>
                        <span class="block-hero__play-label"><?= htmlspecialchars($vBtnText, ENT_QUOTES) ?></span>
                    </<?= $vSafe ? 'a' : 'span' ?>>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($artHtml !== '' && $artPosition === 'right'): ?><?= $artHtml ?><?php endif; ?>
    </div>
</div>
<?php endif; ?>
