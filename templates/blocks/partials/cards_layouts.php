<?php
/*
 * Раскладки «Карточек», которые меняют композицию, а не оформление карточки:
 * «Ведущая карточка», «Указатель», «Знак на фоне» и «Панель». Поля у них те
 * же, что у «Иконки и текста» (иконка, заголовок, текст, ссылка), поэтому
 * переключение варианта ничего не теряет.
 *
 * Подключается из cards_grid.php: $variant, $items, $title, $description,
 * $allText, $allUrl, $safeLink, $scope и $templateCss уже есть.
 */

use App\Core\Icon;

$layoutClass = 'cards-' . $variant;
if ($variant === 'lead') {
    // Эмблема — маской абсолютным адресом: var(--gov-emblem) объявлена
    // относительным url и из файла блока разрешается не туда.
    $templateCss .= $scope . ' .cards-lead{--cards-emblem:url("' . \App\Core\BlockBackground::emblemUrl() . '");}';
}
$iconPx = ['lead' => 28, 'index' => 26, 'mark' => 150, 'panel' => 28][$variant];
$head = \App\Core\SectionHead::render([
    'title' => $title,
    'description' => $description,
    'description_html' => true,
    'all_text' => $allText,
    'all_url' => $allUrl,
]);
?>
    <div class="block-cards block-cards--layout <?= $layoutClass ?>-block">
        <?php if ($variant === 'panel'): ?><div class="cards-panel-box"><?php endif; ?>
        <?= $head ?>
        <?php if ($items === []): ?>
            <p class="block-cards__empty"><?= htmlspecialchars(t('Пункты ещё не добавлены.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="<?= $layoutClass ?>">
                <?php foreach ($items as $item): ?>
                    <?php
                    $url = $safeLink($item);
                    $icon = (string) ($item['icon_svg'] ?? '');
                    $tag = $url !== '' ? 'a' : 'div';
                    ?>
                    <<?= $tag ?> class="<?= $layoutClass ?>__item"<?= $url !== '' ? ' href="' . htmlspecialchars($url, ENT_QUOTES) . '"' : '' ?>>
                        <?php if ($icon !== ''): ?><span class="<?= $layoutClass ?>__icon" aria-hidden="true"><?= Icon::render($icon, $iconPx) ?></span><?php endif; ?>
                        <div class="<?= $layoutClass ?>__body">
                            <h3 class="<?= $layoutClass ?>__title"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?></h3>
                            <?php if (!empty($item['text'])): ?><p class="<?= $layoutClass ?>__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></p><?php endif; ?>
                        </div>
                        <?php if ($variant === 'index' && $url !== ''): ?><span class="cards-index__go" aria-hidden="true"><?= Icon::render('chevron-right', 22) ?></span><?php endif; ?>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($variant === 'panel'): ?></div><?php endif; ?>
    </div>
