<?php

use App\Core\Csrf;
use App\Core\Locale;
use App\Models\Setting;

/** @var array $data */
/** @var int $blockId */
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$image = trim((string) ($data['image'] ?? ''));
if ($variant === 'image' && $image === '') {
    // Без картинки вариант «на фоне» превращается в обычную полосу: белый
    // текст на белом фоне читать нельзя.
    $variant = 'band';
}
$note = trim((string) ($data['note'] ?? ''));
$placeholder = trim((string) ($data['placeholder'] ?? '')) !== ''
    ? (string) $data['placeholder']
    : t('Ваш e-mail');

// Фоновое изображение — в scoped CSS блока: инлайн-стили в блоках запрещены
// тестами и требовали бы послабления в CSP.
$templateCss = '';
if ($variant === 'image') {
    $templateCss = '#block-' . (int) $blockId . ' .subscribe-block{--subscribe-image:url("'
        . str_replace(['"', '\\'], '', \App\Core\Asset::url($image)) . '")}';
}
?>
<div class="container">
    <div class="subscribe-block subscribe-block--<?= htmlspecialchars($variant, ENT_QUOTES) ?>">
        <?php if (!empty($data['title'])): ?>
            <h2 class="subscribe-block__title"><?= htmlspecialchars((string) $data['title'], ENT_QUOTES) ?></h2>
        <?php endif; ?>
        <?php if (!empty($data['text'])): ?>
            <p class="subscribe-block__text"><?= htmlspecialchars((string) $data['text'], ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form class="subscribe-block__form" method="post" action="<?= htmlspecialchars(Locale::url('subscribe'), ENT_QUOTES) ?>">
            <?= Csrf::field() ?>
            <?= Csrf::honeypotField() ?>
            <input type="hidden" name="source" value="block">
            <label class="visually-hidden" for="subscribe-email-<?= (int) $blockId ?>">Email</label>
            <input type="email" id="subscribe-email-<?= (int) $blockId ?>" name="email" placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>" autocomplete="email" required>
            <button type="submit" class="btn btn--primary"><?= htmlspecialchars((string) ($data['button_text'] ?: t('Подписаться')), ENT_QUOTES) ?></button>
            <?php if (Setting::get('form_consent_enabled', '0') === '1'): ?>
                <label class="subscribe-block__consent">
                    <input type="checkbox" name="consent" value="1" required>
                    <span><?= htmlspecialchars((string) Setting::get('form_consent_text', t('Я даю согласие на обработку персональных данных')), ENT_QUOTES) ?></span>
                </label>
            <?php endif; ?>
        </form>
        <?php if ($note !== ''): ?>
            <p class="subscribe-block__note"><?= htmlspecialchars($note, ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>
</div>
