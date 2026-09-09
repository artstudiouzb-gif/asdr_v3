<?php

use App\Core\Icon;

/**
 * Карточка руководителя: слева портрет и контакты, справа вкладки.
 *
 * Вкладки — прогрессивное улучшение. Сервер отдаёт обычные разделы с
 * заголовками и якорные ссылки на них: без JS посетитель видит всё содержимое
 * сразу, а ссылки просто прокручивают к разделу. Роли вкладок расставляет JS
 * (blocks/leader_card.js) — объявлять их в разметке нельзя, иначе без скрипта
 * скринридер обещает переключение, которого не произойдёт.
 *
 * @var array $data
 * @var int $blockId
 */
$photo = trim((string) ($data['photo'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$position = trim((string) ($data['position'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$hours = trim((string) ($data['hours'] ?? ''));

$socials = [];
foreach ([
    'facebook' => ['brand-facebook', 'Facebook'],
    'x' => ['brand-x', 'X'],
    'linkedin' => ['brand-linkedin', 'LinkedIn'],
    'instagram' => ['brand-instagram', 'Instagram'],
    'telegram' => ['brand-telegram', 'Telegram'],
] as $key => [$icon, $label]) {
    $url = trim((string) ($data[$key] ?? ''));
    if ($url !== '') {
        $socials[] = ['url' => $url, 'icon' => $icon, 'label' => $label];
    }
}

// Вкладка показывается, только если в ней что-то есть: пустая вкладка —
// обещание содержимого, которого нет.
$facts = array_values(array_filter(
    (array) ($data['items'] ?? []),
    static fn (array $row): bool => trim((string) ($row['label'] ?? '')) !== '' || trim((string) ($row['value'] ?? '')) !== ''
));
$bio = trim((string) ($data['bio'] ?? ''));
$duties = trim((string) ($data['duties'] ?? ''));

$tabs = [];
if ($facts !== []) {
    $tabs[] = ['key' => 'facts', 'title' => trim((string) ($data['facts_title'] ?? '')) ?: t('Основная информация'), 'icon' => trim((string) ($data['facts_icon'] ?? ''))];
}
if ($bio !== '') {
    $tabs[] = ['key' => 'bio', 'title' => trim((string) ($data['bio_title'] ?? '')) ?: t('Биография'), 'icon' => trim((string) ($data['bio_icon'] ?? ''))];
}
if ($duties !== '') {
    $tabs[] = ['key' => 'duties', 'title' => trim((string) ($data['duties_title'] ?? '')) ?: t('Функции'), 'icon' => trim((string) ($data['duties_icon'] ?? ''))];
}

// Мобильный режим «только иконки» применяем лишь когда иконка есть у каждой
// показанной вкладки. Иначе одна из кнопок стала бы визуально пустой.
$iconsOnly = !empty($data['mobile_icons_only'])
    && $tabs !== []
    && array_filter($tabs, static fn (array $tab): bool => $tab['icon'] === '') === [];

// Тег имени и уровень заголовков вкладок связаны: если имя — заголовок, то
// разделы карточки должны идти ровно на уровень ниже.
// Значение проверено схемой полей (BlockFieldSchema) — читаем как есть.
$nameTag = (string) $data['name_tag'];
$panelTag = $nameTag === 'h3' ? 'h4' : 'h3';

$panelId = static fn (string $key): string => 'leader-' . (int) $blockId . '-' . $key;
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
?>
<div class="leader-card<?= $iconsOnly ? ' leader-card--mobile-icons' : '' ?>" data-tab-count="<?= count($tabs) ?>"<?= $tabs !== [] ? ' data-leader-card' : '' ?>>
    <div class="leader-card__side">
        <?php if ($photo !== ''): ?>
            <div class="leader-card__photo">
                <?= \App\Core\Media::picture($photo, $name, null, null, 'leader-card__img', false, '(max-width: 720px) 70vw, 320px') ?>
            </div>
        <?php endif; ?>

        <div class="leader-card__ident">
            <?php if ($name !== ''): ?><<?= $nameTag ?> class="leader-card__name"><?= $esc($name) ?></<?= $nameTag ?>><?php endif; ?>
            <?php if ($position !== ''): ?><p class="leader-card__position"><?= nl2br($esc($position)) ?></p><?php endif; ?>
        </div>

        <div class="leader-card__info">
        <?php if ($phone !== '' || $email !== ''): ?>
            <ul class="leader-card__contacts">
                <?php if ($phone !== ''): ?>
                    <li class="leader-card__contact">
                        <span class="leader-card__contact-icon" aria-hidden="true"><?= Icon::render('phone', 20) ?></span>
                        <a href="tel:<?= $esc(preg_replace('/[^0-9+]/', '', $phone) ?? '') ?>"><?= $esc($phone) ?></a>
                    </li>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                    <li class="leader-card__contact">
                        <span class="leader-card__contact-icon" aria-hidden="true"><?= Icon::render('mail', 20) ?></span>
                        <a href="mailto:<?= $esc($email) ?>"><?= $esc($email) ?></a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($socials !== []): ?>
            <ul class="leader-card__socials">
                <?php foreach ($socials as $social): ?>
                    <li>
                        <a class="leader-card__social" href="<?= $esc($social['url']) ?>" target="_blank" rel="noopener noreferrer">
                            <span aria-hidden="true"><?= Icon::render($social['icon'], 18) ?></span>
                            <span class="visually-hidden"><?= $esc($social['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($hours !== ''): ?>
            <p class="leader-card__hours">
                <span class="leader-card__contact-icon" aria-hidden="true"><?= Icon::render('clock', 20) ?></span>
                <?= $esc(t('Приёмные часы')) ?>: <?= $esc($hours) ?>
            </p>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($tabs !== []): ?>
        <div class="leader-card__main">
            <nav class="leader-card__tabs" data-leader-tablist aria-label="<?= $esc(t('Разделы карточки')) ?>">
                <span class="leader-card__tab-indicator" aria-hidden="true"></span>
                <?php foreach ($tabs as $index => $tab): ?>
                    <a class="leader-card__tab<?= $index === 0 ? ' is-active' : '' ?>"
                       href="#<?= $esc($panelId($tab['key'])) ?>"
                       <?= $iconsOnly ? 'title="' . $esc($tab['title']) . '" ' : '' ?>data-leader-tab="<?= $esc($tab['key']) ?>">
                        <?php if ($tab['icon'] !== ''): ?>
                            <span class="leader-card__tab-icon" aria-hidden="true"><?= Icon::render($tab['icon'], 20) ?></span>
                        <?php endif; ?>
                        <span class="leader-card__tab-text"><?= $esc($tab['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($tabs as $tab): ?>
                <section class="leader-card__panel" id="<?= $esc($panelId($tab['key'])) ?>" data-leader-panel="<?= $esc($tab['key']) ?>">
                    <<?= $panelTag ?> class="leader-card__panel-title"><?= $esc($tab['title']) ?></<?= $panelTag ?>>

                    <?php if ($tab['key'] === 'facts'): ?>
                        <dl class="leader-card__facts">
                            <?php foreach ($facts as $row): ?>
                                <div class="leader-card__fact">
                                    <dt class="leader-card__fact-label">
                                        <?php if (trim((string) ($row['icon_svg'] ?? '')) !== ''): ?>
                                            <span class="leader-card__fact-icon" aria-hidden="true"><?= Icon::render((string) $row['icon_svg'], 18) ?></span>
                                        <?php endif; ?>
                                        <?= $esc((string) ($row['label'] ?? '')) ?>
                                    </dt>
                                    <dd class="leader-card__fact-value"><?= nl2br($esc((string) ($row['value'] ?? ''))) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    <?php elseif ($tab['key'] === 'bio'): ?>
                        <div class="leader-card__rich"><?= $bio ?></div>
                    <?php else: ?>
                        <div class="leader-card__rich"><?= $duties ?></div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
