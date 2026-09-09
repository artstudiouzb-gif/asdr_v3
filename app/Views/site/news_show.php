<?php

use App\Core\AssetCollector;
use App\Core\DateFormatter;
use App\Core\Csrf;
use App\Core\Locale;
use App\Core\NewsLead;
use App\Models\News;
use App\Models\Setting;

/** @var array $news */
/** @var array $gallery */
/** @var array $related */
/** @var ?array $prevNews */
/** @var ?array $nextNews */
$gallery = $gallery ?? [];
$related = $related ?? [];
$prevNews = $prevNews ?? null;
$nextNews = $nextNews ?? null;
$lang = Locale::current();

$metaTitle = $news['meta_title'] ?: $news['title'];
$metaDescription = $news['meta_description'] ?: ($news['excerpt'] ?? '');
$leadHtml = NewsLead::html($news['lead_html'] ?? null, $news['excerpt'] ?? null);
$ogType = 'article';
// Даты уходят в article:published_time / article:modified_time. Переменные
// читает _header.php; пока их никто не задавал, у новостей не было ни одной
// из двух дат, хотя разметка для них написана.
$publishedAt = (string) ($news['published_at'] ?? '');
$modifiedAt = (string) ($news['updated_at'] ?? '');
$ogImage = News::getCoverImage($news) ?? '';

// Рубрика новости на языке страницы; ссылка ведёт в отфильтрованную ленту.
$newsCategory = null;
if (!empty($news['category_id'])) {
    $categoryRow = \App\Models\NewsCategory::find((int) $news['category_id']);
    if ($categoryRow !== null) {
        $categoryRow = \App\Models\NewsCategory::localize($categoryRow, $lang);
        $newsCategory = [
            'name' => (string) $categoryRow['name'],
            'url' => Locale::url('news') . '?category=' . rawurlencode((string) $categoryRow['slug']),
        ];
    }
}

AssetCollector::requireJs('news');

require __DIR__ . '/_header.php';

// Крошки: у премиум-макета рендерятся внутри hero (см. ниже), у остальных —
// обычной полосой перед статьёй.
$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Новости'), 'url' => Locale::url('news')],
    ['label' => (string) $news['title']],
];

$date = (string) ($news['published_at'] ?? '');
// Дата — единым числовым форматом на всех языках: 19.07.2026.
$dateLong = $date !== '' ? DateFormatter::short($date) : '';
// Время чтения: ~180 слов в минуту по тексту статьи (юникод-подсчёт слов).
preg_match_all('/[\p{L}\p{N}]+/u', strip_tags((string) ($news['content'] ?? '')), $m);
$readMin = max(1, (int) ceil(count($m[0]) / 180));
$views = (int) ($news['views'] ?? 0);

// Слайды: обложка + галерея (уникальные пути).
$slides = [];
$cover = trim((string) ($news['image'] ?? ''));
// Подписи галереи по пути файла: обложка обычно продублирована в галерее, и
// её подпись раньше терялась вместе с пропущенным кадром.
$captionsByPath = [];
foreach ($gallery as $img) {
    $captionsByPath[trim((string) $img['path'])] = [
        'caption' => trim((string) ($img['caption'] ?? '')),
        'credit' => trim((string) ($img['credit'] ?? '')),
    ];
}
if ($cover !== '') {
    $slides[] = [
        'path' => $cover,
        'alt' => (string) $news['title'],
        'caption' => (string) ($captionsByPath[$cover]['caption'] ?? ''),
        'credit' => (string) ($captionsByPath[$cover]['credit'] ?? ''),
    ];
}
foreach ($gallery as $img) {
    $p = trim((string) $img['path']);
    if ($p !== '' && $p !== $cover) {
        $slides[] = [
            'path' => $p,
            'alt' => (string) ($img['alt_text'] ?? ''),
            'caption' => trim((string) ($img['caption'] ?? '')),
            'credit' => trim((string) ($img['credit'] ?? '')),
        ];
    }
}
// Подпись под снимком: текст и автор строкой «Фото: …».
$photoCaption = static function (array $s): string {
    $caption = trim((string) ($s['caption'] ?? ''));
    $credit = trim((string) ($s['credit'] ?? ''));
    if ($caption === '' && $credit === '') {
        return '';
    }

    return '<figcaption class="media-caption">'
        . htmlspecialchars($caption, ENT_QUOTES)
        . ($credit !== '' ? '<span class="media-caption__credit">' . htmlspecialchars(t('Фото:'), ENT_QUOTES) . ' ' . htmlspecialchars(\App\Core\Media::photoCredit($credit), ENT_QUOTES) . '</span>' : '')
        . '</figcaption>';
};

$keyPoints = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($news['key_points'] ?? '')) ?: [])));
$eventMeta = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($news['event_meta'] ?? '')) ?: [])));
$docs = json_decode((string) ($news['docs'] ?? '[]'), true);
$docs = is_array($docs) ? $docs : [];
$videoUrl = trim((string) ($news['video_url'] ?? ''));
$audioUrl = trim((string) ($news['audio_url'] ?? ''));
if ($audioUrl !== ''
    && (str_starts_with($audioUrl, '//') || !\App\Core\UrlGuard::isSafeMedia($audioUrl))) {
    $audioUrl = '';
}
$audioTitle = trim((string) ($news['audio_title'] ?? ''));
$legacyPressUrl = trim((string) ($news['press_release_url'] ?? ''));
if ($legacyPressUrl !== '') {
    $alreadyInDocs = false;
    foreach ($docs as $doc) {
        if (is_array($doc) && trim((string) ($doc['url'] ?? '')) === $legacyPressUrl) {
            $alreadyInDocs = true;
            break;
        }
    }
    if (!$alreadyInDocs) {
        // Старые записи показываем в едином разделе документов до их сохранения
        // в обновлённой форме админки.
        $docs[] = ['title' => t('Пресс-релиз'), 'meta' => '', 'url' => $legacyPressUrl];
    }
}

$base = \App\Core\AppUrl::base();
$pageUrl = $base . Locale::url('news/' . $news['slug'], $lang);
$shareTitle = rawurlencode((string) $news['title']);
$shareUrl = rawurlencode($pageUrl);
$homeUrl = Locale::url('/');

// Общие мини-иконки (событие: календарь, место, участники, теги — по кругу).
$eventIcons = array_map(
    static fn (string $name): string => \App\Core\Icon::render($name, 18, 'ui-icon', 1.6),
    ['calendar', 'map-pin', 'users', 'tag']
);
$docIcon = \App\Core\Icon::render('file-text', 22, 'ui-icon', 1.5);
$dlIcon = \App\Core\Icon::render('download', 17, 'ui-icon', 1.8);
?>
<?php
// Тип отображения (выбирается в админке): standard — только обложка,
// gallery — слайдер с миниатюрами, video — модуль YouTube с play,
// side_image — компактное фото сбоку от заголовка.
$layout = News::normalizeLayout($news['layout_type'] ?? 'standard');
$videoId = \App\Core\Video::youtubeId($news['video_url'] ?? null);
if ($layout === 'video' && $videoId === null) {
    $layout = 'standard'; // без валидного YouTube-URL показываем обложку
}

// Умный макет: если загружено больше 1 фото, автоматически включается интерактивный слайдер галереи!
if ($layout === 'standard' || $layout === 'gallery') {
    if (count($slides) > 1) {
        $layout = 'gallery';
        $heroSlides = $slides;
    } else {
        $layout = 'standard';
        $heroSlides = array_slice($slides, 0, 1);
    }
} else {
    $heroSlides = array_slice($slides, 0, 1);
}

// «Карточка» — та же фото-обложка, но текст лежит в стеклянной панели.
// Отдельного шаблона ей не нужно: содержимое то же, меняется только оформление.
$isCard = $layout === 'card';
$isPremium = $layout === 'premium' || $isCard;
$hasMedia = !$isPremium && ($layout === 'video' || !empty($heroSlides));
$hasKeyPoints = !empty($keyPoints);

// Метка («Важно», «Анонс») — самый яркий элемент строки над заголовком: у
// неё сплошная заливка, а рядом стоят спокойная рубрика, дата и счётчики.
// В этом ряду она перетягивает внимание с самого заголовка, поэтому при
// наличии фото или видео уезжает углом на медиа. Без медиа остаётся в
// строке: иначе метка пропала бы совсем. Премиум-макет не трогаем — там
// вся шапка и так лежит поверх обложки.
$badgeInline = $hasMedia
    ? ''
    : \App\Core\NewsBadge::render($news['badge'] ?? '', $news['badge_color'] ?? null);
$badgeOnMedia = $hasMedia
    ? \App\Core\NewsBadge::renderOverlay($news['badge'] ?? '', $news['badge_color'] ?? null)
    : '';

// Оглавление статьи (премиум): собираем из <h2>/<h3> контента и проставляем id.
$toc = [];
$contentHtml = \App\Core\SocialEmbed::transform((string) $news['content']);
if ($isPremium) {
    $n = 0;
    $contentHtml = (string) preg_replace_callback(
        '/<h([23])([^>]*)>(.*?)<\/h\1>/su',
        static function (array $m) use (&$toc, &$n): string {
            $n++;
            $id = 'sec-' . $n;
            $toc[] = ['id' => $id, 'label' => trim(strip_tags($m[3]))];
            return '<h' . $m[1] . $m[2] . ' id="' . $id . '">' . $m[3] . '</h' . $m[1] . '>';
        },
        $contentHtml
    ) ?: $contentHtml;
}

$shareBlock = static function (string $extraClass) use ($shareUrl, $shareTitle, $pageUrl, $homeUrl): void { ?>
            <div class="newsdetail-share no-print<?= $extraClass ?>">
                <a class="newsdetail-share__home" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES) ?>">
                    <?= \App\Core\Icon::render('arrow-left', 18, 'ui-icon', 1.8) ?>
                    <span><?= htmlspecialchars(t('На главную'), ENT_QUOTES) ?></span>
                </a>
                <p class="newsdetail-share__title"><?= htmlspecialchars(t('Поделиться'), ENT_QUOTES) ?></p>
                <div class="newsdetail-share__row">
                    <a class="newsdetail-share__btn" href="https://t.me/share/url?url=<?= $shareUrl ?>&text=<?= $shareTitle ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(t('Поделиться в Telegram'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('brand-telegram', 17) ?></a>
                    <a class="newsdetail-share__btn" href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(t('Поделиться в Facebook'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('brand-facebook', 17) ?></a>
                    <a class="newsdetail-share__btn" href="https://x.com/intent/post?url=<?= $shareUrl ?>&text=<?= $shareTitle ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(t('Поделиться в X'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('brand-x', 16) ?></a>
                    <a class="newsdetail-share__btn" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(t('Поделиться в LinkedIn'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('brand-linkedin', 17) ?></a>
                    <button type="button" class="newsdetail-share__btn" data-copy-link="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Скопировать ссылку'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('link', 17, 'ui-icon', 1.8) ?></button>
                    <button type="button" class="newsdetail-share__btn" data-reader-mode-toggle aria-label="<?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Открыть режим чтения'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('book', 17, 'ui-icon', 1.8) ?></button>
                    <button type="button" class="newsdetail-share__btn" data-print-page aria-label="<?= htmlspecialchars(t('Распечатать'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('printer', 17, 'ui-icon', 1.8) ?></button>
                </div>
            </div>
<?php };

if (!$isPremium) {
    require __DIR__ . '/_crumbs.php';
}

$sidebar = $sidebar ?? null;
$hasSidebar = $sidebar !== null && trim((string) ($sidebar['html'] ?? '')) !== '';
?>
<article class="newsdetail newsdetail--layout-<?= htmlspecialchars($layout, ENT_QUOTES) ?><?= $isPremium ? ' newsdetail--premium' : '' ?>">
    <?php if (!$isPremium): ?>
        <nav class="newsdetail-actionrail no-print" aria-label="<?= htmlspecialchars(t('Действия новости'), ENT_QUOTES) ?>">
            <a class="newsdetail-actionrail__btn newsdetail-actionrail__btn--home" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES) ?>" data-label="<?= htmlspecialchars(t('На главную'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('На главную'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('arrow-left', 19, 'ui-icon', 1.9) ?></a>
        </nav>
    <?php endif; ?>
    <?php if ($isPremium): ?>
    <div class="newsdetail-phero<?= $isCard ? ' newsdetail-phero--card' : '' ?>"<?= $cover !== '' ? ' style="--news-cover-image:url(\'' . htmlspecialchars($cover, ENT_QUOTES) . '\')"' : '' ?>>
        <span class="newsdetail-phero__overlay"></span>
        <div class="newsdetail-phero__body">
            <?php require __DIR__ . '/_crumbs.php'; ?>
            <?php if ($newsCategory !== null): ?>
                <a class="newsdetail__badge newsdetail__badge--onDark" href="<?= htmlspecialchars($newsCategory['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($newsCategory['name'], ENT_QUOTES) ?></a>
            <?php endif; ?>
            <?= \App\Core\NewsBadge::render($news['badge'] ?? '', $news['badge_color'] ?? null, true) ?>
            <?php if ($isCard && trim((string) ($news['card_badge'] ?? '')) !== ''): ?>
                <span class="newsdetail-cover__tag"><?= htmlspecialchars(trim((string) $news['card_badge']), ENT_QUOTES) ?></span>
            <?php endif; ?>
            <div class="newsdetail__meta newsdetail__meta--onDark">
                <?php if ($dateLong !== ''): ?>
                    <span class="newsdetail__meta-item"><?= $eventIcons[0] ?><time datetime="<?= htmlspecialchars(substr($date, 0, 10), ENT_QUOTES) ?>"><?= htmlspecialchars($dateLong, ENT_QUOTES) ?></time></span>
                <?php endif; ?>
                <span class="newsdetail__meta-item"><?= \App\Core\Icon::render('clock', 18, 'ui-icon', 1.6) ?><?= $readMin ?> <?= htmlspecialchars(t('мин.'), ENT_QUOTES) ?></span>
                <?php if ($views > 0): ?>
                    <span class="newsdetail__meta-item"><?= \App\Core\Icon::render('eye', 18, 'ui-icon', 1.6) ?><?= htmlspecialchars(trim(number_format($views, 0, '', ' ') . ' ' . t('просмотров')), ENT_QUOTES) ?></span>
                <?php endif; ?>
                <button type="button" class="newsdetail__reader-btn newsdetail__reader-btn--onDark" data-reader-mode-toggle aria-label="<?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Открыть режим чтения'), ENT_QUOTES) ?>">
                    <?= \App\Core\Icon::render('book', 17, 'ui-icon', 1.8) ?>
                    <span><?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?></span>
                </button>
            </div>
            <?php
            // Заголовок на обложке — отдельная короткая строка. Настоящий
            // заголовок уходит в ленту, RSS и поиск, укорачивать его ради
            // картинки нельзя. Звёздочки выделения работают только здесь.
            $cardTitle = $isCard ? trim((string) ($news['card_title'] ?? '')) : '';
            ?>
            <h1 class="newsdetail-phero__title"><?= $cardTitle !== ''
                ? \App\Core\TitleMarkup::html($cardTitle)
                : htmlspecialchars((string) $news['title'], ENT_QUOTES) ?></h1>
            <?php if ($leadHtml !== ''): ?>
                <div class="newsdetail-phero__lead news-lead-rich"><?= $leadHtml ?></div>
            <?php endif; ?>
            <?php $cardStats = $isCard ? \App\Core\NewsCard::stats($news['card_stats'] ?? '') : []; ?>
            <?php if ($cardStats !== []): ?>
                <div class="newsdetail-cover__stats">
                    <?php foreach ($cardStats as $cardStat): ?>
                        <div class="newsdetail-cover__stat">
                            <span class="newsdetail-cover__stat-value"><?= htmlspecialchars($cardStat['value'], ENT_QUOTES) ?></span>
                            <span class="newsdetail-cover__stat-label"><?= htmlspecialchars($cardStat['label'], ENT_QUOTES) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($news['source_note'])): ?>
                <p class="newsdetail__source newsdetail__source--onDark"><?= htmlspecialchars((string) $news['source_note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php
            $cardSignature = $isCard ? trim((string) ($news['card_signature'] ?? '')) : '';
            $cardNote = $isCard ? trim((string) ($news['card_note'] ?? '')) : '';
            ?>
            <?php if ($cardSignature !== '' || $cardNote !== ''): ?>
                <div class="newsdetail-cover__foot">
                    <?php if ($cardSignature !== ''): ?>
                        <span class="newsdetail-cover__signature"><?= htmlspecialchars($cardSignature, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <?php if ($cardNote !== ''): ?>
                        <span class="newsdetail-cover__note"><?= htmlspecialchars($cardNote, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($videoUrl !== '' && $layout !== 'video'): ?>
                <a class="newsdetail-phero__video" href="<?= htmlspecialchars($videoUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">
                    <span class="newsdetail-phero__play"><?= \App\Core\Icon::render('player-play', 16) ?></span>
                    <?= htmlspecialchars(t('Смотреть видео'), ENT_QUOTES) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="newsdetail-head<?= ($hasMedia && !$hasSidebar) ? '' : ' newsdetail-head--full' ?><?= $hasMedia && $layout === 'side_image' && !$hasSidebar ? ' newsdetail-head--side' : '' ?>">
        <div class="newsdetail-head__info">
            <div class="newsdetail__eyebrow">
                <a class="newsdetail-back-pill" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>">
                    <?= \App\Core\Icon::render('arrow-left', 16, 'ui-icon', 1.8) ?>
                </a>
                <?php if ($newsCategory !== null): ?>
                    <a class="newsdetail__badge" href="<?= htmlspecialchars($newsCategory['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($newsCategory['name'], ENT_QUOTES) ?></a>
                <?php endif; ?>
                <?= $badgeInline ?>
                <div class="newsdetail__meta">
                    <?php if ($dateLong !== ''): ?>
                        <span class="newsdetail__meta-item"><?= $eventIcons[0] ?><time datetime="<?= htmlspecialchars(substr($date, 0, 10), ENT_QUOTES) ?>"><?= htmlspecialchars($dateLong, ENT_QUOTES) ?></time></span>
                    <?php endif; ?>
                    <span class="newsdetail__meta-item"><?= \App\Core\Icon::render('clock', 18, 'ui-icon', 1.6) ?><?= $readMin ?> <?= htmlspecialchars(t('мин.'), ENT_QUOTES) ?></span>
                    <?php if ($views > 0): ?>
                        <span class="newsdetail__meta-item"><?= \App\Core\Icon::render('eye', 18, 'ui-icon', 1.6) ?><?= htmlspecialchars(trim(number_format($views, 0, '', ' ') . ' ' . t('просмотров')), ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <button type="button" class="newsdetail__reader-btn" data-reader-mode-toggle aria-label="<?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Открыть режим чтения'), ENT_QUOTES) ?>">
                        <?= \App\Core\Icon::render('book', 17, 'ui-icon', 1.8) ?>
                        <span><?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?></span>
                    </button>
                </div>
            </div>
            <h1 class="newsdetail__title"><?= htmlspecialchars((string) $news['title'], ENT_QUOTES) ?></h1>
            <?php if ($leadHtml !== ''): ?>
                <div class="newsdetail__lead news-lead-rich"><?= $leadHtml ?></div>
            <?php endif; ?>
            <?php if (!empty($news['source_note'])): ?>
                <p class="newsdetail__source"><?= htmlspecialchars((string) $news['source_note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($videoUrl !== ''): ?>
                <div class="newsdetail__actions">
                    <a class="newsdetail__btn newsdetail__btn--primary" href="<?= htmlspecialchars($videoUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">
                        <?= \App\Core\Icon::render('player-play', 16) ?>
                        <?= htmlspecialchars(t('Смотреть видео'), ENT_QUOTES) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($layout === 'video'): ?>
            <?php
            // Модуль видео: обложка YouTube, плеер грузится только по клику (news.js).
            $thumb = \App\Core\Video::youtubeThumbnail($videoId);
            $fallback = 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
            $embed = \App\Core\Video::youtubeEmbed($videoId) . '&autoplay=1';
            ?>
            <div class="newsdetail-media newsdetail-media--video">
                <div class="news-video newsdetail-video skeleton" data-youtube="<?= htmlspecialchars($videoId, ENT_QUOTES) ?>" data-embed="<?= htmlspecialchars($embed, ENT_QUOTES) ?>" data-replay-label="<?= htmlspecialchars(t('Посмотреть ещё раз'), ENT_QUOTES) ?>">
                    <img class="news-video__thumb" src="<?= htmlspecialchars($cover !== '' ? $cover : $thumb, ENT_QUOTES) ?>" data-fallback="<?= htmlspecialchars($fallback, ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $news['title'], ENT_QUOTES) ?>" loading="eager" decoding="async">
                    <?= $badgeOnMedia ?>
                    <button type="button" class="news-video__play" aria-label="<?= htmlspecialchars(t('Смотреть видео'), ENT_QUOTES) ?>"></button>
                </div>
            </div>
        <?php elseif (!empty($heroSlides)): ?>
            <div class="newsdetail-gallery" data-ndgallery>
                <div class="newsdetail-gallery__main">
                    <?php foreach ($heroSlides as $i => $s): ?>
                        <?= \App\Core\Media::picture((string) $s['path'], (string) ($s['alt'] !== '' ? $s['alt'] : ($s['caption'] ?? '')), null, null, 'newsdetail-gallery__slide' . ($i === 0 ? ' is-active' : ''), $i !== 0, '(max-width: 900px) 100vw, 70vw') ?>
                    <?php endforeach; ?>
                    <?php if (count($heroSlides) > 1): ?>
                        <button type="button" class="newsdetail-gallery__nav newsdetail-gallery__nav--prev" data-ndg-prev aria-label="<?= htmlspecialchars(t('Предыдущее фото'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-left', 22, 'ui-icon', 2) ?></button>
                        <button type="button" class="newsdetail-gallery__nav newsdetail-gallery__nav--next" data-ndg-next aria-label="<?= htmlspecialchars(t('Следующее фото'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-right', 22, 'ui-icon', 2) ?></button>
                        <span class="newsdetail-gallery__counter"><span data-ndg-current>1</span><span aria-hidden="true">/</span><?= count($heroSlides) ?></span>
                    <?php endif; ?>
                    <?= $badgeOnMedia ?>
                </div>
                <?php
                // Подпись и автор под галереей. Тексты всех слайдов уезжают в
                // data-атрибуты: при листании их подставляет скрипт галереи.
                $galleryCaptions = array_map(static fn (array $s): array => [
                    'caption' => (string) ($s['caption'] ?? ''),
                    'credit' => (string) ($s['credit'] ?? ''),
                ], $heroSlides);
                $hasCaptions = array_filter($galleryCaptions, static fn (array $c): bool => $c['caption'] !== '' || $c['credit'] !== '');
                ?>
                <?php if ($hasCaptions !== []): ?>
                    <figcaption class="media-caption newsdetail-gallery__caption" data-ndg-captions='<?= htmlspecialchars(json_encode($galleryCaptions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', ENT_QUOTES) ?>'>
                        <span data-ndg-caption-text><?= htmlspecialchars($galleryCaptions[0]['caption'], ENT_QUOTES) ?></span>
                        <span class="media-caption__credit" data-ndg-caption-credit<?= $galleryCaptions[0]['credit'] === '' ? ' hidden' : '' ?>><?= $galleryCaptions[0]['credit'] !== '' ? htmlspecialchars(t('Фото:'), ENT_QUOTES) . ' ' . htmlspecialchars($galleryCaptions[0]['credit'], ENT_QUOTES) : '' ?></span>
                    </figcaption>
                <?php endif; ?>
                <?php if (count($heroSlides) > 1): ?>
                    <div class="newsdetail-gallery__thumbs">
                        <?php foreach ($heroSlides as $i => $s): ?>
                            <button type="button" class="newsdetail-gallery__thumb<?= $i === 0 ? ' is-active' : '' ?>" data-ndg-thumb="<?= $i ?>" aria-label="<?= htmlspecialchars(t('Фото'), ENT_QUOTES) ?> <?= $i + 1 ?>">
                                <?php // Миниатюра показывается 110×62, поэтому и грузить надо кадр
                                      // такого порядка. Прежде здесь стоял сырой путь: браузер
                                      // тянул оригинал целиком — и это была ВТОРАЯ загрузка той же
                                      // фотографии, потому что главный слайд берёт webp-вариант по
                                      // другому адресу. На галерее из четырёх снимков это четыре
                                      // лишних оригинала. Подсказка «110px» уводит выбор на самый
                                      // мелкий из существующих вариантов. ?>
                                <?= \App\Core\Media::picture((string) $s['path'], (string) $s['alt'], null, null, '', true, '110px') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="newsdetail-body newsdetail-body--no-left">
        <div class="newsdetail-article">
            <?php $shareBlock(' newsdetail-share--article-top'); ?>
            <?php if ($audioUrl !== ''): ?>
                <div class="news-audio-player" data-audio-player>
                    <div class="news-audio-player__icon" aria-hidden="true">
                        <?= \App\Core\Icon::render('music', 24) ?>
                    </div>
                    <div class="news-audio-player__info">
                        <span class="news-audio-player__badge"><?= htmlspecialchars(t('Аудиозапись'), ENT_QUOTES) ?></span>
                        <span class="news-audio-player__title"><?= htmlspecialchars($audioTitle !== '' ? $audioTitle : t('Аудиоверсия новости'), ENT_QUOTES) ?></span>
                    </div>
                    <audio class="news-audio-player__element" src="<?= htmlspecialchars($audioUrl, ENT_QUOTES) ?>" controls preload="metadata"></audio>
                </div>
            <?php endif; ?>
            <div class="newsdetail-article__content rich-content"><?= $contentHtml ?></div>

            <?php // Хронология событий (Таймлайн) ?>
            <?php if (!empty($news['timeline_json'])): ?>
                <?php $tEvents = json_decode((string) $news['timeline_json'], true); ?>
                <?php if (is_array($tEvents) && $tEvents !== []): ?>
                    <div class="newsdetail-timeline">
                        <h3 class="newsdetail-timeline__title">
                            <?= \App\Core\Icon::render('clock', 20) ?>
                            <?= htmlspecialchars(t('Хронология событий'), ENT_QUOTES) ?>
                        </h3>
                        <div class="newsdetail-timeline__list">
                            <?php foreach ($tEvents as $evt): ?>
                                <div class="newsdetail-timeline__item">
                                    <div class="newsdetail-timeline__dot"></div>
                                    <div class="newsdetail-timeline__content">
                                        <span class="newsdetail-timeline__date"><?= htmlspecialchars((string) ($evt['date'] ?? ''), ENT_QUOTES) ?></span>
                                        <h4 class="newsdetail-timeline__heading"><?= htmlspecialchars((string) ($evt['title'] ?? ''), ENT_QUOTES) ?></h4>
                                        <?php if (!empty($evt['text'])): ?>
                                            <p class="newsdetail-timeline__desc"><?= htmlspecialchars((string) $evt['text'], ENT_QUOTES) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php $hashtagsClean = \App\Models\News::cleanHashtags($news['hashtags'] ?? null); ?>
            <?php if ($hashtagsClean !== null): ?>
                <div class="newsdetail-hashtags" data-no-translit>
                    <?php foreach (explode(' ', $hashtagsClean) as $tag): ?>
                        <a href="<?= Locale::url('news') ?>?q=<?= rawurlencode($tag) ?>" class="newsdetail-tag"><?= htmlspecialchars($tag, ENT_QUOTES) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Сторона колонки — из настройки «Макет страницы с виджетами».
        // Раньше здесь стоял жёсткий --right: выбор «Левый сайдбар» менял
        // только то, ОТКУДА берутся виджеты, а показывались они всё равно
        // справа. Порядок в разметке не трогаем — статья остаётся первой для
        // диктора и клавиатуры, колонка уезжает влево средствами сетки.
        $sideClass = ($sidebar['position'] ?? 'right') === 'left'
            ? 'newsdetail-side--left'
            : 'newsdetail-side--right';
        ?>
        <aside class="newsdetail-side <?= $sideClass ?>">
            <?php if ($hasKeyPoints): ?>
                <div class="newsdetail-card newsdetail-card--summary">
                    <div class="newsdetail-card__heading">
                        <span class="newsdetail-card__heading-icon" aria-hidden="true"><?= \App\Core\Icon::render('notes', 20, 'ui-icon', 1.7) ?></span>
                        <h3 class="newsdetail-card__title"><?= htmlspecialchars(t('Ключевые тезисы'), ENT_QUOTES) ?></h3>
                    </div>
                    <ol class="newsdetail-points">
                        <?php foreach ($keyPoints as $i => $point): ?>
                            <li class="newsdetail-points__item"><span class="newsdetail-points__number" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span><?= htmlspecialchars($point, ENT_QUOTES) ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>
            <?php // Интерактивный опрос (часть новости, отображается как карточка в сайдбаре) ?>
            <?php
                $poll = !empty($news['id']) ? \App\Models\NewsPoll::findByNews((int) $news['id']) : null;
                if (!empty($news['poll_question'])) {
                    $lPollOptions = [];
                    if (!empty($news['poll_options_json'])) {
                        $lPollOptions = is_array($news['poll_options_json']) ? $news['poll_options_json'] : (json_decode((string) $news['poll_options_json'], true) ?: []);
                    }
                    if ($poll === null) {
                        $poll = [
                            'id' => (int) $news['id'],
                            'question' => (string) $news['poll_question'],
                            'options' => $lPollOptions,
                        ];
                    } else {
                        $poll['question'] = (string) $news['poll_question'];
                        if (!empty($lPollOptions)) {
                            $poll['options'] = $lPollOptions;
                        }
                    }
                }
            ?>
            <?php if ($poll !== null && !empty($poll['question'])): ?>
                <?php
                    $pollCsrfToken = Csrf::token();
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $voterHash = hash('sha256', $ip . '|' . session_id());
                    $hasVoted = \App\Models\NewsPoll::hasVoted((int) $poll['id'], $voterHash);
                    $results = \App\Models\NewsPoll::getResults((int) $poll['id'], $poll['options']);
                ?>
                <div class="newsdetail-card news-poll-card" data-poll-id="<?= (int) $poll['id'] ?>">
                    <div class="news-poll-card__header">
                        <span class="news-poll-card__badge"><?= htmlspecialchars(t('Опрос читателей'), ENT_QUOTES) ?></span>
                        <h3 class="news-poll-card__question"><?= htmlspecialchars((string) $poll['question'], ENT_QUOTES) ?></h3>
                    </div>
                    <div class="news-poll-card__body">
                        <?php if ($hasVoted): ?>
                            <div class="news-poll-card__results">
                                <?php foreach ($results['items'] as $resItem): ?>
                                    <div class="news-poll-res-row">
                                        <div class="news-poll-res-info">
                                            <span class="news-poll-res-label"><?= htmlspecialchars($resItem['label'], ENT_QUOTES) ?></span>
                                            <span class="news-poll-res-val"><?= $resItem['percent'] ?>%</span>
                                        </div>
                                        <div class="news-poll-bar-track">
                                            <div class="news-poll-bar-fill" style="--poll-percent:<?= (float) $resItem['percent'] ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="news-poll-card__meta"><?= htmlspecialchars(t('Всего голосов:'), ENT_QUOTES) ?> <strong><?= $results['total'] ?></strong></div>
                            </div>
                        <?php else: ?>
                            <form class="news-poll-card__form" data-poll-form>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pollCsrfToken, ENT_QUOTES) ?>">
                                <div class="news-poll-options">
                                    <?php foreach ($poll['options'] as $idx => $optLabel): ?>
                                        <label class="news-poll-option">
                                            <input type="radio" name="poll_option" value="<?= $idx ?>" required>
                                            <span class="news-poll-option__custom"></span>
                                            <span class="news-poll-option__text"><?= htmlspecialchars($optLabel, ENT_QUOTES) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="news-poll-card__actions">
                                    <button type="submit" class="btn btn--primary btn--sm"><?= htmlspecialchars(t('Голосовать'), ENT_QUOTES) ?></button>
                                </div>
                            </form>
                            <div class="news-poll-card__results" hidden aria-live="polite"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($isPremium && !empty($slides)): ?>
                <div class="newsdetail-card">
                    <h3 class="newsdetail-card__title"><?= htmlspecialchars(t('Галерея'), ENT_QUOTES) ?></h3>
                    <div class="newsdetail-sidegallery">
                        <?php foreach (array_slice($slides, 0, 4) as $i => $s): ?>
                            <?php
                            // У миниатюр подпись не выводим текстом — она бы
                            // сломала плитку; отдаём её как имя ссылки и подсказку.
                            $thumbLabel = trim((string) ($s['caption'] ?? '')) ?: ($s['alt'] !== '' ? $s['alt'] : t('Фото'));
                            $thumbCredit = trim((string) ($s['credit'] ?? ''));
                            $thumbTitle = $thumbLabel . ($thumbCredit !== '' ? ' — ' . t('Фото:') . ' ' . $thumbCredit : '');
                            ?>
                            <a class="newsdetail-sidegallery__item<?= $i === 0 ? ' newsdetail-sidegallery__item--wide' : '' ?>" href="<?= htmlspecialchars($s['path'], ENT_QUOTES) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($thumbTitle, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($thumbTitle, ENT_QUOTES) ?>"><?= \App\Core\Media::picture((string) $s['path'], (string) $s['alt'], null, null, '', true, '(max-width: 900px) 50vw, 280px') ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a class="newsdetail__btn newsdetail__btn--ghost newsdetail-sidegallery__all" href="<?= htmlspecialchars(Locale::url('news/' . $news['slug'] . '/photos.zip', $lang), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Скачать все фото'), ENT_QUOTES) ?> <?= $dlIcon ?></a>
                </div>
            <?php endif; ?>
            <?php if (!empty($eventMeta)): ?>
                <div class="newsdetail-card">
                    <div class="newsdetail-card__heading">
                        <span class="newsdetail-card__heading-icon" aria-hidden="true"><?= \App\Core\Icon::render('calendar-event', 20, 'ui-icon', 1.7) ?></span>
                        <h3 class="newsdetail-card__title"><?= htmlspecialchars(t('О мероприятии'), ENT_QUOTES) ?></h3>
                    </div>
                    <ul class="newsdetail-event">
                        <?php foreach ($eventMeta as $i => $line): ?>
                            <?php
                            $eventParts = array_map('trim', explode(':', $line, 2));
                            $eventLabel = count($eventParts) === 2 ? $eventParts[0] : '';
                            $eventValue = count($eventParts) === 2 ? $eventParts[1] : $eventParts[0];
                            ?>
                            <li class="newsdetail-event__item">
                                <span class="newsdetail-event__icon"><?= $eventIcons[$i % count($eventIcons)] ?></span>
                                <span class="newsdetail-event__body">
                                    <?php if ($eventLabel !== ''): ?><strong class="newsdetail-event__label"><?= htmlspecialchars($eventLabel, ENT_QUOTES) ?></strong><?php endif; ?>
                                    <span><?= htmlspecialchars($eventValue, ENT_QUOTES) ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($docs)): ?>
                <div class="newsdetail-card">
                    <div class="newsdetail-card__heading">
                        <span class="newsdetail-card__heading-icon" aria-hidden="true"><?= \App\Core\Icon::render('file-stack', 20, 'ui-icon', 1.7) ?></span>
                        <h3 class="newsdetail-card__title"><?= htmlspecialchars(t('Документы'), ENT_QUOTES) ?></h3>
                    </div>
                    <div class="newsdetail-docs">
                        <?php foreach ($docs as $doc): ?>
                            <?php $du = trim((string) ($doc['url'] ?? '')); ?>
                            <a class="newsdetail-doc" href="<?= htmlspecialchars($du, ENT_QUOTES) ?>" <?= $du !== '' ? 'download' : '' ?>>
                                <span class="newsdetail-doc__icon"><?= $docIcon ?></span>
                                <span class="newsdetail-doc__body">
                                    <span class="newsdetail-doc__title"><?= htmlspecialchars((string) ($doc['title'] ?? ''), ENT_QUOTES) ?></span>
                                    <?php if (!empty($doc['meta'])): ?><span class="newsdetail-doc__meta"><?= htmlspecialchars((string) $doc['meta'], ENT_QUOTES) ?></span><?php endif; ?>
                                </span>
                                <span class="newsdetail-doc__dl"><?= $dlIcon ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php
            // Виджеты из «Настройки › Виджеты»: колонка выбрана в поле «Макет
            // страницы с виджетами» у новости. Раньше здесь выводились обе
            // колонки сразу, мимо этой настройки и мимо nonce для CSP.
            // Форма подписки — дефолт «из коробки»: она показывается, пока в
            // выбранной колонке нет активных виджетов. При макете «Без
            // сайдбара» колонки нет, поэтому нет и её.
            $sidebarWidgets = trim((string) ($sidebar['html'] ?? ''));
            if ($sidebarWidgets !== '') {
                echo $sidebarWidgets;
            } elseif ($sidebar !== null) {
            ?>
                <div class="newsdetail-subscribe newsdetail-subscribe--fallback no-print">
                    <h3 class="newsdetail-subscribe__title"><?= htmlspecialchars(t('Подпишитесь на новости Агентства'), ENT_QUOTES) ?></h3>
                    <p class="newsdetail-subscribe__text"><?= htmlspecialchars(t('Получайте главные новости и аналитику на почту.'), ENT_QUOTES) ?></p>
                    <form class="newsdetail-subscribe__form" method="post" action="<?= htmlspecialchars(Locale::url('subscribe', $lang), ENT_QUOTES) ?>">
                        <?= \App\Core\Csrf::field() ?>
                        <?= \App\Core\Csrf::honeypotField() ?>
                        <input type="hidden" name="source" value="news">
                        <div class="newsdetail-subscribe__row">
                            <label class="visually-hidden" for="nd-sub-email"><?= htmlspecialchars(t('Ваш e-mail'), ENT_QUOTES) ?></label>
                            <input id="nd-sub-email" type="email" name="email" required placeholder="<?= htmlspecialchars(t('Ваш e-mail'), ENT_QUOTES) ?>" autocomplete="email">
                            <button type="submit" aria-label="<?= htmlspecialchars(t('Подписаться'), ENT_QUOTES) ?>">→</button>
                        </div>
                        <?php if (Setting::get('form_consent_enabled', '0') === '1'): ?>
                            <label class="newsdetail-subscribe__consent">
                                <input type="checkbox" name="consent" value="1" required>
                                <span><?= htmlspecialchars((string) Setting::get('form_consent_text', t('Я даю согласие на обработку персональных данных')), ENT_QUOTES) ?></span>
                            </label>
                        <?php endif; ?>
                    </form>
                </div>
            <?php } ?>
        </aside>
    </div>

    <?php
    // У видеоновости обложка уже используется как постер плеера и повторно в
    // фотоленту не попадает. Остальные прикреплённые изображения остаются
    // самостоятельной фотогалереей под статьёй.
    $photoStripSlides = $slides;
    if ($layout === 'video' && $cover !== '') {
        $photoStripSlides = array_values(array_filter(
            $slides,
            static fn (array $slide): bool => $slide['path'] !== $cover
        ));
    }
    // Если макет новости «gallery» — все фото уже показаны в верхнем слайдере.
    $showPhotoStrip = !$isPremium
        && $layout !== 'gallery'
        && ($layout === 'video' ? $photoStripSlides !== [] : count($photoStripSlides) > 1);
    ?>
    <?php if ($showPhotoStrip): ?>
        <section class="newsdetail-photos">
            <div class="section-head">
                <h2 class="section-head__title"><?= htmlspecialchars(t('Фотогалерея'), ENT_QUOTES) ?></h2>
                <a class="newsdetail-dl-btn" href="<?= htmlspecialchars(Locale::url('news/' . $news['slug'] . '/photos.zip', $lang), ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Скачать все фото архивом ZIP'), ENT_QUOTES) ?>">
                    <span class="newsdetail-dl-btn__icon"><?= $dlIcon ?></span>
                    <span class="newsdetail-dl-btn__text"><?= htmlspecialchars(t('Скачать архив ZIP'), ENT_QUOTES) ?></span>
                </a>
            </div>
            <div class="newsdetail-photos__grid">
                <?php foreach (array_slice($photoStripSlides, 0, 8) as $s): ?>
                    <figure class="newsdetail-photos__figure">
                        <a class="newsdetail-photos__item" href="<?= htmlspecialchars($s['path'], ENT_QUOTES) ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($s['alt'] !== '' ? $s['alt'] : t('Фото'), ENT_QUOTES) ?>"><?= \App\Core\Media::picture((string) $s['path'], (string) $s['alt'], null, null, '', true, '(max-width: 560px) 100vw, (max-width: 1000px) 50vw, 25vw') ?></a>
                        <?= $photoCaption($s) ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($related)): ?>
        <section class="newsdetail-related no-print">
            <div class="section-head">
                <h2 class="section-head__title"><?= htmlspecialchars(t('Другие новости по теме'), ENT_QUOTES) ?></h2>
                <a class="section-head__all" href="<?= htmlspecialchars(Locale::url('news'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Все новости'), ENT_QUOTES) ?> →</a>
            </div>
            <div class="newsdetail-related__grid">
                <?php
                // Рубрики всех похожих новостей одним запросом — карточек здесь
                // несколько, и запрос на каждую был бы N+1.
                $relatedCategories = \App\Models\NewsCategory::namesForIds(
                    array_map(static fn (array $item): int => (int) ($item['category_id'] ?? 0), $related),
                    $lang
                );
                ?>
                <?php foreach ($related as $item): ?>
                    <?php
                    $rc = News::getCoverImage($item);
                    $relCategory = (int) ($item['category_id'] ?? 0);
                    $relCategoryName = $relCategory > 0 ? (string) ($relatedCategories[$relCategory] ?? '') : '';
                    ?>
                    <a class="relnews-card" href="<?= htmlspecialchars(Locale::url('news/' . $item['slug'], $lang), ENT_QUOTES) ?>">
                        <?php if ($rc !== null): ?>
                            <?= \App\Core\Media::picture($rc, (string) $item['title'], null, null, 'relnews-card__img', true, '(max-width: 700px) 100vw, 33vw', false, 'relnews-card__media') ?>
                        <?php else: ?>
                            <span class="relnews-card__media relnews-card__media--empty" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php if (!empty($item['published_at']) || $relCategoryName !== ''): ?>
                            <span class="news-meta">
                                <?php if (!empty($item['published_at'])): ?><time class="relnews-card__date"><?= htmlspecialchars(DateFormatter::short((string) $item['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                                <?php if ($relCategoryName !== ''): ?><span class="news-category"><?= htmlspecialchars($relCategoryName, ENT_QUOTES) ?></span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <h3 class="relnews-card__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($prevNews !== null || $nextNews !== null || ($isPremium && !empty($toc))): ?>
        <nav class="newsdetail-adjacent no-print<?= $isPremium && !empty($toc) ? ' newsdetail-adjacent--with-toc' : '' ?>" aria-label="<?= htmlspecialchars(t('Соседние новости'), ENT_QUOTES) ?>">
            <?php if ($isPremium && !empty($toc)): ?>
                <div class="newsdetail-toc">
                    <span class="newsdetail-toc__title"><?= htmlspecialchars(t('Навигация по статье'), ENT_QUOTES) ?></span>
                    <ol class="newsdetail-toc__list">
                        <?php foreach ($toc as $i => $item): ?>
                            <li><a href="#<?= htmlspecialchars($item['id'], ENT_QUOTES) ?>"><span class="newsdetail-toc__num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><?= htmlspecialchars($item['label'], ENT_QUOTES) ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>
            <?php if ($prevNews !== null): ?>
                <?php $pc = News::getCoverImage($prevNews); ?>
                <a class="adjnews adjnews--prev" href="<?= htmlspecialchars(Locale::url('news/' . $prevNews['slug'], $lang), ENT_QUOTES) ?>">
                    <span class="adjnews__arrow">←</span>
                    <?php if ($pc !== null): ?>
                        <?= \App\Core\Media::picture($pc, (string) $prevNews['title'], null, null, 'adjnews__media', true, '112px') ?>
                    <?php else: ?>
                        <span class="adjnews__media adjnews__media--empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="adjnews__body">
                        <span class="adjnews__meta">
                            <span class="adjnews__label"><?= htmlspecialchars(t('Предыдущая новость'), ENT_QUOTES) ?></span>
                            <?php if (!empty($prevNews['published_at'])): ?><time class="adjnews__date"><?= htmlspecialchars(DateFormatter::short((string) $prevNews['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                        </span>
                        <h3 class="adjnews__title"><?= htmlspecialchars((string) $prevNews['title'], ENT_QUOTES) ?></h3>
                    </span>
                </a>
            <?php else: ?><span class="adjnews adjnews--empty"></span><?php endif; ?>
            <?php if ($nextNews !== null): ?>
                <?php $nc = News::getCoverImage($nextNews); ?>
                <?php // Порядок зеркальный: текст, кадр, стрелка — карточка «дальше»
                      // читается справа налево и повторяет соседнюю как отражение. ?>
                <a class="adjnews adjnews--next" href="<?= htmlspecialchars(Locale::url('news/' . $nextNews['slug'], $lang), ENT_QUOTES) ?>">
                    <span class="adjnews__body">
                        <span class="adjnews__meta">
                            <span class="adjnews__label"><?= htmlspecialchars(t('Следующая новость'), ENT_QUOTES) ?></span>
                            <?php if (!empty($nextNews['published_at'])): ?><time class="adjnews__date"><?= htmlspecialchars(DateFormatter::short((string) $nextNews['published_at']), ENT_QUOTES) ?></time><?php endif; ?>
                        </span>
                        <h3 class="adjnews__title"><?= htmlspecialchars((string) $nextNews['title'], ENT_QUOTES) ?></h3>
                    </span>
                    <?php if ($nc !== null): ?>
                        <?= \App\Core\Media::picture($nc, (string) $nextNews['title'], null, null, 'adjnews__media', true, '112px') ?>
                    <?php else: ?>
                        <span class="adjnews__media adjnews__media--empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="adjnews__arrow">→</span>
                </a>
            <?php else: ?><span class="adjnews adjnews--empty"></span><?php endif; ?>
        </nav>
    <?php endif; ?>
</article>
<?php // Schema.org: карточка новости для поисковиков (Google Discover, Yandex). ?>
<?= \App\Core\SchemaOrg::render(\App\Core\SchemaOrg::newsArticle(
    (string) $news['title'],
    $pageUrl,
    (string) ($news['published_at'] ?? ''),
    (string) ($news['excerpt'] ?? ''),
    $ogImage !== '' ? $base . $ogImage : '',
    \App\Models\Setting::get('site_name', ''),
    (string) ($news['updated_at'] ?? $news['published_at'] ?? ''),
    (string) ($news['author'] ?? \App\Models\Setting::get('site_name', '')),
    $lang,
    (string) ($newsCategory['name'] ?? '')
)) ?>
<div class="reader-mode-overlay" id="reader-mode-overlay" hidden role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars(t('Режим чтения'), ENT_QUOTES) ?>">
    <div class="reader-mode-bar">
        <div class="reader-mode-bar__progress" id="reader-progress" role="progressbar" aria-label="<?= htmlspecialchars(t('Прогресс чтения'), ENT_QUOTES) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
        <div class="reader-mode-bar__inner">
            <div class="reader-mode-bar__title"><?= htmlspecialchars((string) $news['title'], ENT_QUOTES) ?></div>
            <div class="reader-mode-controls">
                <div class="reader-mode-themes">
                    <button type="button" class="reader-theme-btn reader-theme-btn--light is-active" data-reader-theme="light" title="<?= htmlspecialchars(t('Светлая тема'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Светлая тема'), ENT_QUOTES) ?>" aria-pressed="true"><?= \App\Core\Icon::render('sun', 18) ?></button>
                    <button type="button" class="reader-theme-btn reader-theme-btn--sepia" data-reader-theme="sepia" title="<?= htmlspecialchars(t('Сепия'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Сепия'), ENT_QUOTES) ?>" aria-pressed="false"><?= \App\Core\Icon::render('book', 18) ?></button>
                    <button type="button" class="reader-theme-btn reader-theme-btn--dark" data-reader-theme="dark" title="<?= htmlspecialchars(t('Тёмная тема'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Тёмная тема'), ENT_QUOTES) ?>" aria-pressed="false"><?= \App\Core\Icon::render('moon', 18) ?></button>
                </div>
                <div class="reader-mode-fonts">
                    <button type="button" class="reader-font-btn" data-reader-font="dec" title="<?= htmlspecialchars(t('Уменьшить шрифт'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Уменьшить шрифт'), ENT_QUOTES) ?>">A-</button>
                    <button type="button" class="reader-font-btn" data-reader-font="inc" title="<?= htmlspecialchars(t('Увеличить шрифт'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Увеличить шрифт'), ENT_QUOTES) ?>">A+</button>
                </div>
                <button type="button" class="reader-mode-close" data-reader-close aria-label="<?= htmlspecialchars(t('Закрыть режим чтения'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('x', 20) ?></button>
            </div>
        </div>
    </div>
    <div class="reader-mode-container">
        <article class="reader-mode-content">
            <h1 class="reader-mode__headline"><?= htmlspecialchars((string) $news['title'], ENT_QUOTES) ?></h1>
            <div class="reader-mode__submeta">
                <?php if ($dateLong !== ''): ?><span><?= htmlspecialchars($dateLong, ENT_QUOTES) ?></span> • <?php endif; ?>
                <span><?= $readMin ?> <?= htmlspecialchars(t('мин чтения'), ENT_QUOTES) ?></span>
            </div>
            <?php if ($leadHtml !== ''): ?>
                <div class="reader-mode__lead news-lead-rich"><?= $leadHtml ?></div>
            <?php endif; ?>
            <?php if ($cover !== ''): ?>
                <?php // Оверлей скрыт до открытия — обложке нужен именно lazy:
                      // так она не конкурирует за канал с LCP основной статьи. ?>
                <?= \App\Core\Media::picture(
                    $cover,
                    (string) $news['title'],
                    null,
                    null,
                    'reader-mode__cover',
                    true
                ) ?>
            <?php endif; ?>
            <?php // Тело статьи НЕ дублируется в разметке: раньше $contentHtml
                  // печатался здесь второй раз, удваивая HTML и число DOM-узлов
                  // на самых посещаемых страницах сайта. Теперь содержимое
                  // клонируется из основной статьи при первом открытии режима
                  // чтения (frontend.js). Без JS оверлей всё равно не
                  // открывается, поэтому прогрессивное улучшение не страдает. ?>
            <div class="reader-mode__body rich-content"
                 data-reader-body
                 data-reader-source=".newsdetail-article__content"></div>
        </article>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
