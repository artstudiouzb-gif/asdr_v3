<?php

use App\Core\DateFormatter;

/** @var array $data */
$news = $data['news'] ?? [];
$docs = $data['docs'] ?? [];
// Дата — единым числовым форматом на всех языках: 19.07.2026.
$fmt = static fn (string $d): string => DateFormatter::short($d);
?>
<div class="block-newsdocs">
    <div class="newsdocs-col">
        <div class="section-head">
            <?php if (!empty($data['news_title'])): ?><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html((string) $data['news_title']) ?></h2><?php endif; ?>
            <?php if (!empty($data['news_all_text']) && !empty($data['news_all_url'])): ?><a class="section-head__all" href="<?= htmlspecialchars((string) $data['news_all_url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $data['news_all_text'], ENT_QUOTES) ?> →</a><?php endif; ?>
        </div>
        <?php if (empty($news)): ?>
            <p class="block-newsdocs__empty"><?= htmlspecialchars(t('Новостей пока нет.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="newsdocs-news">
                <?php foreach ($news as $item): ?>
                    <a class="newsdocs-item" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                        <?php if (!empty($item['cover'])): ?>
                            <?= \App\Core\Media::picture((string) $item['cover'], (string) $item['title'], null, null, 'newsdocs-item__media', true, '(max-width: 700px) 100vw, 25vw') ?>
                        <?php else: ?>
                            <span class="newsdocs-item__media newsdocs-item__media--empty" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="newsdocs-item__body">
                            <?php if (!empty($item['published_at'])): ?><time class="newsdocs-item__date"><?= htmlspecialchars($fmt((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                            <span class="newsdocs-item__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="newsdocs-col">
        <div class="section-head">
            <?php if (!empty($data['docs_title'])): ?><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html((string) $data['docs_title']) ?></h2><?php endif; ?>
            <?php if (!empty($data['docs_all_text']) && !empty($data['docs_all_url'])): ?><a class="section-head__all" href="<?= htmlspecialchars((string) $data['docs_all_url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $data['docs_all_text'], ENT_QUOTES) ?> →</a><?php endif; ?>
        </div>
        <?php if (empty($docs)): ?>
            <p class="block-newsdocs__empty"><?= htmlspecialchars(t('Документы ещё не добавлены.'), ENT_QUOTES) ?></p>
        <?php else: ?>
            <div class="newsdocs-docs">
                <?php foreach ($docs as $doc): ?>
                    <?php $compact = true; include __DIR__ . '/partials/document_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
