<?php

use App\Core\CalendarGrid;
use App\Core\Locale;
use App\Models\ContentType;

/** @var array $type */
/** @var array $weeks */
/** @var array $byDate */
/** @var string $calLabel (не $label — его затирает _header.php) */
/** @var string $prevMonth */
/** @var string $nextMonth */
/** @var string $today */
/** @var array $weekdays */

$metaTitle = t('Календарь мероприятий');
$metaDescription = (string) ($type['description'] ?? '');
require __DIR__ . '/_header.php';

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Мероприятия'), 'url' => Locale::url(ContentType::path($type))],
    ['label' => t('Календарь')],
];
require __DIR__ . '/_crumbs.php';

$calUrl = Locale::url('calendar');
$entryUrl = static fn (array $e): string => Locale::url(ContentType::entryPath($type, (string) $e['slug']));
?>
<div class="listing">
    <div class="listing__head">
        <h1 class="listing__title"><?= htmlspecialchars(t('Календарь мероприятий'), ENT_QUOTES) ?></h1>
        <?php if (!empty($type['description'])): ?>
            <p class="listing__lead"><?= htmlspecialchars((string) $type['description'], ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>

    <div class="gcal-nav">
        <a class="gcal-nav__btn" href="<?= htmlspecialchars($calUrl . '?m=' . $prevMonth, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Предыдущий месяц'), ENT_QUOTES) ?>">←</a>
        <strong class="gcal-nav__label"><?= htmlspecialchars($calLabel, ENT_QUOTES) ?></strong>
        <a class="gcal-nav__btn" href="<?= htmlspecialchars($calUrl . '?m=' . $nextMonth, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Следующий месяц'), ENT_QUOTES) ?>">→</a>
        <a class="gcal-nav__all" href="<?= htmlspecialchars(Locale::url(ContentType::path($type)), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Списком'), ENT_QUOTES) ?> →</a>
    </div>

    <div class="gcal-scroll">
        <table class="gcal" aria-label="<?= htmlspecialchars(t('Календарь на'), ENT_QUOTES) ?> <?= htmlspecialchars($calLabel, ENT_QUOTES) ?>">
            <thead>
                <tr>
                    <?php foreach ($weekdays as $wd): ?>
                        <th scope="col"><?= $wd ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weeks as $week): ?>
                    <tr>
                        <?php foreach ($week as $cell): ?>
                            <?php if ($cell['day'] === null): ?>
                                <td class="gcal__cell gcal__cell--empty"></td>
                            <?php else: ?>
                                <?php $events = $byDate[$cell['date']] ?? []; ?>
                                <td class="gcal__cell<?= $cell['date'] === $today ? ' gcal__cell--today' : '' ?><?= $events !== [] ? ' gcal__cell--has-events' : '' ?>">
                                    <span class="gcal__day"><?= (int) $cell['day'] ?></span>
                                    <?php foreach ($events as $event): ?>
                                        <a class="gcal__event" href="<?= htmlspecialchars($entryUrl($event), ENT_QUOTES) ?>" title="<?= htmlspecialchars((string) $event['title'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars(mb_strimwidth((string) $event['title'], 0, 40, '…'), ENT_QUOTES) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($byDate !== []): ?>
        <section class="gcal-list">
            <h2 class="gcal-list__title"><?= htmlspecialchars(t('Мероприятия месяца'), ENT_QUOTES) ?></h2>
            <ul class="gcal-list__items">
                <?php foreach ($byDate as $date => $events): ?>
                    <?php foreach ($events as $event): ?>
                        <li class="gcal-list__item">
                            <?php
                            $calendarBanner = trim((string) ($event['data']['banner_image'] ?? ''));
                            if ($calendarBanner !== '' && !\App\Core\UrlGuard::isSafeMedia($calendarBanner)) {
                                $calendarBanner = '';
                            }
                            ?>
                            <?php if ($calendarBanner !== ''): ?>
                                <a class="gcal-list__media" href="<?= htmlspecialchars($entryUrl($event), ENT_QUOTES) ?>" tabindex="-1" aria-hidden="true">
                                    <?= \App\Core\Media::picture(
                                        $calendarBanner,
                                        '',
                                        null,
                                        null,
                                        'gcal-list__image',
                                        true,
                                        '96px'
                                    ) ?>
                                </a>
                            <?php endif; ?>
                            <div class="gcal-list__body">
                            <time datetime="<?= htmlspecialchars((string) $date, ENT_QUOTES) ?>" class="gcal-list__date"><?= htmlspecialchars(date('d.m.Y', (int) strtotime((string) $date)), ENT_QUOTES) ?></time>
                            <?php if (!empty($event['data']['event_time'])): ?>
                                <span class="gcal-list__time"><?= htmlspecialchars((string) $event['data']['event_time'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <a class="gcal-list__link" href="<?= htmlspecialchars($entryUrl($event), ENT_QUOTES) ?>"><?= htmlspecialchars((string) $event['title'], ENT_QUOTES) ?></a>
                            <?php if (!empty($event['data']['location'])): ?>
                                <span class="gcal-list__loc"><?= htmlspecialchars((string) $event['data']['location'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php else: ?>
        <p class="listing__empty"><?= htmlspecialchars(t('В этом месяце мероприятий нет.'), ENT_QUOTES) ?></p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
