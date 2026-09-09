<?php
/** @var array $data */
$title = $data['title'] ?? '';
$items = $data['items'] ?? [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$cardBg = (string) $data['card_bg'];
$textColor = (string) $data['text_color'];
$iconSize = (int) $data['icon_size'];
$iconBoxSize = max(42, $iconSize + 22);
$iconBackground = (string) $data['icon_bg'];
$iconPosition = (string) $data['icon_position'];
$panel = (string) $data['panel'];
$textAlign = (string) $data['text_align'];
$variant = (string) $data['variant'];
$valueSize = (string) $data['value_size'];
$cstyle = '--counter-icon-size:' . $iconSize . 'px;--counter-icon-box-size:' . $iconBoxSize . 'px;'
    . ($cardBg !== '' ? '--counters-bg:' . $cardBg . ';' : '')
    . ($textColor !== '' ? '--counters-text:' . $textColor . ';' : '');
$templateCss = '#block-' . $blockId . ' .block-counters{' . $cstyle . '}';
$blockClasses = ($iconBackground === 'off' ? ' block-counters--icons-no-bg' : '')
    . ' block-counters--panel-' . $panel
    . ' block-counters--icon-pos-' . $iconPosition
    . ' block-counters--text-align-' . $textAlign
    . ' block-counters--' . $variant
    . ' block-counters--size-' . $valueSize;
?>
<div class="block-counters<?= $blockClasses ?>">
    <?php if ($title !== ''): ?><h2 class="block-counters__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
    <div class="block-counters__grid">
        <?php foreach ($items as $item):
            $value = (string) ($item['value'] ?? '');
            // Отсчёт включается только для чистого числа: «24/7» и «№1»
            // анимировать нечем, а разряды в «1 200» скрипт бы потерял.
            $countable = preg_match('/^\d{1,9}$/', $value) === 1;
            $link = (string) ($item['link'] ?? '');
            $note = (string) ($item['note'] ?? '');
            $prefix = (string) ($item['prefix'] ?? '');
            $iconImage = trim((string) ($item['icon_image'] ?? ''));
            if ($iconImage !== '' && !\App\Core\UrlGuard::isSafeMedia($iconImage)) {
                $iconImage = '';
            }
            $tag = $link !== '' ? 'a' : 'div';
        ?>
            <<?= $tag ?> class="counter<?= $link !== '' ? ' counter--link' : '' ?>"<?= $link !== '' ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '' ?>>
                <?php if ($iconImage !== ''): ?>
                    <span class="counter__icon" aria-hidden="true"><img class="counter__icon-img" src="<?= htmlspecialchars($iconImage, ENT_QUOTES) ?>" alt="" width="<?= $iconSize ?>" height="<?= $iconSize ?>"></span>
                <?php elseif (!empty($item['icon_svg'])): ?>
                    <span class="counter__icon" aria-hidden="true"><?= \App\Core\Icon::render($item['icon_svg'], $iconSize) ?></span>
                <?php endif; ?>
                <div class="counter__body">
                    <div class="counter__num">
                        <?php if ($prefix !== ''): ?><span class="counter__prefix"><?= htmlspecialchars($prefix, ENT_QUOTES) ?></span><?php endif; ?>
                        <span class="counter__value"<?= $countable ? ' data-counter-target="' . htmlspecialchars($value, ENT_QUOTES) . '"' : '' ?>><?= htmlspecialchars($value, ENT_QUOTES) ?></span>
                        <?php if (!empty($item['suffix'])): ?><span class="counter__suffix"><?= htmlspecialchars($item['suffix'], ENT_QUOTES) ?></span><?php endif; ?>
                    </div>
                    <div class="counter__label"><?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?></div>
                    <?php if ($note !== ''): ?><div class="counter__note"><?= htmlspecialchars($note, ENT_QUOTES) ?></div><?php endif; ?>
                </div>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
</div>
