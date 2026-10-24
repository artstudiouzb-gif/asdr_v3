<?php

use App\Core\AssetCollector;
use App\Core\Icon;

/**
 * Просмотр PDF на телефонах (см. Site\PdfController). Страницы документа
 * рисует PDF.js в холсты по мере прокрутки; без JavaScript здесь остаётся
 * ссылка на сам файл — сюда без скрипта и не попадают.
 *
 * @var string $fileUrl
 * @var string $fileName
 * @var string $pdfjsUrl
 * @var string $workerUrl
 * @var string $viewerScript
 */

$metaTitle = $fileName;
$robotsNoindex = true;
AssetCollector::requireCss('pdf-viewer', '/assets/css/blocks/pdf-viewer.css');
require __DIR__ . '/_header.php';
?>
<main class="pdf-viewer" id="main-content"
      data-pdf-viewer
      data-file="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>"
      data-pdfjs="<?= htmlspecialchars($pdfjsUrl, ENT_QUOTES) ?>"
      data-worker="<?= htmlspecialchars($workerUrl, ENT_QUOTES) ?>"
      data-label-page="<?= htmlspecialchars(t('Страница'), ENT_QUOTES) ?>"
      data-label-error="<?= htmlspecialchars(t('Не удалось открыть документ. Скачайте файл, чтобы прочитать его.'), ENT_QUOTES) ?>">
    <div class="pdf-viewer__bar">
        <h1 class="pdf-viewer__title"><?= htmlspecialchars($fileName, ENT_QUOTES) ?></h1>
        <span class="pdf-viewer__count" data-pdf-count></span>
        <span class="pdf-viewer__tools">
            <button type="button" class="pdf-viewer__btn" data-pdf-zoom="-1" aria-label="<?= htmlspecialchars(t('Уменьшить'), ENT_QUOTES) ?>" hidden><?= Icon::render('zoom-out', 20) ?></button>
            <button type="button" class="pdf-viewer__btn" data-pdf-zoom="1" aria-label="<?= htmlspecialchars(t('Увеличить'), ENT_QUOTES) ?>" hidden><?= Icon::render('zoom-in', 20) ?></button>
            <a class="pdf-viewer__btn pdf-viewer__btn--download" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" download>
                <?= Icon::render('download', 20) ?>
                <span><?= htmlspecialchars(t('Скачать'), ENT_QUOTES) ?></span>
            </a>
        </span>
    </div>
    <div class="pdf-viewer__pages" data-pdf-pages>
        <p class="pdf-viewer__status" data-pdf-status><?= htmlspecialchars(t('Загрузка документа…'), ENT_QUOTES) ?></p>
    </div>
</main>
<script type="module" src="<?= htmlspecialchars($viewerScript, ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/_footer.php'; ?>
