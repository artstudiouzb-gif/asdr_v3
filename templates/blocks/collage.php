<?php

use App\Core\BlockBackground;
use App\Core\CollageBadge;
use App\Core\Icon;
use App\Core\Media;
use App\Core\TitleMarkup;

/**
 * «Коллаж»: свободная композиция из разнотипных элементов на общей сетке.
 *
 * Размещение хранится номерами ячеек, а не координатами: два элемента могут
 * занять одни и те же ячейки — так и получается наложение, а порядок в
 * репитере решает, кто сверху. Свободные X/Y не годятся, потому что их нечем
 * сложить в столбец на телефоне.
 *
 * Правила размещения уходят в scoped CSS: инлайн-стили в блоках запрещены
 * тестами, а значения тут у каждого блока свои.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$columns = (int) $data['columns'];
$rows = (int) $data['rows'];
$ratio = (string) $data['ratio'];
$gap = (string) $data['gap'];
$items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));

$scope = '#block-' . (int) $blockId;
$templateCss = $scope . ' .collage__canvas{--collage-cols:' . $columns . ';--collage-rows:' . $rows . ';}';

foreach ($items as $index => $item) {
    $templateCss .= $scope . ' .collage__item--' . $index . '{'
        . 'grid-column:' . (int) $item['col'] . '/span ' . (int) $item['col_span'] . ';'
        . 'grid-row:' . (int) $item['row'] . '/span ' . (int) $item['row_span'] . ';'
        . '}';
    // Свой цвет элемента — тоже переменная: инлайн-стиль здесь запрещён.
    $vars = '';
    if (($item['bg'] ?? '') !== '') {
        $vars .= '--collage-bg:' . $item['bg'] . ';';
    }
    if (($item['fg'] ?? '') !== '') {
        $vars .= '--collage-fg:' . $item['fg'] . ';';
    }
    if ($vars !== '') {
        $templateCss .= $scope . ' .collage__item--' . $index . '{' . $vars . '}';
    }
}

// Точка фокуса уходит аргументом в Media::picture, а не собственным
// object-position: рендерер печатает её инлайновой переменной у самого <img>,
// и своё правило либо проигрывало бы ей, либо молча затирало точку фокуса,
// заданную снимку в медиатеке.
$focusMap = [
    'center' => [50, 50],
    'top' => [50, 0],
    'bottom' => [50, 100],
    'left' => [0, 50],
    'right' => [100, 50],
];
?>
<div class="block-collage">
    <?php if ($title !== ''): ?><h2 class="section-head__title block-collage__title"><?= TitleMarkup::html($title) ?></h2><?php endif; ?>
    <?php if ($items !== []): ?>
        <div class="collage__canvas collage__canvas--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?> collage__canvas--gap-<?= htmlspecialchars($gap, ENT_QUOTES) ?>">
            <?php foreach ($items as $index => $item):
                $type = (string) $item['type'];
                // Печать всегда круглая: у неё форма не настройка, а суть.
                $shape = $type === 'badge' ? 'circle' : (string) $item['shape'];
                $link = (string) ($item['link'] ?? '');
                $tag = $link !== '' ? 'a' : 'div';
                $classes = 'collage__item collage__item--' . $index
                    . ' collage__item--' . $type
                    . ' collage__item--shape-' . $shape;
            ?>
                <<?= $tag ?> class="<?= $classes ?>"<?= $link !== '' ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '' ?>>
                    <?php if ($type === 'photo'):
                        $focus = $focusMap[(string) ($item['focus'] ?? 'auto')] ?? [null, null];
                        echo Media::picture(
                            (string) $item['image'],
                            (string) ($item['alt'] ?? ''),
                            $focus[0],
                            $focus[1],
                            'collage__photo',
                            true,
                            '(max-width: 720px) 100vw, 50vw'
                        );
                    elseif ($type === 'stat'): ?>
                        <?php if (!empty($item['icon_svg'])): ?>
                            <span class="collage__stat-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 32) ?></span>
                        <?php endif; ?>
                        <?php if (($item['value'] ?? '') !== ''): ?>
                            <span class="collage__stat-value"><?= htmlspecialchars((string) $item['value'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                        <?php if (($item['label'] ?? '') !== ''): ?>
                            <span class="collage__stat-label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    <?php elseif ($type === 'badge'):
                        // Надпись по кругу собирает CollageBadge: это текст на
                        // траектории, а не иконка, и шаблону такую геометрию
                        // носить нельзя.
                        $badgeText = (string) ($item['text'] ?? '');
                    ?>
                        <span class="collage__badge">
                            <?php if ($badgeText !== ''): ?>
                                <?= CollageBadge::ring($badgeText, 'collage-badge-' . (int) $blockId . '-' . $index) ?>
                                <span class="visually-hidden"><?= htmlspecialchars($badgeText, ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['icon_svg'])): ?>
                                <span class="collage__badge-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 26) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php else:
                        // Узор берётся из общего набора фонов секции, а не
                        // рисуется здесь заново.
                        $templateCss .= $scope . ' .collage__item--' . $index . ' .collage__pattern{'
                            . BlockBackground::patternCss((string) $item['pattern']) . '}';
                    ?>
                        <span class="collage__pattern" aria-hidden="true"></span>
                    <?php endif; ?>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
