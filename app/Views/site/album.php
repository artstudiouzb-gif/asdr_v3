<?php

use App\Core\Locale;

/** @var array $album */
/** @var array $images */

$metaTitle = (string) $album['title'];
$metaDescription = mb_substr(trim((string) ($album['description'] ?? '')), 0, 160);
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Фотоальбомы'), 'url' => Locale::url('albums')],
    ['label' => (string) $album['title']],
];
?>
<div class="content-list">
    <?php require __DIR__ . '/_crumbs.php'; ?>

    <header class="content-list__head">
        <h1><?= htmlspecialchars((string) $album['title'], ENT_QUOTES) ?></h1>
        <?php if (!empty($album['description'])): ?>
            <p class="content-list__lead"><?= nl2br(htmlspecialchars((string) $album['description'], ENT_QUOTES)) ?></p>
        <?php endif; ?>
    </header>

    <?php if (empty($images)): ?>
        <p class="content-list__empty"><?= htmlspecialchars(t('В альбоме пока нет фотографий.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="album-photos">
            <?php foreach ($images as $img): ?>
                <figure class="album-photo">
                    <a href="<?= htmlspecialchars((string) $img['image_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                        <?= \App\Core\Media::picture((string) $img['image_url'], (string) ($img['caption'] ?: $album['title']), null, null, '', true, '(max-width: 700px) 100vw, 33vw') ?>
                    </a>
                    <?php $imgCredit = trim((string) ($img['credit'] ?? '')); ?>
                    <?php if ($img['caption'] !== '' || $imgCredit !== ''): ?>
                        <figcaption class="media-caption">
                            <?= htmlspecialchars((string) $img['caption'], ENT_QUOTES) ?>
                            <?php if ($imgCredit !== ''): ?>
                                <span class="media-caption__credit"><?= htmlspecialchars(t('Фото:'), ENT_QUOTES) ?> <?= htmlspecialchars(\App\Core\Media::photoCredit($imgCredit), ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </figcaption>
                    <?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>