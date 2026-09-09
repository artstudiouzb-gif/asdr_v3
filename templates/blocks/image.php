<?php

use App\Core\Media;
use App\Core\TitleMarkup;

/**
 * «Изображение»: один снимок с подписью и указанием источника.
 *
 * Раньше одиночной картинки было нечем поставить: «Текст с фото» требует
 * текста, «Медиагалерея» — сетка, «Слайдер» — карусель.
 *
 * Подпись и источник рисует общий компонент `.media-caption` — тот же, что у
 * галереи новости и у альбома: третий набор правил под ту же задачу разъехался
 * бы с ними при первой правке.
 *
 * Клик делает что-то одно: если задана ссылка, она и срабатывает, а увеличение
 * отключается. Два действия на один клик редактор всё равно не разведёт, а
 * посетитель не угадает, какое из них он получит.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$image = (string) $data['image'];
$alt = (string) $data['alt'];
$caption = (string) $data['caption'];
$credit = (string) $data['credit'];
$link = (string) $data['link'];
$zoom = !empty($data['zoom']) && $link === '';
$width = (string) $data['width'];
$ratio = (string) $data['ratio'];

$picture = Media::picture(
    $image,
    $alt,
    null,
    null,
    'block-image__img',
    true,
    $width === 'reading' ? '(max-width: 800px) 100vw, 760px' : '(max-width: 1100px) 100vw, 1100px'
);

$figureClass = 'block-image'
    . ' block-image--w-' . $width
    . ' block-image--ratio-' . $ratio
    . ($zoom ? ' block-image--zoomable' : '');
?>
<?php if ($picture === ''): ?>
    <p class="block-image__empty"><?= htmlspecialchars(t('Изображение не выбрано.'), ENT_QUOTES) ?></p>
<?php else: ?>
    <?php if ($title !== ''): ?>
        <h2 class="section-head__title block-image__title"><?= TitleMarkup::html($title) ?></h2>
    <?php endif; ?>
    <figure class="<?= $figureClass ?>">
        <?php if ($link !== ''): ?>
            <a class="block-image__frame" href="<?= htmlspecialchars($link, ENT_QUOTES) ?>"><?= $picture ?></a>
        <?php elseif ($zoom): ?>
            <?php // Лайтбокс общий: он ищет ссылку на файл внутри известного
                  // контейнера, поэтому увеличение — это ссылка на сам снимок. ?>
            <a class="block-image__frame" href="<?= htmlspecialchars($image, ENT_QUOTES) ?>"><?= $picture ?></a>
        <?php else: ?>
            <span class="block-image__frame"><?= $picture ?></span>
        <?php endif; ?>
        <?php if ($caption !== '' || $credit !== ''): ?>
            <figcaption class="media-caption">
                <?= htmlspecialchars($caption, ENT_QUOTES) ?>
                <?php if ($credit !== ''): ?>
                    <span class="media-caption__credit"><?= htmlspecialchars(t('Фото:'), ENT_QUOTES) ?> <?= htmlspecialchars(Media::photoCredit($credit), ENT_QUOTES) ?></span>
                <?php endif; ?>
            </figcaption>
        <?php endif; ?>
    </figure>
<?php endif; ?>
