<?php
/** @var array $data */
$photo = trim((string) ($data['photo'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$btnText = trim((string) ($data['button_text'] ?? ''));
$btnUrl = trim((string) ($data['button_url'] ?? ''));
$btn2Text = trim((string) ($data['button2_text'] ?? ''));
$btn2Url = trim((string) ($data['button2_url'] ?? ''));
$hasContacts = $phone !== '' || $email !== '';
// Значение проверено схемой полей (BlockFieldSchema) — читаем как есть.
$photoSide = (string) $data['photo_side'];

// Соцсети: показываем только заполненные и только с безопасным адресом.
$socialIcons = [
    'telegram' => 'brand-telegram',
    'facebook' => 'brand-facebook',
    'linkedin' => 'brand-linkedin',
    'x' => 'brand-x',
    'instagram' => 'brand-instagram',
];
$socialLabels = [
    'telegram' => 'Telegram',
    'facebook' => 'Facebook',
    'linkedin' => 'LinkedIn',
    'x' => 'X',
    'instagram' => 'Instagram',
];
$socials = [];
foreach ($socialIcons as $key => $icon) {
    $url = trim((string) ($data[$key] ?? ''));
    if ($url === '' || !\App\Core\UrlGuard::isSafeLink($url)) {
        continue;
    }
    $socials[] = ['url' => $url, 'icon' => $icon, 'label' => $socialLabels[$key]];
}
?>
<div class="block-profile block-profile--photo-<?= $photoSide ?>">
    <div class="profile__media">
        <?php if ($photo !== ''): ?>
            <?= \App\Core\Media::picture($photo, (string) ($data['name'] ?? ''), null, null, 'profile__img', false, '(max-width: 700px) 100vw, 35vw', false, 'profile__photo') ?>
        <?php else: ?>
            <?php // Портрета нет — вместо серого поля фирменная эмблема из CSS. ?>
            <span class="profile__photo profile__photo--empty" aria-hidden="true"></span>
        <?php endif; ?>
    </div>
    <div class="profile__info">
        <?php if (!empty($data['name'])): ?><?php $hTag = $data['_heading_tag'] ?? 'h1'; ?><<?= $hTag ?> class="profile__name"><?= htmlspecialchars((string) $data['name'], ENT_QUOTES) ?></<?= $hTag ?>><?php endif; ?>
        <?php if (!empty($data['position'])): ?><div class="profile__position"><?= htmlspecialchars((string) $data['position'], ENT_QUOTES) ?></div><?php endif; ?>
        <?php if (!empty($data['text'])): ?>
            <div class="profile__text rich-content">
                <?= \App\Core\HtmlSanitizer::sanitize((string) $data['text']) ?>
            </div>
        <?php endif; ?>
        <?php if ($hasContacts): ?><div class="profile__contacts">
            <?php if ($phone !== ''): ?>
                <span class="profile__contact">
                    <?= \App\Core\Icon::render('phone', 17, 'profile__contact-icon', 1.6) ?>
                    <span class="profile__contact-label"><?= htmlspecialchars((string) ($data['phone_label'] ?? ''), ENT_QUOTES) ?></span>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^+\d]/', '', $phone) ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($phone, ENT_QUOTES) ?></a>
                </span>
            <?php endif; ?>
            <?php if ($email !== ''): ?>
                <span class="profile__contact">
                    <?= \App\Core\Icon::render('mail', 17, 'profile__contact-icon', 1.6) ?>
                    <span class="profile__contact-label"><?= htmlspecialchars((string) ($data['email_label'] ?? ''), ENT_QUOTES) ?></span>
                    <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES) ?>"><?= htmlspecialchars($email, ENT_QUOTES) ?></a>
                </span>
            <?php endif; ?>
        </div><?php endif; ?>
        <?php if ($socials !== []): ?>
            <div class="profile__socials">
                <?php foreach ($socials as $social): ?>
                    <a class="profile__social" href="<?= htmlspecialchars($social['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener me" aria-label="<?= htmlspecialchars($social['label'], ENT_QUOTES) ?>">
                        <?= \App\Core\Icon::render($social['icon'], 20) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (($btnText !== '' && $btnUrl !== '') || ($btn2Text !== '' && $btn2Url !== '')): ?>
            <div class="profile__buttons">
                <?php if ($btnText !== '' && $btnUrl !== ''): ?>
                    <a class="profile__button" href="<?= htmlspecialchars($btnUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($btnText, ENT_QUOTES) ?> →</a>
                <?php endif; ?>
                <?php if ($btn2Text !== '' && $btn2Url !== ''): ?>
                    <a class="profile__button profile__button--secondary" href="<?= htmlspecialchars($btn2Url, ENT_QUOTES) ?>"><?= htmlspecialchars($btn2Text, ENT_QUOTES) ?> →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
