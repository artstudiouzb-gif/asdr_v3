<?php

use App\Core\DocumentPresenter;
use App\Core\Icon;
use App\Core\Media;

/**
 * Лицо документа: обложка, если её загрузили (первая страница издания,
 * титул отчёта), иначе лист с подписью формата. Лист нарисован разметкой:
 * подпись «PDF» на цветной плашке читается с первого взгляда, а значок
 * формата в 24px различался только цветом. Ни то ни другое диктору не
 * адресовано — формат он слышит в подписи кнопки.
 *
 * @var array{cover:string,extension:string} $presented
 */
$visualFamily = DocumentPresenter::family($presented['extension']);
?>
<?php if ($presented['cover'] !== ''): ?>
    <span class="doc-visual doc-visual--cover" aria-hidden="true"><?= Media::picture($presented['cover'], '', null, null, 'doc-visual__img', true, '(max-width: 560px) 100vw, 280px') ?></span>
<?php else: ?>
    <span class="doc-visual doc-sheet doc-sheet--<?= $visualFamily ?>" aria-hidden="true">
        <?php if ($visualFamily === 'other'): ?>
            <?= Icon::render($presented['extension'] === 'other' ? 'link' : 'file-text', 22, 'doc-sheet__icon', 1.6) ?>
        <?php else: ?>
            <span class="doc-sheet__label"><?= htmlspecialchars(strtoupper($presented['extension']), ENT_QUOTES) ?></span>
        <?php endif; ?>
    </span>
<?php endif; ?>
