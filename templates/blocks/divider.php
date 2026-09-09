<?php
/**
 * «Разделитель»: линия, знак или пустое место между секциями.
 *
 * Элемент один — `<hr>` — и он же нужен по существу: тематический разрыв это
 * ровно его роль, а диктор объявит его сам. Обычный `<div>` тут не годится не
 * только семантически: блок без текста и без картинки считается пустым
 * (`BlockRenderer::isVisuallyEmpty`) и на страницу не попадает вовсе.
 *
 * Расстояние вокруг линии и знака задают отступы секции в оформлении раздела —
 * второй настройки того же не заводим. Своя высота есть только у пустого
 * места: вешать её больше не на что.
 *
 * @var array $data
 * @var int $blockId
 */
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$size = (string) $data['size'];
$classes = 'block-divider block-divider--' . $variant
    . ($variant === 'space' ? ' block-divider--size-' . $size : '');

$templateCss = '';
if ($variant === 'emblem') {
    // Абсолютный адрес, а не var(--gov-emblem): переменная объявлена
    // относительным url и из другого файла разрешается не туда — знак просто
    // не рисуется, хотя правило на месте.
    $mask = 'url("' . \App\Core\BlockBackground::emblemUrl() . '") center / 28px 28px no-repeat';
    $templateCss = '#block-' . (int) $blockId . ' .block-divider--emblem{-webkit-mask:'
        . $mask . ';mask:' . $mask . ';}';
}
?>
<hr class="<?= $classes ?>">
