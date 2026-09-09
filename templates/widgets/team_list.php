<?php
/** @var array $data */
$items = $data['items'] ?? [];
?>
<ul class="widget-team">
    <?php foreach ($items as $item): ?>
        <li>
            <?php if (!empty($item['photo'])): ?>
                <?= \App\Core\Media::picture((string) $item['photo'], (string) $item['name'], null, null, '', true, '80px') ?>
            <?php endif; ?>
            <div>
                <strong><?= htmlspecialchars($item['name'], ENT_QUOTES) ?></strong>
                <?php if (!empty($item['position'])): ?><span><?= htmlspecialchars($item['position'], ENT_QUOTES) ?></span><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><li class="widget-empty">Список пуст.</li><?php endif; ?>
</ul>
