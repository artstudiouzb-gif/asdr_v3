<?php

use App\Core\Icon;

/**
 * «Кнопки»: до трёх действий в ряд, без карточки и заголовка.
 *
 * Отличие от «Призыва к действию»: тот рисует врезку с заголовком, текстом и
 * одной кнопкой на своей подложке. Здесь — только ряд кнопок под текстом
 * страницы («Скачать бланк», «Подать заявление»).
 *
 * @var array $data
 * @var int $blockId
 */
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$align = (string) $data['align'];
$size = (string) $data['size'];
$items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
?>
<?php if ($items !== []): ?>
    <div class="block-buttons block-buttons--<?= htmlspecialchars($align, ENT_QUOTES) ?> block-buttons--size-<?= htmlspecialchars($size, ENT_QUOTES) ?>">
        <?php foreach ($items as $item):
            $icon = (string) ($item['icon_svg'] ?? '');
            $newTab = !empty($item['new_tab']);
        ?>
            <?php // Класса .btn здесь нет намеренно: общее правило темы красит
                  // его в navy через !important и не задаёт отступов, поэтому
                  // «контурная» и «ссылкой» превратились бы в ту же заливку.
                  // Вид «основная» повторяет ту же кнопку своими правилами. ?>
            <a class="block-buttons__btn block-buttons__btn--<?= htmlspecialchars((string) $item['style'], ENT_QUOTES) ?>"
               href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>"
               <?php // Внешняя вкладка без rel — приглашение подменить нашу страницу
                     // через window.opener. ?>
               <?= $newTab ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?php if ($icon !== ''): ?><span class="block-buttons__icon" aria-hidden="true"><?= Icon::render($icon, 18) ?></span><?php endif; ?>
                <span class="block-buttons__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span>
                <?php if ($newTab): ?><span class="visually-hidden"><?= htmlspecialchars(t('Откроется в новой вкладке'), ENT_QUOTES) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
