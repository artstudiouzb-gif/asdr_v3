<?php
use App\Core\Icon;
use App\Core\Media;
use App\Core\MediaPosition;
use App\Core\UrlGuard;
use App\Core\Video;

/** @var array $data */
$title = $data['title'] ?? '';
$content = \App\Core\HtmlSanitizer::sanitizeText((string) ($data['content'] ?? ''));
$variant = in_array($data['variant'] ?? 'default', ['default', 'section', 'intro', 'system', 'spotlight'], true)
    ? (string) $data['variant']
    : 'default';
$asideTitle = trim((string) ($data['aside_title'] ?? ''));
$items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
$quote = trim((string) ($data['quote'] ?? ''));
// Оформление цитаты. Пустой цвет и нулевой размер означают «как в теме»:
// переменная не объявляется вовсе, и работает запасное значение из gov-theme.
$quoteBg = trim((string) ($data['quote_bg'] ?? ''));
$quoteFg = trim((string) ($data['quote_color'] ?? ''));
$quoteMark = in_array($data['quote_mark'] ?? 'text', ['text', 'icon', 'none'], true)
    ? (string) $data['quote_mark']
    : 'text';
$quoteMarkPosition = in_array($data['quote_mark_position'] ?? 'top-left', ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'above'], true)
    ? (string) $data['quote_mark_position']
    : 'top-left';
$quoteMarkSize = max(0, min(240, (int) ($data['quote_mark_size'] ?? 0)));
$quoteMarkColor = trim((string) ($data['quote_mark_color'] ?? ''));
$quoteMarkHtml = '';
if ($quoteMark === 'icon') {
    // Кегль знака задаёт CSS (svg { width: 1em }), поэтому размер значка тут
    // нужен только как разумный запас качества.
    $quoteMarkHtml = Icon::render((string) ($data['quote_mark_icon'] ?? ''), 48, 'block-text__quote-glyph');
} elseif ($quoteMark === 'text') {
    $quoteMarkHtml = htmlspecialchars(mb_substr(trim((string) ($data['quote_mark_text'] ?? "\u{201c}")), 0, 2), ENT_QUOTES);
}

$quoteVars = ($quoteBg !== '' ? '--quote-bg:' . $quoteBg . ';' : '')
    // Свой цвет текста главнее подбора; если задан только фон, цвет считается
    // по контрасту — белым по светлой заливке цитату не прочесть.
    . ($quoteFg !== ''
        ? '--quote-fg:' . $quoteFg . ';'
        : ($quoteBg !== '' ? '--quote-fg:' . \App\Core\AccentContrast::onFill($quoteBg) . ';' : ''))
    . ($quoteMarkColor !== '' ? '--quote-mark-color:' . $quoteMarkColor . ';' : '')
    . ($quoteMarkSize > 0 ? '--quote-mark-size:' . $quoteMarkSize . 'px;' : '');
if ($variant === 'spotlight' && $quote !== '' && $quoteVars !== '') {
    $templateCss = '#block-' . (int) $blockId . ' .block-text__quote{' . $quoteVars . '}';
}
$mediaType = in_array($data['media_type'] ?? 'none', ['none', 'image', 'video', 'youtube'], true)
    ? (string) $data['media_type']
    : 'none';
$mediaImage = trim((string) ($data['media_image'] ?? ''));
$mediaVideo = trim((string) ($data['media_video'] ?? ''));
$mediaYoutubeId = Video::youtubeId((string) ($data['media_youtube'] ?? ''));
$mediaAlt = trim((string) ($data['media_alt'] ?? ''));
$mediaCaption = trim((string) ($data['media_caption'] ?? ''));
$mediaClasses = MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);

if ($mediaImage !== '' && !UrlGuard::isSafeMedia($mediaImage)) {
    $mediaImage = '';
}
if ($mediaVideo !== '' && !UrlGuard::isSafeMedia($mediaVideo)) {
    $mediaVideo = '';
}

$resolvedMediaType = match ($mediaType) {
    'image' => $mediaImage !== '' ? 'image' : 'placeholder',
    'video' => $mediaVideo !== '' ? 'video' : 'placeholder',
    'youtube' => $mediaYoutubeId !== null ? 'youtube' : 'placeholder',
    default => 'placeholder',
};
?>
<div class="block-text block-text--<?= htmlspecialchars($variant, ENT_QUOTES) ?>">
    <?php if ($title !== ''): ?>
        <h2 class="block-text__title"><?= \App\Core\TitleMarkup::html($title) ?></h2>
    <?php endif; ?>
    <div class="block-text__layout">
        <?php if ($variant === 'intro'): ?>
            <div class="block-text__intro-copy">
                <div class="block-text__content rich-content"><?= $content ?></div>
                <?php if ($items !== []): ?>
                    <ol class="block-text__principles">
                        <?php foreach ($items as $item): ?>
                            <li class="block-text__principle">
                                <?php if (!empty($item['icon_svg'])): ?><span class="block-text__principle-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 22) ?></span><?php endif; ?>
                                <span><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
            <figure class="block-text__media block-text__media--<?= $resolvedMediaType ?>">
                <?php if ($resolvedMediaType === 'image'): ?>
                    <?= Media::picture($mediaImage, $mediaAlt, null, null, 'block-text__media-image ' . $mediaClasses, true, '(max-width: 1000px) 100vw, 44vw') ?>
                <?php elseif ($resolvedMediaType === 'video'): ?>
                    <video class="block-text__media-video" controls playsinline preload="metadata"<?= $mediaImage !== '' ? ' poster="' . htmlspecialchars($mediaImage, ENT_QUOTES) . '"' : '' ?>>
                        <source src="<?= htmlspecialchars($mediaVideo, ENT_QUOTES) ?>" type="video/mp4">
                    </video>
                <?php elseif ($resolvedMediaType === 'youtube' && $mediaYoutubeId !== null): ?>
                    <iframe class="block-text__media-video" src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($mediaYoutubeId, ENT_QUOTES) ?>?rel=0&amp;playsinline=1" title="<?= htmlspecialchars($mediaAlt !== '' ? $mediaAlt : ($title !== '' ? $title : t('Видео')), ENT_QUOTES) ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                <?php else: ?>
                    <span class="block-text__media-placeholder" aria-hidden="true">
                        <span class="block-text__media-emblem"></span>
                    </span>
                <?php endif; ?>
                <?php if ($resolvedMediaType !== 'placeholder' && $mediaCaption !== ''): ?>
                    <figcaption class="block-text__media-caption"><?= htmlspecialchars($mediaCaption, ENT_QUOTES) ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php else: ?>
            <div class="block-text__content rich-content"><?= $content ?></div>
        <?php endif; ?>

        <?php if ($variant === 'system' && ($asideTitle !== '' || $items !== [])): ?>
            <aside class="block-text__system"<?= $asideTitle !== '' ? ' aria-labelledby="block-text-system-' . $blockId . '"' : '' ?>>
                <?php if ($asideTitle !== ''): ?><h3 id="block-text-system-<?= $blockId ?>" class="block-text__system-title"><?= htmlspecialchars($asideTitle, ENT_QUOTES) ?></h3><?php endif; ?>
                <?php if ($items !== []): ?>
                    <ul class="block-text__system-list">
                        <?php foreach ($items as $item): ?>
                            <li class="block-text__system-item">
                                <?php if (!empty($item['icon_svg'])): ?><span class="block-text__system-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 24) ?></span><?php endif; ?>
                                <span><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </aside>
        <?php elseif ($variant === 'spotlight' && $quote !== ''): ?>
            <blockquote class="block-text__quote block-text__quote--mark-<?= htmlspecialchars($quoteMarkPosition, ENT_QUOTES) ?>">
                <?php if ($quoteMarkHtml !== ''): ?><span class="block-text__quote-mark" aria-hidden="true"><?= $quoteMarkHtml ?></span><?php endif; ?>
                <p><?= nl2br(htmlspecialchars($quote, ENT_QUOTES)) ?></p>
            </blockquote>
        <?php endif; ?>
    </div>
</div>
