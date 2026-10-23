<?php

use App\Core\DocumentPresenter;

/**
 * Карточка правового акта: номер и дата вынесены в шапку, ниже название и
 * описание, внизу файлы — на языке страницы и переводы. Отличается от обычной
 * карточки документа тем, что у акта есть реквизиты, по которым его и ищут.
 *
 * @var array $doc
 */
$presented = DocumentPresenter::prepare($doc);
$number = trim((string) ($doc['number'] ?? ''));
$date = trim((string) ($doc['date'] ?? ''));
$editorialAct = !empty($editorialAct);
$showEmblem = !isset($showEmblem) || (bool) $showEmblem;
$docIndex = isset($docIndex) ? (int) $docIndex : 0;
?>
<article
    class="feature-card act-card<?= $number === '' ? ' act-card--no-number' : '' ?>"
    data-document-card
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars(trim($presented['search'] . ' ' . mb_strtolower($number . ' ' . $date)), ENT_QUOTES) ?>"
>
    <?php if ($showEmblem): ?><span class="act-card__emblem" aria-hidden="true"></span><?php endif; ?>
    <span class="feature-card__top act-card__editorial-head" aria-hidden="true">
        <?php include __DIR__ . '/document_visual.php'; ?>
        <span class="feature-card__num act-card__index" aria-hidden="true"><?= str_pad((string) ($docIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
    </span>
    <?php if ($number !== '' || $date !== ''): ?>
        <span class="act-card__head">
            <?php if ($number !== ''): ?><span class="feature-card__title act-card__number"><?= htmlspecialchars($number, ENT_QUOTES) ?></span><?php endif; ?>
            <?php if ($date !== ''): ?><span class="act-card__date"><?= htmlspecialchars($date, ENT_QUOTES) ?></span><?php endif; ?>
        </span>
    <?php endif; ?>
    <?php /* Без номера акта название и есть заголовок карточки: у изданий и
             отчётов реквизитов нет, и обычным текстом оно терялось. */ ?>
    <p class="feature-card__text act-card__desc"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></p>
    <?php $summaryClass = 'act-card__summary'; include __DIR__ . '/document_summary.php'; ?>
    <?php /* У акта без файла подвал пуст: его линия висела бы под названием
             как обрыв карточки, поэтому партиал без файлов не печатает ничего. */ ?>
    <?php $filesClass = 'act-card__foot'; include __DIR__ . '/document_files.php'; ?>
</article>
