<?php
/** @var array $data */
$title = $data['title'] ?? '';
$allText = trim((string) ($data['all_text'] ?? ''));
$allUrl = trim((string) ($data['all_url'] ?? ''));
$items = $data['items'] ?? [];

// Разделяем на видео/фото для переключателей.
$videoCount = 0;
$photoCount = 0;
foreach ($items as $it) {
    if (($it['kind'] ?? 'video') === 'photo') { $photoCount++; } else { $videoCount++; }
}
$hasVideo = $videoCount > 0;
$hasPhoto = $photoCount > 0;
$showTabs = $hasVideo && $hasPhoto;

// Открытая вкладка приходит из адреса (`?mtab=photo`), а не выбирается
// скриптом: номер страницы принадлежит вкладке, и после перехода по полосе
// страниц открыться должна та же вкладка, что листали.
$activeTab = (string) ($data['_media_tab'] ?? '');
if ($activeTab !== 'photo' || !$hasPhoto) {
    $activeTab = $hasVideo ? 'video' : 'photo';
}

// Логический класс количества колонок нужен адаптиву: на узких экранах их
// меньше, а на десктопе ряд держит заданное редактором число.
$initialKind = $activeTab;
$initialCount = $initialKind === 'video' ? $videoCount : $photoCount;
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$columns = (int) $data['columns'];
$initialColumns = max(1, min(4, $initialCount));
$ratio = (string) $data['ratio'];

// Число колонок десктопа — в scoped CSS блока: раньше оно было прибито
// четвёркой в теме и не настраивалось.
$templateCss = '@media (min-width:1001px){#block-' . (int) $blockId
    . ' .mediagallery-grid{--media-desktop-cols:' . $columns . '}}';

$tabsHtml = '';
if ($showTabs) {
    ob_start(); ?>
    <div class="media-tabs" role="group" aria-label="<?= htmlspecialchars(t('Фильтр медиа'), ENT_QUOTES) ?>">
        <span class="media-tabs__indicator" aria-hidden="true"></span>
        <button type="button" class="media-tabs__tab<?= $activeTab === 'video' ? ' is-active' : '' ?>" data-media-tab="video" aria-pressed="<?= $activeTab === 'video' ? 'true' : 'false' ?>"><span class="media-tabs__tab-text"><?= htmlspecialchars(t('Видео'), ENT_QUOTES) ?></span></button>
        <button type="button" class="media-tabs__tab<?= $activeTab === 'photo' ? ' is-active' : '' ?>" data-media-tab="photo" aria-pressed="<?= $activeTab === 'photo' ? 'true' : 'false' ?>"><span class="media-tabs__tab-text"><?= htmlspecialchars(t('Фото'), ENT_QUOTES) ?></span></button>
    </div>
    <?php $tabsHtml = (string) ob_get_clean();
}
?>
<div class="block-mediagallery" data-media-gallery data-media-active="<?= htmlspecialchars($activeTab, ENT_QUOTES) ?>">
    <?= \App\Core\SectionHead::render([
        'title' => (string) $title,
        'description' => (string) ($data['description'] ?? ''),
        'all_text' => $allText,
        'all_url' => $allUrl,
        'tools' => $tabsHtml,
        // Ссылка «Все материалы» стоит после вкладок и прижата к правому краю
        // их строки: вкладки — навигация по содержимому, ссылка — выход из него.
        'all_after_tools' => true,
        'class' => 'block-mediagallery__head' . ($tabsHtml !== '' ? ' section-head--split-tools' : ''),
    ]) ?>
    <?php if (empty($items)): ?>
        <p class="block-mediagallery__empty"><?= htmlspecialchars(t('Материалы ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="mediagallery-grid mediagallery-grid--desktop-4 mediagallery-grid--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?> mediagallery-grid--cols-<?= $initialColumns ?>" data-media-grid>
            <?php foreach ($items as $item): ?>
                <?php
                $url = trim((string) ($item['url'] ?? ''));
                $img = trim((string) ($item['image'] ?? ''));
                $duration = trim((string) ($item['meta'] ?? ''));
                $kind = ($item['kind'] ?? 'video') === 'photo' ? 'photo' : 'video';
                if ($img !== '' && !\App\Core\UrlGuard::isSafeMedia($img)) {
                    $img = '';
                }
                if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                    $url = '';
                }
                // Ручная фотография без отдельной ссылки открывает сам файл
                // в общем lightbox — отдельный тип «Галерея» больше не нужен.
                if ($kind === 'photo' && $url === '' && $img !== '') {
                    $url = $img;
                }
                $tag = $url !== '' ? 'a' : 'div';
                ?>
                <<?= $tag ?> class="mediacard mediacard--<?= $kind ?>" data-media-kind="<?= $kind ?>"<?= $url !== '' ? ' href="' . htmlspecialchars($url, ENT_QUOTES) . '"' : '' ?>>
                    <span class="mediacard__media">
                        <?php if ($img !== ''): ?><?= \App\Core\Media::picture($img, (string) $item['title'], null, null, 'mediacard__img', true, '(max-width: 560px) 100vw, (max-width: 1000px) 50vw, 25vw') ?><?php endif; ?>
                        <span class="mediacard__play mediacard__play--<?= $kind ?>" aria-hidden="true">
                            <?php if ($kind === 'photo'): ?>
                                <?= \App\Core\Icon::render('photo', 24) ?>
                            <?php else: ?>
                                <?= \App\Core\Icon::render('player-play', 24) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($duration !== ''): ?><span class="mediacard__duration"><?= htmlspecialchars($duration, ENT_QUOTES) ?></span><?php endif; ?>
                    </span>
                    <span class="mediacard__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                    <?php if (!empty($item['text'])): ?><span class="mediacard__date"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span><?php endif; ?>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    // Полоса страниц у блока — та же, что у ленты новостей и каталогов:
    // расходясь, две копии дали бы разное поведение на половинах сайта.
    // Полос столько, сколько вкладок: у видео и фото списки разной длины,
    // и общая полоса на «Фото» показывала страницы видео. Видна та, чья
    // вкладка открыта; вторую показывает переключатель.
    $mediaPagers = (array) ($data['_pagers'] ?? []);
    $mediaBlockId = (int) $blockId;
    foreach ($mediaPagers as $pagerKind => $mediaPager):
        $mediaPager = (array) $mediaPager;
        if ((int) $mediaPager['pages'] < 2) {
            continue;
        }
        $pagerKind = (string) $pagerKind;
        // Имя вкладки в адресе нужно только там, где вкладки есть: у блока
        // с одним источником лишний параметр ничего не значит.
        $pagerTab = $showTabs ? $pagerKind : '';
        ?>
        <div class="block-mediagallery__pager" data-media-pager="<?= htmlspecialchars($pagerKind, ENT_QUOTES) ?>"<?= $showTabs && $pagerKind !== $activeTab ? ' hidden' : '' ?>>
            <?= \App\Core\View::renderPartial('site/_pager', [
                'page' => (int) $mediaPager['page'],
                'pages' => (int) $mediaPager['pages'],
                'pageUrl' => static fn (int $p): string => \App\Core\BlockPager::url($p, $mediaBlockId, $pagerTab),
            ]) ?>
        </div>
    <?php endforeach; ?>
</div>
