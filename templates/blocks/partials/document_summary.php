<?php

use App\Core\Media;

/**
 * Зона описания документа: фото обложки и текст рядом. Обложка стоит здесь, а
 * не на месте листа формата: лист сообщает, что за файл скачается, и пропадать
 * из-за фотографии ему нельзя — в ряду карточек с обложкой и без неё значок
 * формата иначе то был бы, то нет. Текст стоит колонкой рядом с фото, а не
 * обтекает его: обтекание оставляло под квадратом хвост из одного-двух слов.
 * Без описания фото встаёт под названием само.
 *
 * @var array{cover:string,description:string} $presented
 * @var string $summaryClass
 */
if ($presented['cover'] === '' && $presented['description'] === '') {
    return;
}
?>
<div class="doc-desc<?= $summaryClass !== '' ? ' ' . $summaryClass : '' ?><?= $presented['cover'] !== '' ? ' doc-desc--cover' : '' ?>">
    <?php if ($presented['cover'] !== ''): ?>
        <span class="doc-desc__cover" aria-hidden="true"><?= Media::picture($presented['cover'], '', null, null, 'doc-desc__img', true, '(max-width: 560px) 40vw, 160px') ?></span>
    <?php endif; ?>
    <?php if ($presented['description'] !== ''): ?><div class="doc-desc__text"><?= $presented['description'] ?></div><?php endif; ?>
</div>
