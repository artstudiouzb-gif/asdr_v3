<?php

use App\Core\DocumentPresenter;

/**
 * Карточка документа: лицо (обложка или лист формата), название, описание и
 * файлы — главный на языке страницы плюс переводы. Карточка — `<article>`, а
 * не ссылка: у документа бывает несколько файлов, и ссылки переводов внутри
 * ссылки-карточки браузер разорвал бы. Кликабельна она всё равно целиком —
 * это делает главная кнопка (см. document_files.php).
 *
 * @var array $doc
 * @var bool|null $compact
 */
$presented = DocumentPresenter::prepare($doc);
$compact = !empty($compact);
?>
<article
    class="doc-card<?= $compact ? ' doc-card--compact' : '' ?><?= $presented['cover'] !== '' ? ' doc-card--cover' : '' ?>"
    data-document-card
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars($presented['search'], ENT_QUOTES) ?>"
>
    <?php include __DIR__ . '/document_visual.php'; ?>
    <span class="doc-card__body">
        <span class="doc-card__title"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></span>
        <?php if ($presented['description'] !== ''): ?>
            <span class="doc-desc"><?= $presented['description'] ?></span>
        <?php endif; ?>
        <?php $filesClass = ''; include __DIR__ . '/document_files.php'; ?>
    </span>
</article>
