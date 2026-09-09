<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Locale;
use App\Models\Setting;

/** @var array $data */
/** @var string $lang */

$subTitle = !empty($data['title']) ? (string) $data['title'] : t('Подпишитесь на новости Агентства');
$subText = !empty($data['text']) ? (string) $data['text'] : t('Получайте главные новости и аналитику на почту.');
?>
<div class="newsdetail-subscribe widget-subscribe-card">
    <h3 class="newsdetail-subscribe__title"><?= htmlspecialchars(t($subTitle), ENT_QUOTES) ?></h3>
    <p class="newsdetail-subscribe__text"><?= htmlspecialchars(t($subText), ENT_QUOTES) ?></p>
    <form class="newsdetail-subscribe__form" method="post" action="<?= htmlspecialchars(Locale::url('subscribe', $lang), ENT_QUOTES) ?>">
        <?= Csrf::field() ?>
        <?= Csrf::honeypotField() ?>
        <input type="hidden" name="source" value="widget">
        <div class="newsdetail-subscribe__row">
            <label class="visually-hidden" for="w-sub-email"><?= htmlspecialchars(t('Ваш e-mail'), ENT_QUOTES) ?></label>
            <input id="w-sub-email" type="email" name="email" required placeholder="<?= htmlspecialchars(t('Ваш e-mail'), ENT_QUOTES) ?>" autocomplete="email">
            <button type="submit" aria-label="<?= htmlspecialchars(t('Подписаться'), ENT_QUOTES) ?>">
                <?= \App\Core\Icon::render('arrow-right', 16, 'subscribe-widget__submit-icon', 2.5) ?>
            </button>
        </div>
        <?php if (Setting::get('form_consent_enabled', '0') === '1'): ?>
            <label class="newsdetail-subscribe__consent">
                <input type="checkbox" name="consent" value="1" required>
                <span><?= htmlspecialchars((string) Setting::get('form_consent_text', t('Я даю согласие на обработку персональных данных')), ENT_QUOTES) ?></span>
            </label>
        <?php endif; ?>
    </form>
</div>
