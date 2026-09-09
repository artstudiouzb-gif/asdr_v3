<?php

use App\Core\DocumentPresenter;
use App\Core\Icon;

/**
 * Карточка правового акта: номер и дата вынесены в шапку, название остаётся
 * обычным текстом, внизу формат файла. Отличается от обычной карточки
 * документа тем, что у акта есть реквизиты, по которым его и ищут.
 *
 * @var array $doc
 */
$presented = DocumentPresenter::prepare($doc);
$tag = $presented['url'] !== '' ? 'a' : 'div';
$number = trim((string) ($doc['number'] ?? ''));
$date = trim((string) ($doc['date'] ?? ''));
$editorialAct = !empty($editorialAct);
$showEmblem = !isset($showEmblem) || (bool) $showEmblem;
$docIndex = isset($docIndex) ? (int) $docIndex : 0;
?>
<<?= $tag ?>
    class="feature-card act-card"
    data-document-card
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars(trim($presented['search'] . ' ' . mb_strtolower($number . ' ' . $date)), ENT_QUOTES) ?>"
    <?= $presented['url'] !== '' ? 'href="' . htmlspecialchars($presented['url'], ENT_QUOTES) . '"' : '' ?>
    <?= $presented['url'] !== '' && $presented['direct_file'] ? 'download' : '' ?>
>
    <?php if ($showEmblem): ?><span class="act-card__emblem" aria-hidden="true"></span><?php endif; ?>
    <span class="feature-card__top act-card__editorial-head" aria-hidden="true">
        <span class="feature-card__icon act-card__editorial-icon"><?= Icon::render('file-description', 22) ?></span>
        <span class="feature-card__num act-card__index" aria-hidden="true"><?= str_pad((string) ($docIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
    </span>
    <?php if ($number !== '' || $date !== ''): ?>
        <span class="act-card__head">
            <?php if ($number !== ''): ?><span class="feature-card__title act-card__number"><?= htmlspecialchars($number, ENT_QUOTES) ?></span><?php endif; ?>
            <?php if ($date !== ''): ?><span class="act-card__date"><?= htmlspecialchars($date, ENT_QUOTES) ?></span><?php endif; ?>
        </span>
    <?php endif; ?>
    <p class="feature-card__text act-card__desc"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></p>
    <?php /* У акта без ссылки и без формата файла подвал пуст: его разделительная
             линия висела бы под названием как обрыв карточки. */ ?>
    <?php if ($presented['meta'] !== '' || $presented['url'] !== ''): ?>
    <span class="act-card__foot">
        <?php if ($presented['meta'] !== ''): ?>
            <span class="act-card__meta">
                <?= Icon::render($presented['icon'], 16, 'act-card__meta-icon', 1.6) ?>
                <?= htmlspecialchars($presented['meta'], ENT_QUOTES) ?>
            </span>
        <?php endif; ?>
        <?php if ($presented['url'] !== ''): ?>
            <span class="act-card__action">
                <?= htmlspecialchars(t($presented['direct_file'] ? 'Скачать' : 'Открыть'), ENT_QUOTES) ?>
                <?= Icon::render($presented['direct_file'] ? 'download' : 'external-link', 16, 'act-card__action-icon', 1.8) ?>
            </span>
        <?php endif; ?>
    </span>
    <?php endif; ?>
</<?= $tag ?>>
