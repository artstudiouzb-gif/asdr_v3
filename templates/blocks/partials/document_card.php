<?php

use App\Core\DocumentPresenter;

/**
 * Карточка документа: лист формата, название, описание с фото обложки и
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
    class="doc-card<?= $compact ? ' doc-card--compact' : '' ?>"
    data-document-card<?= !empty($carouselItem) ? ' data-carousel-item' : '' ?>
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars($presented['search'], ENT_QUOTES) ?>"
>
    <?php include __DIR__ . '/document_visual.php'; ?>
    <div class="doc-card__body">
        <span class="doc-card__title"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></span>
        <?php $summaryClass = ''; include __DIR__ . '/document_summary.php'; ?>
        <?php $filesClass = ''; include __DIR__ . '/document_files.php'; ?>
    </div>
</article>
