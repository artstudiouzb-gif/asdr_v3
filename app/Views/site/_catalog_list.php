<?php

use App\Core\ContentFields;
use App\Core\Locale;
use App\Models\ContentType;

/**
 * Область результатов каталога: счётчик, карточки записей и пагинация.
 * Подключается и целой страницей (content_index.php), и отдельно — как
 * фрагмент для AJAX-фильтрации (ContentController::index), поэтому все
 * производные значения считаются здесь.
 *
 * @var array $type
 * @var array $fields
 * @var array $entries
 * @var string $q
 * @var string $sort
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var bool $hasDeadline
 */
$shortFields = array_values(array_filter($fields, static fn ($f) => in_array($f['field_type'], ['text', 'number', 'date'], true)));
$longFields = array_values(array_filter($fields, static fn ($f) => $f['field_type'] === 'textarea'));
$fileFields = array_values(array_filter($fields, static fn ($f) => $f['field_type'] === 'file'));
$bannerField = array_values(array_filter(
    $fields,
    static fn ($f) => $f['name'] === 'banner_image' && $f['field_type'] === 'image'
))[0] ?? null;
// Типы с датой проведения (мероприятия) получают карточку с датой-плиткой.
$isEvents = array_filter($fields, static fn ($f) => $f['name'] === 'event_date' && $f['field_type'] === 'date') !== [];
$months = match (Locale::current()) {
    'uz' => ['YAN', 'FEV', 'MAR', 'APR', 'MAY', 'IYN', 'IYL', 'AVG', 'SEN', 'OKT', 'NOY', 'DEK'],
    'en' => ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'],
    default => ['ЯНВ', 'ФЕВ', 'МАР', 'АПР', 'МАЙ', 'ИЮН', 'ИЮЛ', 'АВГ', 'СЕН', 'ОКТ', 'НОЯ', 'ДЕК'],
};

$baseUrl = Locale::url(ContentType::path($type));
$qs = static function (array $overrides) use ($q, $sort): string {
    $params = array_filter(array_merge(['q' => $q, 'sort' => $sort === 'new' ? '' : $sort], $overrides), static fn ($v) => $v !== '' && $v !== null);
    return $params === [] ? '' : '?' . http_build_query($params);
};
?>
<?php if (empty($entries)): ?>
    <p class="listing__empty">
        <?= $q !== '' ? t('По вашему запросу ничего не найдено.') : t('В этом разделе пока нет опубликованных записей.') ?>
    </p>
<?php else: ?>
    <p class="catlist-count"><?= htmlspecialchars(t('Найдено:'), ENT_QUOTES) ?> <b><?= (int) $total ?></b></p>
    <div class="catlist<?= $isEvents ? ' catlist--events' : '' ?>">
        <?php foreach ($entries as $entry): ?>
            <?php
            $url = Locale::url(ContentType::entryPath($type, (string) $entry['slug']));
            $banner = $bannerField !== null ? trim((string) ($entry['data']['banner_image'] ?? '')) : '';
            if ($banner !== '' && !\App\Core\UrlGuard::isSafeMedia($banner)) {
                $banner = '';
            }
            ?>
            <article class="catcard<?= !empty($entry['is_archived']) ? ' catcard--archived' : '' ?><?= $banner !== '' ? ' catcard--with-image' : '' ?>">
                <?php if ($isEvents && $banner !== ''): ?>
                    <a class="catcard__event-media" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" tabindex="-1" aria-hidden="true">
                        <?= \App\Core\Media::picture($banner, '', null, null, 'catcard__event-image', true, '(max-width: 560px) 100vw, 180px') ?>
                    </a>
                <?php endif; ?>
                <?php if ($isEvents && !empty($entry['data']['event_date'])): ?>
                    <?php $ts = (int) strtotime((string) $entry['data']['event_date']); ?>
                    <span class="catcard__datebox" aria-hidden="true">
                        <b><?= date('d', $ts) ?></b>
                        <i><?= $months[(int) date('n', $ts) - 1] ?></i>
                        <em><?= date('Y', $ts) ?></em>
                    </span>
                <?php endif; ?>
                <div class="catcard__main">
                    <div class="catcard__top">
                        <span class="catcard__doc-icon" aria-hidden="true">
                            <?= \App\Core\Icon::render($isEvents ? 'calendar' : 'file-description', 18) ?>
                        </span>
                        <?php if ($hasDeadline): ?>
                            <span class="catcard__status<?= !empty($entry['is_archived']) ? ' catcard__status--off' : '' ?>"><?= htmlspecialchars(t(!empty($entry['is_archived']) ? 'Архив' : 'Приём открыт'), ENT_QUOTES) ?></span>
                        <?php endif; ?>
                        <time class="catcard__created"><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $entry['created_at'], 'd.m.Y'), ENT_QUOTES) ?></time>
                    </div>
                    <h2 class="catcard__title"><a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES) ?></a></h2>
                    <?php
                    $meta = [];
                    foreach ($shortFields as $f) {
                        if ($isEvents && $f['name'] === 'event_date') {
                            continue; // уже в плитке даты
                        }
                        $val = ContentFields::displayValue($f, $entry['data'][$f['name']] ?? null);
                        if ($val !== '') {
                            $meta[] = '<div class="catcard__meta-item"><i>' . htmlspecialchars(t((string) $f['label']), ENT_QUOTES) . '</i><span>' . $val . '</span></div>';
                        }
                    }
                    ?>
                    <?php if ($meta !== []): ?><div class="catcard__meta"><?= implode('', $meta) ?></div><?php endif; ?>
                    <?php foreach ($longFields as $f): ?>
                        <?php $val = ContentFields::displayValue($f, $entry['data'][$f['name']] ?? null); ?>
                        <?php if ($val !== ''): ?><p class="catcard__excerpt"><?= htmlspecialchars(mb_substr(trim(strip_tags((string) $val)), 0, 160), ENT_QUOTES) ?></p><?php break; endif; ?>
                    <?php endforeach; ?>
                    <div class="catcard__foot">
                        <?php foreach ($fileFields as $f): ?>
                            <?php if (!empty($entry['data'][$f['name']])): ?>
                                <span class="catcard__file">
                                    <?= \App\Core\Icon::render('file-download', 15, 'ui-icon', 1.8) ?>
                                    <?= htmlspecialchars(t((string) $f['label']), ENT_QUOTES) ?>
                                </span>
                                <?php break; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <a class="catcard__more" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                            <span><?= htmlspecialchars(t('Подробнее'), ENT_QUOTES) ?></span>
                            <span class="catcard__arrow" aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php // Адрес страницы каталога сохраняет поиск и сортировку. ?>
    <?php $pageUrl = static fn (int $p): string => $baseUrl . $qs(['page' => $p > 1 ? $p : null]); ?>
    <?php require __DIR__ . '/_pager.php'; ?>
<?php endif; ?>
