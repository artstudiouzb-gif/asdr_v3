<?php

use App\Core\DocumentPresenter;
use App\Core\Media;

/**
 * Карточка правового акта: номер и дата вынесены в шапку, ниже название и
 * описание, внизу файлы — на языке страницы и переводы. Отличается от обычной
 * карточки документа тем, что у акта есть реквизиты, по которым его и ищут.
 *
 * Обложка у акта — не врезка в описание, а левая колонка во всю высоту
 * карточки: у изданий и отчётов титул и есть то, по чему их узнают, а
 * квадрат 112px рядом с абзацем читался заплаткой. Лист формата при этом
 * остаётся в шапке справа — он говорит, что скачается. В узкой карточке
 * (телефон, колонка конструктора) фото встаёт сверху, это решает
 * контейнерный запрос по ширине самой карточки.
 *
 * @var array $doc
 */
$presented = DocumentPresenter::prepare($doc);
$number = trim((string) ($doc['number'] ?? ''));
$date = trim((string) ($doc['date'] ?? ''));
$editorialAct = !empty($editorialAct);
$showEmblem = !isset($showEmblem) || (bool) $showEmblem;
$hasMedia = $presented['cover'] !== '';
?>
<article
    class="feature-card act-card<?= $number === '' ? ' act-card--no-number' : '' ?><?= $hasMedia ? ' act-card--media' : '' ?>"
    data-document-card<?= !empty($carouselItem) ? ' data-carousel-item' : '' ?>
    data-document-kind="<?= htmlspecialchars($presented['extension'], ENT_QUOTES) ?>"
    data-document-search="<?= htmlspecialchars(trim($presented['search'] . ' ' . mb_strtolower($number . ' ' . $date)), ENT_QUOTES) ?>"
>
    <?php if ($showEmblem): ?><span class="act-card__emblem" aria-hidden="true"></span><?php endif; ?>
    <?php if ($hasMedia): ?>
        <?php /* Название рядом говорит то же, что титул: alt пустой. */ ?>
        <div class="act-card__frame">
        <span class="act-card__media" aria-hidden="true"><?= Media::picture($presented['cover'], '', null, null, 'act-card__img', true, '(max-width: 560px) 100vw, 320px') ?></span>
        <div class="act-card__main">
    <?php endif; ?>
    <?php /* Шапка карточки — лист формата и рядом название с реквизитами:
             взгляд сразу связывает «что за файл» с «что за документ». Номер
             по порядку (01, 02) снят: документы раздела — не последовательность,
             а цифра в углу спорила с названием за первый взгляд. */ ?>
    <div class="act-card__editorial-head">
        <?php include __DIR__ . '/document_visual.php'; ?>
        <div class="act-card__heading">
            <?php if ($number !== '' || $date !== ''): ?>
                <span class="act-card__head">
                    <?php if ($number !== ''): ?><span class="feature-card__title act-card__number"><?= htmlspecialchars($number, ENT_QUOTES) ?></span><?php endif; ?>
                    <?php if ($date !== ''): ?><span class="act-card__date"><?= htmlspecialchars($date, ENT_QUOTES) ?></span><?php endif; ?>
                </span>
            <?php endif; ?>
            <?php /* Без номера акта название и есть заголовок карточки: у изданий и
                     отчётов реквизитов нет, и обычным текстом оно терялось. */ ?>
            <p class="feature-card__text act-card__desc"><?= htmlspecialchars($presented['title'], ENT_QUOTES) ?></p>
        </div>
    </div>
    <?php $summaryClass = 'act-card__summary'; $summaryCover = !$hasMedia; include __DIR__ . '/document_summary.php'; ?>
    <?php /* У акта без файла подвал пуст: его линия висела бы под названием
             как обрыв карточки, поэтому партиал без файлов не печатает ничего. */ ?>
    <?php $filesClass = 'act-card__foot'; include __DIR__ . '/document_files.php'; ?>
    <?php if ($hasMedia): ?></div></div><?php endif; ?>
</article>
