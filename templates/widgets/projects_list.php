<?php
/** @var array $data */
$items = $data['items'] ?? [];
?>
<ul class="widget-projects">
    <?php foreach ($items as $item): ?>
        <li>
            <?php if (!empty($item['cover_image'])): ?>
                <?= \App\Core\Media::picture((string) $item['cover_image'], (string) $item['title'], null, null, '', true, '96px') ?>
            <?php endif; ?>
            <span><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></span>
        </li>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><li class="widget-empty">Нет проектов.</li><?php endif; ?>
</ul>
