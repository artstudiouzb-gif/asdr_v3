<?php

use App\Core\Locale;
use App\Models\News;

/** @var array $data */
/** @var string $lang */
$items = $data['items'] ?? [];
$showThumb = !empty($data['show_thumb']);
?>
<ul class="widget-latest-news<?= $showThumb ? ' widget-latest-news--has-thumbs' : '' ?>">
    <?php foreach ($items as $item): ?>
        <li class="widget-latest-news__item">
            <?php if ($showThumb): ?>
                <?php $cover = News::getCoverImage($item); ?>
                <a class="widget-latest-news__thumb" href="<?= htmlspecialchars(Locale::url('news/' . $item['slug'], $lang), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($item['title'], ENT_QUOTES) ?>">
                    <?php if (!empty($cover)): ?>
                        <img src="<?= htmlspecialchars($cover, ENT_QUOTES) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="widget-latest-news__no-thumb"></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <div class="widget-latest-news__content">
                <a class="widget-latest-news__title" href="<?= htmlspecialchars(Locale::url('news/' . $item['slug'], $lang), ENT_QUOTES) ?>">
                    <?= htmlspecialchars($item['title'], ENT_QUOTES) ?>
                </a>
                <time class="widget-latest-news__date" datetime="<?= htmlspecialchars(substr((string) $item['published_at'], 0, 10), ENT_QUOTES) ?>"><?= htmlspecialchars(substr((string) $item['published_at'], 0, 10), ENT_QUOTES) ?></time>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><li class="widget-empty">Нет новостей.</li><?php endif; ?>
</ul>
