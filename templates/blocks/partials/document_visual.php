<?php

use App\Core\DocumentPresenter;
use App\Core\Icon;

/**
 * Лицо документа — лист с подписью формата, и он есть всегда: обложка
 * встаёт в зону описания (document_summary.php). Лист нарисован разметкой:
 * подпись «PDF» на цветной плашке читается с первого взгляда, а значок
 * формата в 24px различался только цветом. Ни то ни другое диктору не
 * адресовано — формат он слышит в подписи кнопки.
 *
 * @var array{extension:string} $presented
 */
$visualFamily = DocumentPresenter::family($presented['extension']);
?>
<span class="doc-visual doc-sheet doc-sheet--<?= $visualFamily ?>" aria-hidden="true">
    <?php if ($visualFamily === 'other'): ?>
        <?= Icon::render($presented['extension'] === 'other' ? 'link' : 'file-text', 22, 'doc-sheet__icon', 1.6) ?>
    <?php else: ?>
        <span class="doc-sheet__label"><?= htmlspecialchars(strtoupper($presented['extension']), ENT_QUOTES) ?></span>
    <?php endif; ?>
</span>
