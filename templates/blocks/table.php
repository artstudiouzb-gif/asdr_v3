<?php

use App\Core\TableRows;
use App\Core\TitleMarkup;

/**
 * «Таблица»: реквизиты, тарифы, график приёма, сравнение показателей.
 *
 * Ряды набираются построчно, ячейки через `|` — разбор в App\Core\TableRows.
 * Ячейка остаётся простым текстом: HTML в неё не допускается, как и в любое
 * поле-заголовок.
 *
 * Широкая таблица прокручивается внутри своей рамки, а не растягивает
 * страницу: горизонтальная прокрутка всего документа ломает и шапку, и
 * якорную навигацию. Рамке даны role="region" и tabindex, иначе до прокрутки
 * нельзя добраться с клавиатуры, а диктор не сообщит, что область прокручивается.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$rows = TableRows::parse((string) $data['rows']);
$headerRow = !empty($data['header_row']);
$headerCol = !empty($data['header_col']);
$variant = (string) $data['variant'];
$density = (string) $data['density'];

$tableClasses = 'block-table__grid'
    . ' block-table__grid--' . $variant
    . ' block-table__grid--' . $density;
$regionLabel = $title !== '' ? TitleMarkup::plain($title) : t('Таблица');
?>
<div class="block-table">
    <?php if ($title !== ''): ?>
        <h2 class="section-head__title block-table__title" id="table-<?= (int) $blockId ?>-title"><?= TitleMarkup::html($title) ?></h2>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <p class="block-table__empty"><?= htmlspecialchars(t('Таблица не заполнена.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="block-table__scroll" role="region" tabindex="0" aria-label="<?= htmlspecialchars($regionLabel, ENT_QUOTES) ?>">
            <table class="<?= $tableClasses ?>">
                <?php if ($headerRow): ?>
                    <thead>
                        <tr>
                            <?php foreach ($rows[0] as $cell): ?>
                                <th scope="col"><?= htmlspecialchars($cell, ENT_QUOTES) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                <?php endif; ?>
                <tbody>
                    <?php foreach (array_slice($rows, $headerRow ? 1 : 0) as $row): ?>
                        <tr>
                            <?php foreach ($row as $index => $cell): ?>
                                <?php if ($headerCol && $index === 0): ?>
                                    <th scope="row"><?= htmlspecialchars($cell, ENT_QUOTES) ?></th>
                                <?php else: ?>
                                    <td><?= htmlspecialchars($cell, ENT_QUOTES) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
