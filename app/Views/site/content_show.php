<?php

use App\Core\ContentFields;
use App\Core\Locale;
use App\Models\ContentType;

/** @var array $type */
/** @var array $fields */
/** @var array $entry */

$metaTitle = (string) $entry['title'];
$metaDescription = '';
$isEvent = (string) ($type['slug'] ?? '') === 'meropriyatiya';
$eventBanner = $isEvent ? trim((string) ($entry['data']['banner_image'] ?? '')) : '';
if ($eventBanner !== '' && !\App\Core\UrlGuard::isSafeMedia($eventBanner)) {
    $eventBanner = '';
}
if ($eventBanner !== '') {
    $ogImage = $eventBanner;
}
// Мета-описание из первого текстового поля, если есть.
foreach ($fields as $f) {
    if (in_array($f['field_type'], ['textarea', 'text'], true)) {
        $raw = (string) ($entry['data'][$f['name']] ?? '');
        if ($raw !== '') {
            $metaDescription = mb_substr(trim(strip_tags($raw)), 0, 200);
            break;
        }
    }
}
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t((string) $type['name']), 'url' => Locale::url(ContentType::path($type))],
    ['label' => (string) $entry['title']],
];
require __DIR__ . '/_crumbs.php';

// Длинные поля (текст/изображение) — в основную колонку, короткие и файлы —
// в боковую карточку «Сведения».
$mainTypes = ['textarea', 'image'];
$mainFields = array_values(array_filter(
    $fields,
    static fn ($f) => in_array($f['field_type'], $mainTypes, true) && $f['name'] !== 'banner_image'
));
$sideFields = array_values(array_filter(
    $fields,
    static fn ($f) => !in_array($f['field_type'], $mainTypes, true) && $f['name'] !== 'banner_image'
));
?>
<article class="catdetail">
    <header class="catdetail__head">
        <span class="catdetail__eyebrow"><?= htmlspecialchars(t((string) $type['name']), ENT_QUOTES) ?></span>
        <h1 class="catdetail__title"><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES) ?></h1>
        <time class="catdetail__date"><?= htmlspecialchars(t('Опубликовано'), ENT_QUOTES) ?> <?= htmlspecialchars(\App\Core\DateFormatter::format((string) $entry['created_at'], 'd.m.Y'), ENT_QUOTES) ?></time>
    </header>

    <?php if ($eventBanner !== ''): ?>
        <figure class="catdetail__event-banner">
            <?= \App\Core\Media::picture(
                $eventBanner,
                (string) $entry['title'],
                null,
                null,
                'catdetail__event-image',
                false,
                '(max-width: 900px) 100vw, 1200px',
                true
            ) ?>
        </figure>
    <?php endif; ?>

    <div class="catdetail__grid">
        <div class="catdetail__body">
            <?php $hasMain = false; ?>
            <?php foreach ($mainFields as $f): ?>
                <?php $val = ContentFields::displayValue($f, $entry['data'][$f['name']] ?? null); ?>
                <?php if ($val === '') { continue; } $hasMain = true; ?>
                <section class="catdetail__section">
                    <h2 class="catdetail__subtitle"><?= htmlspecialchars(t((string) $f['label']), ENT_QUOTES) ?></h2>
                    <div class="catdetail__text rich-content"><?= $val ?></div>
                </section>
            <?php endforeach; ?>
            <?php if (!$hasMain): ?><p class="catdetail__empty"><?= htmlspecialchars(t('Описание не заполнено.'), ENT_QUOTES) ?></p><?php endif; ?>
        </div>

        <aside class="catdetail__aside">
            <div class="catdetail__card">
                <h2 class="catdetail__card-title"><?= htmlspecialchars(t('Сведения'), ENT_QUOTES) ?></h2>
                <dl class="catdetail__facts">
                    <?php foreach ($sideFields as $f): ?>
                        <?php
                        $val = ContentFields::displayValue($f, $entry['data'][$f['name']] ?? null);
                        if ($val === '' || $f['field_type'] === 'file') {
                            continue;
                        }
                        ?>
                        <div class="catdetail__fact">
                            <dt><?= htmlspecialchars(t((string) $f['label']), ENT_QUOTES) ?></dt>
                            <dd><?= $val ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <?php foreach ($sideFields as $f): ?>
                    <?php if ($f['field_type'] === 'file' && !empty($entry['data'][$f['name']])): ?>
                        <a class="catdetail__download" href="<?= htmlspecialchars((string) $entry['data'][$f['name']], ENT_QUOTES) ?>" target="_blank" rel="noopener" download>
                            <?= \App\Core\Icon::render('download', 16, 'ui-icon', 1.8) ?>
                            <?= htmlspecialchars(t((string) $f['label']), ENT_QUOTES) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="catdetail__back" href="<?= htmlspecialchars(Locale::url(ContentType::path($type)), ENT_QUOTES) ?>">← <?= htmlspecialchars(t('Ко всем записям раздела'), ENT_QUOTES) ?></a>
            </div>
        </aside>
    </div>
</article>
<?php // Schema.org: хлебные крошки выводит _crumbs.php; здесь — карточка события. ?>
<?php
$schemaBase = \App\Core\AppUrl::base();
$schemaUrl = static fn (string $p): string => $schemaBase . \App\Core\Locale::url($p);
if ($isEvent) {
    $eventStart = (string) ($entry['data']['event_date'] ?? '');
    if ($eventStart !== '' && !empty($entry['data']['event_time'])) {
        $eventStart .= 'T' . (string) $entry['data']['event_time'];
    }
    $schemaImage = $eventBanner;
    if ($schemaImage !== '' && !preg_match('#^https?://#i', $schemaImage)) {
        $schemaImage = $schemaBase . '/' . ltrim($schemaImage, '/');
    }
    echo \App\Core\SchemaOrg::render(\App\Core\SchemaOrg::event(
        (string) $entry['title'],
        $schemaUrl(ContentType::entryPath($type, (string) $entry['slug'])),
        $eventStart,
        (string) ($entry['data']['location'] ?? ''),
        strip_tags((string) ($entry['data']['summary'] ?? '')),
        $schemaImage
    )), "\n";
}
?>
<?php require __DIR__ . '/_footer.php'; ?>
