<?php

use App\Core\Icon;

/**
 * «Иконка и текст»: карточки с иконкой и парами «подпись → значение».
 * Типовое применение — полезные телефоны и короткие реквизиты.
 *
 * Строки задаются по одной на пару: «Подпись | Значение». Строка без черты
 * выводится одной подписью — так карточка не ломается на неполном вводе.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$align = (string) $data['align'];
// Подпись и значение: в столбик или одной строкой рядом с иконкой
// («Приёмная: +998 71 200-00-00»).
$rowsLayout = (string) $data['rows_layout'];
// Позиция иконки — отдельная настройка. У блоков, сохранённых до её появления,
// её задавало выравнивание: «по центру» означало иконку сверху.
$iconPosition = (string) ($data['icon_position'] ?? '');
if (!in_array($iconPosition, ['left', 'top', 'right'], true)) {
    $iconPosition = $align === 'center' ? 'top' : 'left';
}
$columns = (int) $data['columns'];
$items = array_values(array_filter(
    (array) ($data['items'] ?? []),
    static fn (array $item): bool => trim((string) ($item['rows'] ?? '')) !== ''
        || trim((string) ($item['icon_svg'] ?? '')) !== ''
));
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);

// Число колонок уходит в scoped CSS: инлайн-стили в блоках запрещены тестами.
$templateCss = '';
if ($columns !== 3) {
    $templateCss = '@media (min-width:721px){#block-' . (int) $blockId
        . ' .icon-text__grid{grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));}}';
}
?>
<div class="block-icon-text block-icon-text--<?= $esc($variant) ?> block-icon-text--icon-<?= $esc($iconPosition) ?> block-icon-text--align-<?= $esc($align) ?> block-icon-text--rows-<?= $esc($rowsLayout) ?>">
    <?= \App\Core\SectionHead::render([
        'title' => $title,
        'description' => $description,
        'class' => 'section-head--stacked',
    ]) ?>

    <?php if ($items === []): ?>
        <p class="block-icon-text__empty"><?= $esc(t('Карточки ещё не добавлены.')) ?></p>
    <?php else: ?>
        <div class="icon-text__grid">
            <?php foreach ($items as $index => $item): ?>
                <?php
                $icon = trim((string) ($item['icon_svg'] ?? ''));
                $color = \App\Core\NewsBadge::normalizeColor($item['icon_color'] ?? '');
                $rows = preg_split('/\R/u', (string) ($item['rows'] ?? '')) ?: [];
                // Цвет иконки — переменной в scoped CSS блока, а не в атрибуте
                // style: инлайн-стили в блоках стережёт тест.
                if ($color !== '') {
                    $templateCss .= '#block-' . (int) $blockId . ' .icon-text__card--c' . $index
                        . '{--icon-text-accent:' . $color . ';}';
                }
                ?>
                <div class="icon-text__card<?= $color !== '' ? ' icon-text__card--c' . $index : '' ?>">
                    <?php if ($icon !== ''): ?>
                        <span class="icon-text__icon" aria-hidden="true"><?= Icon::render($icon, 26) ?></span>
                    <?php endif; ?>
                    <div class="icon-text__body">
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $row = trim((string) $row);
                            if ($row === '') {
                                continue;
                            }
                            $parts = array_map('trim', explode('|', $row, 2));
                            ?>
                            <?php // Каждая пара — своя строка: без обёртки в режиме
                                  // «в одну строку» подписи и значения разных пар
                                  // сливались в общий поток и переносились вперемешку. ?>
                            <span class="icon-text__row">
                                <p class="icon-text__label"><?= $esc($parts[0]) ?></p>
                                <?php if (($parts[1] ?? '') !== ''): ?>
                                    <p class="icon-text__value"><?= $esc($parts[1]) ?></p>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
