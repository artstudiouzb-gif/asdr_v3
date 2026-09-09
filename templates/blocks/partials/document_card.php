<?php

use App\Core\DocumentPresenter;
use App\Core\Icon;

/** @var array $doc */
/** @var bool|null $compact */
$presented = DocumentPresenter::prepare($doc);
$tag = $presented['url'] !== '' ? 'a' : 'div';
$compact = !empty($compact);
?>
<<?= $tag ?>
    class="doc-card<?= $compact ? ' doc-card--compact' : '' ?>"
    data-document-card
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars($presented['search'], ENT_QUOTES) ?>"
    <?= $presented['url'] !== '' ? 'href="' . htmlspecialchars($presented['url'], ENT_QUOTES) . '"' : '' ?>
    <?= $presented['url'] !== '' && $presented['direct_file'] ? 'download' : '' ?>
>
    <span class="doc-card__icon" aria-hidden="true">
        <?= Icon::render($presented['icon'], 24, 'doc-card__icon-svg', 1.5) ?>
    </span>
    <span class="doc-card__body">
        <span class="doc-card__title"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></span>
        <?php if ($presented['meta'] !== ''): ?>
            <span class="doc-card__meta"><?= htmlspecialchars($presented['meta'], ENT_QUOTES) ?></span>
        <?php endif; ?>
    </span>
    <?php if ($presented['url'] !== ''): ?>
        <span class="doc-card__dl" aria-hidden="true">
            <?= Icon::render($presented['direct_file'] ? 'download' : 'external-link', 18, 'doc-card__download-icon', 1.8) ?>
        </span>
        <span class="visually-hidden">
            <?= htmlspecialchars(t($presented['direct_file'] ? 'Скачать документ' : 'Открыть документ'), ENT_QUOTES) ?>
        </span>
    <?php endif; ?>
</<?= $tag ?>>
