<?php
/** @var array $data */
/** @var int $blockId */
$title = trim((string) ($data['title'] ?? ''));
$rawText = trim((string) ($data['text'] ?? ''));
// Новые записи содержат безопасный HTML из WYSIWYG. Старые записи могли
// хранить обычный текст, поэтому для них сохраняем прежнее отображение
// переносов строк. В обоих случаях вывод проходит через allowlist.
$text = \App\Core\HtmlSanitizer::sanitizeText($rawText);
$textIsPlain = $rawText !== '' && $rawText === strip_tags($rawText);
if ($textIsPlain) {
    $text = nl2br($text);
}
$image = trim((string) ($data['image'] ?? ''));
$items = $data['items'] ?? [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$imageSide = (string) $data['image_side'];
$ratio = (string) $data['image_ratio'];
$mediaClasses = \App\Core\MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);
$buttonText = trim((string) ($data['button_text'] ?? ''));
$buttonUrl = trim((string) ($data['button_url'] ?? ''));
if ($buttonUrl !== '' && !\App\Core\UrlGuard::isSafeLink($buttonUrl)) {
    $buttonUrl = '';
}

// Доля ширины под кадр — долями fr в scoped CSS блока: инлайн-стили в блоках
// запрещены тестами.
$templateCss = '';
$visualWidth = (int) $data['image_width'];
if ($image !== '' && $visualWidth !== 50) {
    $templateCss = '@media (min-width:901px){#block-' . (int) $blockId
        . ' .block-textimage{--textimage-info:' . (100 - $visualWidth) . 'fr;--textimage-visual:' . $visualWidth . 'fr}}';
}
?>
<div class="block-textimage block-textimage--image-<?= $imageSide ?> block-textimage--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?><?= $image !== '' ? '' : ' block-textimage--no-image' ?>">
    <div class="textimage__info">
        <?php if ($title !== ''): ?><h2 class="textimage__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><div class="textimage__text rich-content"><?= $text ?></div><?php endif; ?>
        <?php if (!empty($items)): ?>
            <div class="textimage__features">
                <?php foreach ($items as $item): ?>
                    <span class="textimage__feature">
                        <?php if (!empty($item['icon_svg'])): ?><span class="textimage__feature-icon"><?= \App\Core\Icon::render($item['icon_svg'], 22) ?></span><?php endif; ?>
                        <span class="textimage__feature-label"><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES) ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($buttonText !== '' && $buttonUrl !== ''): ?>
            <?php // Своя кнопка, а не общий .btn: тот навязывает градиент и белый
                  // текст через !important, но не задаёт ни display, ни отступы —
                  // получалась строчная ссылка с подложкой впритык к буквам. ?>
            <a class="textimage__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($image !== ''): ?>
        <div class="textimage__visual">
            <?= \App\Core\Media::picture($image, $title, null, null, 'textimage__media ' . $mediaClasses, true, '(max-width: 900px) 100vw, ' . $visualWidth . 'vw') ?>
        </div>
    <?php endif; ?>
</div>
