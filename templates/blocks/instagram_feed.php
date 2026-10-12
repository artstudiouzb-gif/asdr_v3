<?php

/**
 * «Лента Instagram»: последние публикации аккаунта ведомства.
 *
 * Посты приходят из сохранённой копии (`InstagramFeed`), а не из Instagram:
 * публичная страница в сеть не ходит. Кадры лежат в медиатеке, поэтому
 * уменьшенные копии и ленивая загрузка — те же, что у любой картинки сайта.
 *
 * Пустая лента не выводится вовсе: рамка с одним заголовком читается как
 * поломка, а что лента не подключена, редактору говорит подсказка в форме.
 *
 * @var array $data
 * @var int $blockId
 */
$posts = is_array($data['posts'] ?? null) ? $data['posts'] : [];
$columns = (int) $data['columns'];
$captionLines = (int) $data['caption_lines'];
$templateCss = '#block-' . (int) $blockId . ' .ig-feed{--ig-cols:' . $columns . ';--ig-caption-lines:' . $captionLines . ';}';
// Случайный выбор: сервер отдаёт всю сохранённую копию и прячет кадры сверх
// лимита, перемешивает их скрипт. Без него видны последние — тоже честно.
$igShuffle = $data['order'] === 'random' ? (int) $data['limit'] : 0;
$igDates = !empty($data['dates']);
$ratio = (string) $data['ratio'];
// Карусель — раскладка «Карусель» и любой один кадр в ряду: столбец из
// восьми кадров во всю ширину колонки не листают, а прокручивают мимо.
// Считается по видимым кадрам — один кадр листать некуда.
$igVisible = $igShuffle > 0 ? min($igShuffle, count($posts)) : count($posts);
$igCarousel = $igVisible > 1 && ($data['layout'] === 'row' || $columns === 1);
$layout = $igCarousel ? 'row' : (string) $data['layout'];
// Кадр в одну колонку — во всю её ширину (до 640px), а не 240px сетки.
$igSizes = $columns === 1 ? '(max-width: 720px) 92vw, 480px' : null;
?>
<?php if ($posts !== []): ?>
    <div class="ig-feed ig-feed--<?= htmlspecialchars($layout, ENT_QUOTES) ?> ig-feed--cols-<?= $columns ?> ig-feed--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?>"<?= $igCarousel ? ' data-carousel' : '' ?>>
        <?= \App\Core\SectionHead::render([
            'title' => (string) ($data['title'] ?? ''),
            'all_text' => (string) $data['all_text'],
            'all_url' => (string) ($data['profile_url'] ?? ''),
            'class' => 'ig-feed__head',
        ]) ?>
        <?php require __DIR__ . '/partials/instagram_grid.php'; ?>
        <?php if ($igCarousel): ?>
            <div class="ig-feed__nav"><?php include __DIR__ . '/partials/carousel_nav.php'; ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
