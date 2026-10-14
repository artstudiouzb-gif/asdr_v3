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
// Раскладка без фото теряет смысл — такой блок остаётся одной колонкой текста.
$layout = $image !== '' ? (string) $data['layout'] : 'side';
$ratio = (string) $data['image_ratio'];
$mediaClasses = \App\Core\MediaPosition::classes($data['image_position'] ?? null, $data['image_position_mobile'] ?? null);
$buttonText = trim((string) ($data['button_text'] ?? ''));
$buttonUrl = trim((string) ($data['button_url'] ?? ''));
if ($buttonUrl !== '' && !\App\Core\UrlGuard::isSafeLink($buttonUrl)) {
    $buttonUrl = '';
}
$linkText = trim((string) ($data['link_text'] ?? ''));
$linkUrl = trim((string) ($data['link_url'] ?? ''));
if ($linkUrl !== '' && !\App\Core\UrlGuard::isSafeLink($linkUrl)) {
    $linkUrl = '';
}
$hasButton = $buttonText !== '' && $buttonUrl !== '';
$hasLink = $linkText !== '' && $linkUrl !== '';
// Факт на фото — только там, где у кадра свободен угол (схема прячет поля у
// остальных раскладок, но данные могли остаться от прежнего выбора).
$factValue = in_array($layout, ['side', 'bleed', 'spread'], true) ? trim((string) ($data['fact_value'] ?? '')) : '';
$factCaption = $factValue !== '' ? trim((string) ($data['fact_caption'] ?? '')) : '';

// Доля ширины под кадр — долями fr в scoped CSS блока: инлайн-стили в блоках
// запрещены тестами.
$templateCss = '';
$visualWidth = (int) $data['image_width'];
if ($image !== '' && $layout === 'side' && $visualWidth !== 50) {
    $templateCss = '@media (min-width:901px){#block-' . (int) $blockId
        . ' .block-textimage{--textimage-info:' . (100 - $visualWidth) . 'fr;--textimage-visual:' . $visualWidth . 'fr}}';
}
// Водяной знак «Разворота» — маской абсолютным адресом: var(--gov-emblem)
// объявлена относительным url и из scoped CSS разрешается не туда.
if ($layout === 'spread') {
    $templateCss .= '#block-' . (int) $blockId . ' .block-textimage{--textimage-emblem:url("'
        . \App\Core\BlockBackground::emblemUrl() . '")}';
}
?>
<div class="block-textimage block-textimage--layout-<?= htmlspecialchars($layout, ENT_QUOTES) ?> block-textimage--image-<?= $imageSide ?> block-textimage--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?><?= $image !== '' ? '' : ' block-textimage--no-image' ?><?= $factValue !== '' ? ' block-textimage--has-fact' : '' ?>">
    <?php // У «Текста поверх фото» текст лежит карточкой со своей поверхностью:
          // класс --card входит в SectionColors::SURFACES, и на тёмной секции
          // текст карточки остаётся тёмным на светлом. ?>
    <div class="textimage__info<?= $layout === 'overlap' ? ' textimage__info--card' : '' ?>">
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
        <?php if ($hasButton || $hasLink): ?>
            <div class="textimage__actions">
                <?php if ($hasButton): ?>
                    <?php // Своя кнопка, а не общий .btn: тот навязывает градиент и белый
                          // текст через !important, но не задаёт ни display, ни отступы —
                          // получалась строчная ссылка с подложкой впритык к буквам. ?>
                    <a class="textimage__button" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($buttonText, ENT_QUOTES) ?></a>
                <?php endif; ?>
                <?php if ($hasLink): ?>
                    <a class="textimage__link" href="<?= htmlspecialchars($linkUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($linkText, ENT_QUOTES) ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($image !== ''): ?>
        <div class="textimage__visual">
            <?= \App\Core\Media::picture($image, $title, null, null, 'textimage__media ' . $mediaClasses, true, '(max-width: 900px) 100vw, ' . $visualWidth . 'vw') ?>
            <?php if ($factValue !== ''): ?>
                <p class="textimage__fact"><span class="textimage__fact-value"><?= htmlspecialchars($factValue, ENT_QUOTES) ?></span><?php if ($factCaption !== ''): ?><span class="textimage__fact-caption"><?= htmlspecialchars($factCaption, ENT_QUOTES) ?></span><?php endif; ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
