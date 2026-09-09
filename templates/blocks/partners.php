<?php

use App\Core\Icon;

/** @var array $data */
/** @var int $blockId */
// Значения проверены схемой полей (BlockFieldSchema) — здесь они читаются
// как есть, второй копии списков в шаблоне нет.
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$variant = (string) $data['variant'];
$columns = (int) $data['columns'];
$logoSize = (string) $data['logo_size'];
$grayscale = !empty($data['grayscale']);
$autoplay = (int) $data['autoplay'];
// Бегущей строке нужен хотя бы один повтор набора, иначе в шве видна пустота;
// с двумя логотипами лента выглядит как мигание, поэтому от трёх.
$marquee = $variant === 'marquee' && count($items) >= 3;
$carousel = !$marquee && count($items) > 1;
// В полосу сетка превращается, когда логотипов больше, чем помещается в ряд.
$desktopCarousel = !$marquee && count($items) > $columns;

// Число колонок и скорость ленты — в scoped CSS: инлайн-стили в блоках
// запрещены тестами.
if ($marquee) {
    // Скорость ленты — от числа логотипов: с одним и тем же временем полного
    // круга длинный набор летел бы, а короткий полз.
    $templateCss = '#block-' . (int) $blockId . ' .block-partners__marquee-track{'
        . '--partners-marquee-time:' . (count($items) * 6) . 's}';
} else {
    $templateCss = '@media (min-width:721px){#block-' . (int) $blockId
        . ' .block-partners__grid{--partners-cols:' . $columns . '}}';
}

$navHtml = '';
if ($carousel) {
    ob_start(); ?>
    <span class="carousel-nav" data-carousel-nav hidden>
        <button type="button" class="carousel-nav__btn" data-carousel-prev aria-label="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>"><?= Icon::render('chevron-left', 18) ?></button>
        <span class="carousel-nav__dots" data-carousel-dots role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>"></span>
        <button type="button" class="carousel-nav__btn" data-carousel-next aria-label="<?= htmlspecialchars(t('Вперёд'), ENT_QUOTES) ?>"><?= Icon::render('chevron-right', 18) ?></button>
    </span>
    <?php $navHtml = (string) ob_get_clean();
}

$head = \App\Core\SectionHead::render([
    'title' => (string) ($data['title'] ?? ''),
    'description' => (string) ($data['description'] ?? ''),
    'all_text' => (string) ($data['all_text'] ?? ''),
    'all_url' => (string) ($data['all_url'] ?? ''),
    'tools' => $navHtml,
    'title_class' => 'block-partners__title',
]);
?>
<div class="block-partners block-partners--logo-<?= htmlspecialchars($logoSize, ENT_QUOTES) ?><?= $grayscale ? ' block-partners--grayscale' : '' ?><?= $marquee ? ' block-partners--marquee' : '' ?>"<?= $carousel ? ' data-carousel' : '' ?><?= $carousel && $autoplay > 0 ? ' data-carousel-autoplay="' . $autoplay . '"' : '' ?>>
    <?= $head ?>
    <?php if (!empty($items)): ?>
        <?php // Лента едет непрерывно, поэтому набор логотипов выводится дважды:
              // копия догоняет оригинал в момент, когда он ушёл влево на свою
              // ширину, и шов не виден. Копия — для глаз, поэтому она скрыта от
              // диктора, а сама дорожка объявлена списком партнёров.
              // Прокрутить ленту можно и рукой: под курсором она стоит. ?>
        <?php if ($marquee): ?>
        <div class="block-partners__marquee" tabindex="0" role="group" aria-label="<?= htmlspecialchars(t('Партнёры'), ENT_QUOTES) ?>">
            <div class="block-partners__marquee-track">
        <?php else: ?>
        <div class="block-partners__grid<?= $desktopCarousel ? ' block-partners__grid--carousel' : '' ?>"<?= $carousel ? ' data-carousel-track tabindex="0" role="group" aria-label="' . htmlspecialchars(t('Партнёры — прокрутка вбок'), ENT_QUOTES) . '"' : '' ?>>
        <?php endif; ?>
            <?php foreach ($marquee ? array_merge($items, $items) : $items as $index => $p): ?>
                <?php
                $logo = trim((string) ($p['logo'] ?? ''));
                $nameRaw = (string) ($p['name'] ?? '');
                $name = htmlspecialchars($nameRaw, ENT_QUOTES);
                $url = (string) ($p['url'] ?? '');
                if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                    $url = '';
                }
                // Без логотипа показываем название текстом: пустой <img src="">
                // — это битая картинка, а имя партнёра было видно только во
                // всплывающей подсказке.
                $img = $logo !== ''
                    ? \App\Core\Media::picture($logo, $nameRaw, null, null, 'block-partners__logo', true, '180px')
                    : '<span class="block-partners__name">' . ($name !== '' ? $name : htmlspecialchars(t('Партнёр'), ENT_QUOTES)) . '</span>';
                ?>
                <?php $copy = $marquee && $index >= count($items); ?>
                <?php if ($url !== '' && !$copy): ?>
                    <a class="block-partners__item"<?= $carousel ? ' data-carousel-item' : '' ?> href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" target="_blank" rel="noopener" title="<?= $name ?>"><?= $img ?></a>
                <?php else: ?>
                    <span class="block-partners__item"<?= $carousel ? ' data-carousel-item' : '' ?><?= $copy ? ' aria-hidden="true"' : '' ?> title="<?= $name ?>"><?= $img ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php if ($marquee): ?>
            </div>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
