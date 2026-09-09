<?php

use App\Core\Locale;

/** @var array $albums */

$metaTitle = t('Фотоальбомы');
$metaDescription = t('Фотогалереи и альбомы мероприятий');
require __DIR__ . '/_header.php';
?>
<div class="content-list">
    <nav class="content-crumbs" aria-label="<?= htmlspecialchars(t('Хлебные крошки'), ENT_QUOTES) ?>">
        <a href="<?= htmlspecialchars(Locale::url('/'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Главная'), ENT_QUOTES) ?></a>
        <span>/</span>
        <span><?= htmlspecialchars(t('Фотоальбомы'), ENT_QUOTES) ?></span>
    </nav>

    <header class="content-list__head">
        <h1><?= htmlspecialchars(t('Фотоальбомы'), ENT_QUOTES) ?></h1>
    </header>

    <?php if (empty($albums)): ?>
        <p class="content-list__empty"><?= htmlspecialchars(t('Альбомов пока нет.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="albums-grid">
            <?php foreach ($albums as $album): ?>
                <a class="album-card" href="<?= htmlspecialchars(Locale::url('albums/' . $album['slug']), ENT_QUOTES) ?>">
                    <?php if ($album['cover'] !== ''): ?>
                        <?= \App\Core\Media::picture((string) $album['cover'], (string) $album['title'], null, null, 'album-card__img', true, '(max-width: 700px) 100vw, 33vw', false, 'album-card__cover') ?>
                    <?php else: ?>
                        <span class="album-card__cover album-card__cover--empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="album-card__body">
                        <h2 class="album-card__title"><?= htmlspecialchars((string) $album['title'], ENT_QUOTES) ?></h2>
                        <span class="album-card__meta"><?= (int) $album['images_count'] ?> <?= htmlspecialchars(t('фото'), ENT_QUOTES) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>