<?php

use App\Core\BlockRenderer;

/** @var array $data */
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$auto = !empty($data['auto']);
$sticky = !empty($data['sticky']);

// Автоматическое оглавление: разделами страницы становятся блоки с
// заголовком, якорем — id их секции. Ручные пункты не теряются: они идут
// первыми, собранные добавляются следом.
if ($auto) {
    foreach (BlockRenderer::pageSections() as $section) {
        $items[] = ['label' => $section['label'], 'url' => '#' . $section['id']];
    }
}
?>
<?php if (!empty($items)): ?>
<nav class="block-anchornav<?= $sticky ? ' block-anchornav--sticky' : '' ?>"
     data-anchor-nav aria-label="<?= htmlspecialchars(t('Разделы страницы'), ENT_QUOTES) ?>">
    <?php foreach ($items as $item): ?>
        <a class="block-anchornav__link" href="<?= htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
