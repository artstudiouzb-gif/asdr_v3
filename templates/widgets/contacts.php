<?php
/** @var array $data */
$phone = $data['phone'] ?? '';
$email = $data['email'] ?? '';
$address = $data['address'] ?? '';
$social = $data['social'] ?? [];
$showSocials = !empty($data['show_socials']);
?>
<div class="widget-contacts">
    <?php if ($phone !== ''): ?>
        <p class="widget-contacts__line"><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone) ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($phone, ENT_QUOTES) ?></a></p>
    <?php endif; ?>
    <?php if ($email !== ''): ?>
        <p class="widget-contacts__line"><a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES) ?>"><?= htmlspecialchars($email, ENT_QUOTES) ?></a></p>
    <?php endif; ?>
    <?php if ($address !== ''): ?>
        <p class="widget-contacts__line"><?= htmlspecialchars($address, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($showSocials && !empty($social)): ?>
        <div class="widget-contacts__social">
            <?php foreach ($social as $btn): ?>
                <a href="<?= htmlspecialchars($btn['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener" class="widget-social-link widget-social-link--<?= htmlspecialchars($btn['network'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars(ucfirst($btn['network']), ENT_QUOTES) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
