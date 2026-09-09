<?php
/** @var array $data */
$title = $data['title'] ?? '';
$description = trim(\App\Core\HtmlSanitizer::sanitizeText((string) ($data['description'] ?? '')));
$items = $data['items'] ?? [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];

// Шапка секции — общая: заголовок, вводный текст и ссылка «Все …».
$head = \App\Core\SectionHead::render([
    'title' => (string) $title,
    'description' => $description,
    'description_html' => true,
    'all_text' => (string) ($data['all_text'] ?? ''),
    'all_url' => (string) ($data['all_url'] ?? ''),
    'class' => $variant === 'band' ? 'block-featband__head' : 'block-advantages__head',
    // Легаси-классы сохраняем: на них висят правила темы.
    'title_class' => $variant === 'band' ? 'block-featband__title' : 'block-advantages__title',
    'description_class' => $variant === 'band' ? 'block-featband__description' : 'block-advantages__description',
]);

if ($variant !== 'band') {
    // Колонки задаёт редактор; 0 — прежнее поведение: до пяти карточек один
    // ряд (пять направлений идут пятёркой, как на макете), дальше число
    // подбирается так, чтобы в последнем ряду не осталась одинокая карточка.
    // Карточки хвоста растягиваются на всю ширину — иначе справа зияет пустая
    // ячейка.
    $count = count($items);
    $columns = (int) $data['columns'];
    if ($columns === 0) {
        $columns = $count <= 5 ? $count : \App\Core\GridBalance::columnsFor($count);
    }
    $templateCss = \App\Core\GridBalance::css(
        $blockId,
        '.block-advantages__grid',
        '.block-advantages__item',
        $count,
        $columns
    );
}
?>
<?php if ($variant === 'band'): ?>
<div class="block-featband">
    <?= $head ?>
    <?php if (empty($items)): ?>
        <p class="block-featband__empty"><?= htmlspecialchars(t('Элементы ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="featband">
            <?php foreach ($items as $item): ?>
                <div class="featband__item">
                    <?php if (!empty($item['icon_svg'])): ?><span class="featband__icon" aria-hidden="true"><?= \App\Core\Icon::render($item['icon_svg'], 28) ?></span><?php endif; ?>
                    <span class="featband__name"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></span>
                    <?php if (!empty($item['text'])): ?><span class="featband__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="block-advantages block-advantages--<?= htmlspecialchars($variant, ENT_QUOTES) ?>">
    <?= $head ?>
    <div class="block-advantages__grid">
        <?php foreach ($items as $index => $item): ?>
            <?php
            $itemUrl = trim((string) ($item['url'] ?? ''));
            if ($itemUrl !== '' && !\App\Core\UrlGuard::isSafeLink($itemUrl)) {
                $itemUrl = '';
            }
            // Карточка со ссылкой кликается целиком — отдельная подпись
            // «Подробнее» съедала бы высоту и дублировала заголовок.
            $itemTag = $itemUrl !== '' ? 'a' : 'article';
            ?>
            <<?= $itemTag ?> class="feature-card block-advantages__item<?= $itemUrl !== '' ? ' block-advantages__item--link' : '' ?>"<?= $itemUrl !== '' ? ' href="' . htmlspecialchars($itemUrl, ENT_QUOTES) . '"' : '' ?>>
                <?php // Вариант «в строку»: иконка и заголовок стоят рядом, номер
                      // уходит вправо. В остальных вариантах иконка занимает
                      // отдельную строку над заголовком. ?>
                <div class="feature-card__top">
                    <?php if (!empty($item['icon_svg'])): ?>
                        <span class="feature-card__icon block-advantages__icon block-advantages__icon--svg" aria-hidden="true"><?= \App\Core\Icon::render($item['icon_svg'], 22) ?></span>
                    <?php else: ?>
                        <span class="feature-card__spacer"></span>
                    <?php endif; ?>
                    <?php if ($variant === 'inline'): ?>
                        <h3 class="feature-card__title"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                    <?php endif; ?>
                    <span class="feature-card__num block-advantages__index" aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                </div>
                <?php if ($variant !== 'inline'): ?>
                    <h3 class="feature-card__title"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                <?php endif; ?>
                <p class="feature-card__text"><?= htmlspecialchars($item['text'] ?? '', ENT_QUOTES) ?></p>
            </<?= $itemTag ?>>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
