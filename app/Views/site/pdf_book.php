<?php

use App\Core\AssetCollector;
use App\Core\Icon;

/**
 * PDF книгой с листанием (см. Site\PdfController, вид `book`). Книгу собирает
 * `pdf-book.js`; без JavaScript здесь остаются ссылки на сам файл — открыть
 * его как документ и скачать.
 *
 * @var string $fileUrl
 * @var string $title      название документа из блока «Документы»
 * @var string $backUrl    страница, где стоит документ; пусто — не нашлась
 * @var string $backTitle
 * @var string $pdfjsUrl
 * @var string $workerUrl
 * @var string $flipUrl
 * @var string $bookScript
 */

$metaTitle = $title;
$robotsNoindex = true;
AssetCollector::requireCss('pdf-book', '/assets/css/blocks/pdf-book.css');
require __DIR__ . '/_header.php';
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
?>
<main class="pdf-book" id="main-content"
      data-pdf-book
      data-file="<?= $e($fileUrl) ?>"
      data-pdfjs="<?= $e($pdfjsUrl) ?>"
      data-worker="<?= $e($workerUrl) ?>"
      data-label-page="<?= $e(t('Страница %1 из %2')) ?>"
      data-label-pages="<?= $e(t('Страницы %1–%2 из %3')) ?>"
      data-label-thumb="<?= $e(t('Страница %1')) ?>"
      data-label-fullscreen="<?= $e(t('Во весь экран')) ?>"
      data-label-fullscreen-exit="<?= $e(t('Выйти из полного экрана')) ?>"
      data-label-error="<?= $e(t('Не удалось открыть документ. Скачайте файл, чтобы прочитать его.')) ?>">
    <div class="pdf-book__bar">
        <?php /* Книга открывается в той же вкладке, поэтому дорога обратно — в шапке.
                 Страница не нашлась — ссылку покажет скрипт, если пришли со своего сайта. */ ?>
        <a class="pdf-book__btn" href="<?= $e($backUrl !== '' ? $backUrl : '#') ?>" data-book-back<?= $backUrl === '' ? ' hidden' : '' ?><?= $backTitle !== '' ? ' title="' . $e($backTitle) . '"' : '' ?>>
            <?= Icon::render('arrow-left', 20) ?>
            <span><?= $e(t('Назад')) ?></span>
        </a>
        <h1 class="pdf-book__title"><?= $e($title) ?></h1>
        <span class="pdf-book__tools">
            <?php /* Книга — картинки страниц: текст выделяют, ищут и читают диктором в документе. */ ?>
            <a class="pdf-book__btn" href="<?= $e($fileUrl) ?>" target="_blank" rel="noopener">
                <?= Icon::render('file-text', 20) ?>
                <span><?= $e(t('Открыть как документ')) ?></span>
            </a>
            <a class="pdf-book__btn pdf-book__btn--strong" href="<?= $e($fileUrl) ?>" download>
                <?= Icon::render('download', 20) ?>
                <span><?= $e(t('Скачать')) ?></span>
            </a>
        </span>
    </div>

    <div class="pdf-book__desk">
        <p class="pdf-book__status" data-book-status><?= $e(t('Загрузка документа…')) ?></p>
        <div class="pdf-book__stage" data-book-stage hidden>
            <div class="pdf-book__shift" data-book-shift>
                <span class="pdf-book__edge pdf-book__edge--left" data-book-edge="left"></span>
                <span class="pdf-book__edge pdf-book__edge--right" data-book-edge="right"></span>
                <div class="pdf-book__flip" data-book></div>
            </div>
        </div>
    </div>

    <div class="pdf-book__nav" data-book-nav hidden>
        <button type="button" class="pdf-book__btn" data-book-prev aria-label="<?= $e(t('Предыдущая страница')) ?>"><?= Icon::render('chevron-left', 20) ?></button>
        <span class="pdf-book__count" data-book-count aria-live="polite"></span>
        <button type="button" class="pdf-book__btn" data-book-next aria-label="<?= $e(t('Следующая страница')) ?>"><?= Icon::render('chevron-right', 20) ?></button>
        <input type="range" class="pdf-book__slider" data-book-slider min="1" max="1" value="1" aria-label="<?= $e(t('Перейти к странице')) ?>">
        <button type="button" class="pdf-book__btn" data-book-zoom-open>
            <?= Icon::render('zoom-in', 20) ?>
            <span><?= $e(t('Лупа')) ?></span>
        </button>
        <button type="button" class="pdf-book__btn" data-book-fullscreen hidden>
            <?= Icon::render('maximize', 20) ?>
            <span data-book-fullscreen-label><?= $e(t('Во весь экран')) ?></span>
        </button>
    </div>
    <div class="pdf-book__thumbs" data-book-thumbs role="group" aria-label="<?= $e(t('Страницы документа')) ?>" hidden></div>

    <div class="pdf-book__zoom" data-book-zoom role="dialog" aria-modal="true" aria-label="<?= $e(t('Лупа')) ?>" hidden>
        <div class="pdf-book__zoom-bar">
            <span class="pdf-book__count" data-zoom-count></span>
            <button type="button" class="pdf-book__btn" data-zoom-step="-1" aria-label="<?= $e(t('Уменьшить')) ?>"><?= Icon::render('zoom-out', 20) ?></button>
            <button type="button" class="pdf-book__btn" data-zoom-step="1" aria-label="<?= $e(t('Увеличить')) ?>"><?= Icon::render('zoom-in', 20) ?></button>
            <button type="button" class="pdf-book__btn" data-zoom-close>
                <?= Icon::render('x', 20) ?>
                <span><?= $e(t('Закрыть')) ?></span>
            </button>
        </div>
        <div class="pdf-book__zoom-scroll" data-zoom-scroll><div class="pdf-book__zoom-spread" data-zoom-spread></div></div>
    </div>
</main>
<?php /* Листание — классический скрипт с глобальным St; оба отложены и идут по порядку. */ ?>
<script defer src="<?= $e($flipUrl) ?>"></script>
<script type="module" src="<?= $e($bookScript) ?>"></script>
<?php require __DIR__ . '/_footer.php'; ?>
