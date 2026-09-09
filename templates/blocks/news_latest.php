<?php
/** @var array $data */
/** @var int $blockId */
$news = is_array($data['news'] ?? null) ? $data['news'] : [];
// Шапка секции — общая для всех блоков: ссылка «Все новости» больше не
// привязана к заголовку и остаётся на месте у безымянной секции.
$head = \App\Core\SectionHead::render([
    'title' => (string) ($data['title'] ?? ''),
    'all_text' => (string) ($data['all_text'] ?? ''),
    'all_url' => (string) ($data['all_url'] ?? ''),
    'class' => 'block-news__head',
    // Легаси-класс сохраняем: на нём висят правила статичной темы.
    'title_class' => 'block-news__title',
]);
$templateCss = \App\Core\GridBalance::css($blockId, '.block-news__grid', '.news-card', count($news), 3);
?>
<div class="block-news">
    <?= $head ?>
    <?php if (empty($news)): ?>
        <p class="block-news__empty"><?= htmlspecialchars(t('Новостей пока нет.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="block-news__grid">
            <?php foreach ($news as $item): ?>
                <article class="news-card">
                    <a class="news-card__link" href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>">
                        <?php if (!empty($item['cover'])): ?>
                            <span class="news-card__cover"><?= \App\Core\Media::picture((string) $item['cover'], (string) $item['title'], null, null, '', true, '(max-width: 700px) 100vw, 33vw') ?></span>
                        <?php else: ?>
                            <span class="news-card__cover news-card__cover--empty" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="news-card__body">
                            <?php // Дата и рубрика — одной строкой, как в ленте новостей. ?>
                            <?php if (!empty($item['published_at']) || trim((string) ($item['category'] ?? '')) !== ''): ?>
                                <span class="news-meta">
                                    <?php if (!empty($item['published_at'])): ?>
                                        <time class="news-card__date" datetime="<?= htmlspecialchars(substr((string) $item['published_at'], 0, 10), ENT_QUOTES) ?>"><?= htmlspecialchars(\App\Core\DateFormatter::short((string) $item['published_at']), ENT_QUOTES) ?></time>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($item['category'] ?? '')) !== ''): ?>
                                        <span class="news-category"><?= htmlspecialchars((string) $item['category'], ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span class="news-card__title"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></span>
                            <?php if (!empty($item['excerpt'])): ?>
                                <span class="news-card__excerpt"><?= htmlspecialchars(excerpt((string) $item['excerpt'], 180), ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
