<?php

use App\Core\Locale;

/** @var array $items */
/** @var array|null $sectionPage шапка раздела из админки (может отсутствовать) */
$sectionPage = $sectionPage ?? null;
$sectionTitle = (string) ($sectionPage['title'] ?? '');
$sectionLead = (string) ($sectionPage['lead'] ?? '');
$sectionMetaTitle = (string) ($sectionPage['meta_title'] ?? '');
$metaTitle = $sectionMetaTitle !== '' ? $sectionMetaTitle : ($sectionTitle !== '' ? $sectionTitle : t('Проекты'));
$metaDescription = (string) ($sectionPage['meta_description'] ?? '');
if ($metaDescription === '') {
    $metaDescription = $sectionLead !== '' ? $sectionLead : t('Проекты и инициативы Агентства.');
}
// Стили блоков раздела уезжают в <head> тем же путём, что и у страницы.
$extraHeadCss = (string) ($sectionPage['css'] ?? '');
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Проекты')],
];
require __DIR__ . '/_crumbs.php';
?>
<div class="listing">
    <div class="listing__head">
        <h1 class="listing__title"><?= htmlspecialchars($sectionTitle !== '' ? $sectionTitle : t('Проекты и инициативы'), ENT_QUOTES) ?></h1>
        <p class="listing__lead"><?= htmlspecialchars($sectionLead !== '' ? $sectionLead : t('Стратегические проекты, которые Агентство реализует для устойчивого развития страны.'), ENT_QUOTES) ?></p>
    </div>
    <?php if (empty($items)): ?>
        <p class="listing__empty"><?= htmlspecialchars(t('Проекты ещё не опубликованы.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($items as $item): ?>
                <?php $cover = trim((string) ($item['cover_image'] ?? '')); ?>
                <a class="imgcard imgcard--project imgcard--below" href="<?= htmlspecialchars(Locale::url('projects/' . $item['slug']), ENT_QUOTES) ?>">
                    <?php if ($cover !== ''): ?>
                        <?= \App\Core\Media::picture($cover, (string) $item['title'], null, null, 'imgcard__media', true, '(max-width: 700px) 100vw, 50vw') ?>
                    <?php else: ?>
                        <span class="imgcard__media" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="imgcard__body">
                        <h3 class="imgcard__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                        <?php if (!empty($item['description'])): ?>
                            <span class="imgcard__desc"><?= htmlspecialchars(excerpt((string) $item['description'], 120), ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php // Блоки страницы-раздела — под списком: наверху уже его шапка. ?>
    <?php if (!empty($sectionPage['content'])): ?>
        <div class="listing__section-blocks"><?= $sectionPage['content'] ?></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>