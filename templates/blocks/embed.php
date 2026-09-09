<?php

use App\Core\EmbedSource;
use App\Core\TitleMarkup;

/**
 * «Внешняя врезка»: ролик YouTube, пост Telegram или форма Google.
 *
 * Раньше вставить что-то из этого мог только супер-админ блоком «HTML».
 * Здесь редактор вставляет ссылку, а адрес для встраивания собирает
 * App\Core\EmbedSource по закрытому списку источников: произвольный iframe —
 * это чужой код на нашей странице, и такая дверь остаётся закрытой.
 *
 * Неопознанная ссылка не выводится вовсе: пустая рамка на странице выглядит
 * поломкой, а подсказать редактору можно только в админке.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$embed = EmbedSource::parse((string) $data['url']);
$ratio = (string) $data['ratio'];
$caption = (string) $data['caption'];

$templateCss = '';
if ($embed !== null && $ratio === 'fixed') {
    // Своя высота — переменной в scoped CSS: инлайн-стили в блоках запрещены.
    $templateCss = '#block-' . (int) $blockId . ' .block-embed__frame{--embed-height:'
        . (int) $data['height'] . 'px;}';
}

// Имя рамки для диктора: без него он объявляет «фрейм» и ничего больше.
$frameTitle = $title !== '' ? TitleMarkup::plain($title) : (string) ($embed['title'] ?? '');
?>
<?php // Неопознанная ссылка не выводится вовсе: посетителю сообщение
      // редактору не адресовано, а пустая рамка выглядит поломкой. Блок при
      // этом считается пустым — на сайте его нет, в предпросмотре видна
      // штатная заметка, а причину называет подсказка в форме (BlockHints). ?>
<?php if ($embed !== null): ?>
    <div class="block-embed">
        <?php if ($title !== ''): ?>
            <h2 class="section-head__title block-embed__title"><?= TitleMarkup::html($title) ?></h2>
        <?php endif; ?>
        <div class="block-embed__frame block-embed__frame--<?= htmlspecialchars($embed['provider'], ENT_QUOTES) ?> block-embed__frame--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?>">
            <iframe
                src="<?= htmlspecialchars($embed['src'], ENT_QUOTES) ?>"
                title="<?= htmlspecialchars($frameTitle, ENT_QUOTES) ?>"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="accelerometer; clipboard-write; encrypted-media; picture-in-picture"
                allowfullscreen></iframe>
        </div>
        <?php if ($caption !== ''): ?>
            <?php // Не figcaption: он допустим только внутри figure, а здесь
                  // рядом стоит заголовок секции. Стили берём у того же компонента. ?>
            <p class="media-caption block-embed__caption"><?= htmlspecialchars($caption, ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
