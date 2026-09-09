<?php

use App\Core\Icon;

/** @var array $data */
/** @var int $blockId */
$projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$columns = (int) $data['columns'];
$variant = (string) $data['variant'];
$autoplay = (int) $data['autoplay'];
// Полосой блок становится, только когда есть что листать.
$carousel = $variant === 'carousel' && count($projects) > 1;

$navHtml = '';
if ($carousel) {
    ob_start(); ?>
    <span class="carousel-nav" data-carousel-nav hidden>
        <button type="button" class="carousel-nav__btn" data-carousel-prev aria-label="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>"><?= Icon::render('chevron-left', 18) ?></button>
        <span class="carousel-nav__dots" data-carousel-dots role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>"></span>
        <button type="button" class="carousel-nav__btn" data-carousel-next aria-label="<?= htmlspecialchars(t('Вперёд'), ENT_QUOTES) ?>"><?= Icon::render('chevron-right', 18) ?></button>
    </span>
    <?php $navHtml = (string) ob_get_clean();
}

$head = \App\Core\SectionHead::render([
    'title' => (string) ($data['title'] ?? ''),
    'description' => (string) ($data['description'] ?? ''),
    'all_text' => (string) ($data['all_text'] ?? ''),
    'all_url' => (string) ($data['all_url'] ?? ''),
    'tools' => $navHtml,
    // Легаси-класс сохраняем: на нём висят правила статичной темы.
    'title_class' => 'block-projects__title',
]);

// Ряды сетки без дыр: два проекта в трёх колонках оставляли треть ряда пустой.
// Списку и полосе колонки не нужны — там своя раскладка.
$templateCss = $variant === 'grid'
    ? \App\Core\GridBalance::css($blockId, '.block-projects__grid', '.project-card', count($projects), $columns)
    : '';

// Ширина картинки в вёрстке: в сетке — доля ряда, в списке — узкая колонка,
// в полосе — карточка фиксированной ширины.
$sizes = match ($variant) {
    'list' => '(max-width: 720px) 100vw, 320px',
    'carousel' => '(max-width: 720px) 85vw, 320px',
    default => '(max-width: 720px) 100vw, ' . (int) round(100 / $columns) . 'vw',
};
?>
<div class="block-projects block-projects--<?= $variant ?>"<?= $carousel ? ' data-carousel' : '' ?><?= $carousel && $autoplay > 0 ? ' data-carousel-autoplay="' . $autoplay . '"' : '' ?>>
    <?= $head ?>
    <?php if (empty($projects)): ?>
        <p class="block-projects__empty"><?= htmlspecialchars(t('Проекты пока не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="block-projects__grid"<?= $carousel ? ' data-carousel-track tabindex="0"' : '' ?>>
            <?php foreach ($projects as $p): ?>
                <?php
                $projectUrl = !empty($p['slug'])
                    ? \App\Core\Locale::url('projects/' . (string) $p['slug'])
                    : '';
                $projectTag = $projectUrl !== '' ? 'a' : 'div';
                ?>
                <<?= $projectTag ?> class="project-card"<?= $projectUrl !== '' ? ' href="' . htmlspecialchars($projectUrl, ENT_QUOTES) . '"' : '' ?><?= $carousel ? ' data-carousel-item' : '' ?>>
                    <?php if (!empty($p['cover_image'])): ?>
                        <?= \App\Core\Media::picture((string) $p['cover_image'], (string) ($p['title'] ?? ''), null, null, 'project-card__cover', true, $sizes) ?>
                    <?php endif; ?>
                    <div class="project-card__body">
                        <div class="project-card__title"><?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES) ?></div>
                        <?php if (!empty($p['description'])): ?>
                            <p class="project-card__desc"><?= htmlspecialchars(excerpt((string) $p['description'], $variant === 'list' ? 260 : 160), ENT_QUOTES) ?></p>
                        <?php endif; ?>
                    </div>
                </<?= $projectTag ?>>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
