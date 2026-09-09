<?php

/**
 * Панель настроек отображения («для слабовидящих») — выдвижная колонка справа.
 * Разметка отдаётся сервером: она переводится вместе с сайтом, работает
 * при выключенном JS (значения видны) и не требует inline-скриптов (CSP).
 *
 * @var array $a11ySettings — нормализованные настройки из cookie
 */

use App\Core\A11ySettings;
use App\Core\Icon;

$settings = $a11ySettings ?? A11ySettings::DEFAULTS;

/** Кнопка выбора значения: подсвечена, если оно сейчас активно. */
$choice = static function (string $key, string $value, string $label, string $iconHtml = '', string $extraClass = '') use ($settings): string {
    $pressed = ((string) $settings[$key] === $value) ? 'true' : 'false';

    return '<button type="button" class="a11y-choice' . ($extraClass !== '' ? ' ' . $extraClass : '') . '"'
        . ' data-a11y-set="' . htmlspecialchars($key . ':' . $value, ENT_QUOTES) . '"'
        . ' aria-pressed="' . $pressed . '">'
        . ($iconHtml !== '' ? '<span class="a11y-choice__visual" aria-hidden="true">' . $iconHtml . '</span>' : '')
        . '<span class="a11y-choice__label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
        . '</button>';
};

/** Тумблер-переключатель с иконкой и свитчем. */
$toggle = static function (string $key, string $activeVal, string $label, string $iconName) use ($settings): string {
    $pressed = ((string) $settings[$key] === $activeVal) ? 'true' : 'false';

    return '<button type="button" class="a11y-toggle-btn" data-a11y-set="' . htmlspecialchars($key . ':' . $activeVal, ENT_QUOTES) . '" aria-pressed="' . $pressed . '">'
        . '<span class="a11y-toggle-btn__icon" aria-hidden="true">' . Icon::render($iconName, 18) . '</span>'
        . '<span class="a11y-toggle-btn__text">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
        . '<span class="a11y-switch" aria-hidden="true"><span class="a11y-switch__track"></span><span class="a11y-switch__thumb"></span></span>'
        . '</button>';
};
?>
<div class="a11y-backdrop" data-a11y-close hidden></div>
<aside class="a11y-drawer" id="a11y-panel" aria-label="<?= htmlspecialchars(t('Настройки отображения'), ENT_QUOTES) ?>" hidden>
    <div class="a11y-drawer__head">
        <h2 class="a11y-drawer__title">
            <span class="a11y-drawer__title-icon" aria-hidden="true"><?= Icon::render('accessible', 22) ?></span>
            <?= htmlspecialchars(t('Настройки отображения'), ENT_QUOTES) ?>
        </h2>
        <button type="button" class="a11y-drawer__close" data-a11y-close aria-label="<?= htmlspecialchars(t('Закрыть'), ENT_QUOTES) ?>">
            <span aria-hidden="true"><?= Icon::render('x', 20) ?></span>
        </button>
    </div>

    <div class="a11y-drawer__body">
        <!-- 1. РАЗМЕР ТЕКСТА -->
        <section class="a11y-group">
            <div class="a11y-group__head">
                <span class="a11y-group__title">
                    <span class="a11y-group__icon" aria-hidden="true"><?= Icon::render('typography', 18) ?></span>
                    <?= htmlspecialchars(t('Размер текста'), ENT_QUOTES) ?>
                </span>
            </div>
            <?php // Главный элемент панели: им пользуются те, кому мелкое неудобно,
                  // поэтому кнопки крупные, значение читается через комнату, а
                  // подписи под кнопками говорят словами, что произойдёт. ?>
            <div class="a11y-sizer">
                <button type="button" class="a11y-sizer__step" data-a11y-step="-1" aria-label="<?= htmlspecialchars(t('Уменьшить текст'), ENT_QUOTES) ?>">
                    <span class="a11y-sizer__glyph" aria-hidden="true">&minus;</span>
                    <span class="a11y-sizer__caption"><?= htmlspecialchars(t('Уменьшить'), ENT_QUOTES) ?></span>
                </button>
                <output class="a11y-sizer__value" data-a11y-size-value aria-live="polite"><?= (int) $settings['size'] ?>%</output>
                <button type="button" class="a11y-sizer__step" data-a11y-step="1" aria-label="<?= htmlspecialchars(t('Увеличить текст'), ENT_QUOTES) ?>">
                    <span class="a11y-sizer__glyph" aria-hidden="true">+</span>
                    <span class="a11y-sizer__caption"><?= htmlspecialchars(t('Увеличить'), ENT_QUOTES) ?></span>
                </button>
            </div>
            <?php // Ползунок остаётся: он даёт стрелки на клавиатуре и показывает,
                  // насколько далеко значение от краёв — кнопки этого не сообщают. ?>
            <input type="range" class="a11y-sizer__range" data-a11y-range="size"
                   min="<?= A11ySettings::SIZE_MIN ?>" max="<?= A11ySettings::SIZE_MAX ?>" step="<?= A11ySettings::SIZE_STEP ?>"
                   value="<?= (int) $settings['size'] ?>"
                   aria-label="<?= htmlspecialchars(t('Размер текста'), ENT_QUOTES) ?>">
        </section>

        <!-- 2. КОНТРАСТ И ЦВЕТОВАЯ СХЕМА -->
        <section class="a11y-group">
            <div class="a11y-group__head">
                <span class="a11y-group__title">
                    <span class="a11y-group__icon" aria-hidden="true"><?= Icon::render('palette', 18) ?></span>
                    <?= htmlspecialchars(t('Контраст и фон'), ENT_QUOTES) ?>
                </span>
            </div>
            <div class="a11y-group__grid a11y-group__grid--schemes">
                <?= $choice('contrast', 'normal', t('Обычный'), '<span class="a11y-scheme-badge a11y-scheme-badge--normal">A</span>', 'a11y-choice--card a11y-choice--normal') ?>
                <?= $choice('contrast', 'mono', t('Чёрно-белый'), '<span class="a11y-scheme-badge a11y-scheme-badge--mono">A</span>', 'a11y-choice--card a11y-choice--mono') ?>
                <?= $choice('contrast', 'warm', t('Тёплый'), '<span class="a11y-scheme-badge a11y-scheme-badge--warm">A</span>', 'a11y-choice--card a11y-choice--warm') ?>
                <button type="button" class="a11y-choice a11y-choice--card a11y-choice--dark" data-a11y-theme aria-pressed="false">
                    <span class="a11y-choice__visual" aria-hidden="true">
                        <span class="a11y-scheme-badge a11y-scheme-badge--dark"><?= Icon::render('moon', 15) ?></span>
                    </span>
                    <span class="a11y-choice__label"><?= htmlspecialchars(t('Тёмный'), ENT_QUOTES) ?></span>
                </button>
            </div>
            <p class="a11y-group__hint"><?= htmlspecialchars(t('Тёплый фон снижает резь в глазах при долгом чтении.'), ENT_QUOTES) ?></p>
        </section>

        <!-- 3. ШРИФТ -->
        <section class="a11y-group">
            <div class="a11y-group__head">
                <span class="a11y-group__title">
                    <span class="a11y-group__icon" aria-hidden="true"><?= Icon::render('typography', 18) ?></span>
                    <?= htmlspecialchars(t('Шрифт'), ENT_QUOTES) ?>
                </span>
            </div>
            <div class="a11y-group__grid a11y-group__grid--fonts">
                <?= $choice('font', 'default', t('Обычный'), '<span class="a11y-font-badge a11y-font-badge--sans">Aa</span>', 'a11y-choice--card a11y-choice--font-col') ?>
                <?= $choice('font', 'readable', t('Читаемый'), '<span class="a11y-font-badge a11y-font-badge--readable">Aa</span>', 'a11y-choice--card a11y-choice--font-col a11y-choice--font-readable') ?>
                <?= $choice('font', 'serif', t('С засечками'), '<span class="a11y-font-badge a11y-font-badge--serif">Aa</span>', 'a11y-choice--card a11y-choice--font-col a11y-choice--font-serif') ?>
            </div>
        </section>

        <!-- 4. ИНТЕРВАЛЫ -->
        <section class="a11y-group">
            <div class="a11y-group__head">
                <span class="a11y-group__title">
                    <span class="a11y-group__icon" aria-hidden="true"><?= Icon::render('arrows-vertical', 18) ?></span>
                    <?= htmlspecialchars(t('Интервалы'), ENT_QUOTES) ?>
                </span>
            </div>
            <div class="a11y-group__grid a11y-group__grid--spacing">
                <?= $choice('spacing', 'normal', t('Обычные'), '<span class="a11y-spacing-icon a11y-spacing-icon--normal"><i></i><i></i><i></i></span>', 'a11y-choice--card a11y-choice--spacing-col') ?>
                <?= $choice('spacing', 'wide', t('Увеличенные'), '<span class="a11y-spacing-icon a11y-spacing-icon--wide"><i></i><i></i><i></i></span>', 'a11y-choice--card a11y-choice--spacing-col') ?>
                <?= $choice('spacing', 'wider', t('Максимальные'), '<span class="a11y-spacing-icon a11y-spacing-icon--wider"><i></i><i></i><i></i></span>', 'a11y-choice--card a11y-choice--spacing-col') ?>
            </div>
        </section>

        <!-- 5. ЧТЕНИЕ БЕЗ ПОМЕХ (ТУМБЛЕРЫ) -->
        <section class="a11y-group">
            <div class="a11y-group__head">
                <span class="a11y-group__title">
                    <span class="a11y-group__icon" aria-hidden="true"><?= Icon::render('adjustments-horizontal', 18) ?></span>
                    <?= htmlspecialchars(t('Чтение без помех'), ENT_QUOTES) ?>
                </span>
            </div>
            <div class="a11y-group__list">
                <?= $toggle('images', 'off', t('Скрыть картинки'), 'photo-off') ?>
                <?= $toggle('motion', 'off', t('Остановить анимации'), 'player-pause') ?>
                <?= $toggle('links', 'underline', t('Подчёркивать ссылки'), 'underline') ?>
            </div>
        </section>
    </div>

    <div class="a11y-drawer__foot">
        <button type="button" class="a11y-reset" data-a11y-reset>
            <span class="a11y-reset__icon" aria-hidden="true"><?= Icon::render('refresh', 18) ?></span>
            <?= htmlspecialchars(t('Сбросить настройки'), ENT_QUOTES) ?>
        </button>
    </div>
</aside>
