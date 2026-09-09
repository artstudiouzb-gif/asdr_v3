<?php
/** @var array $data */
/** @var int $blockId */
use App\Core\Csrf;

$form = $data['form'] ?? null;

// Умные фоллбек векторные SVG-иконки для полей публичных форм
$getFieldIcon = static function (string $fieldType, string $fieldName, string $fieldLabel): ?string {
    $t = mb_strtolower(trim($fieldName . ' ' . $fieldLabel));
    if (str_contains($t, 'имя') || str_contains($t, 'name') || str_contains($t, 'fio') || str_contains($t, 'фио') || str_contains($t, 'ism') || str_contains($t, 'familiya') || str_contains($t, 'пользователь')) {
        return \App\Core\Icon::render('user', 18, 'form-field__icon-svg', 1.8);
    }
    if (str_contains($t, 'mail') || str_contains($t, 'почта') || str_contains($t, 'e-mail') || str_contains($t, 'pochta')) {
        return \App\Core\Icon::render('mail', 18, 'form-field__icon-svg', 1.8);
    }
    if (str_contains($t, 'телефон') || str_contains($t, 'telefon') || str_contains($t, 'phone') || str_contains($t, 'tel') || str_contains($t, 'номер')) {
        return \App\Core\Icon::render('phone', 18, 'form-field__icon-svg', 1.8);
    }
    if (str_contains($t, 'тема') || str_contains($t, 'subject') || str_contains($t, 'mavzu') || str_contains($t, 'вопрос') || str_contains($t, 'заголовок') || str_contains($t, 'тип')) {
        return \App\Core\Icon::render('tag', 18, 'form-field__icon-svg', 1.8);
    }
    if (str_contains($t, 'сообщение') || str_contains($t, 'хабар') || str_contains($t, 'message') || str_contains($t, 'текст') || str_contains($t, 'комментарий') || str_contains($t, 'обращение') || str_contains($t, 'описание')) {
        return \App\Core\Icon::render('message', 18, 'form-field__icon-svg', 1.8);
    }
    if ($fieldType === 'file' || str_contains($t, 'файл') || str_contains($t, 'fayl') || str_contains($t, 'документ')) {
        return \App\Core\Icon::render('paperclip', 18, 'form-field__icon-svg', 1.8);
    }
    if ($fieldType === 'date' || str_contains($t, 'дата') || str_contains($t, 'sana') || str_contains($t, 'date') || str_contains($t, 'день')) {
        return \App\Core\Icon::render('calendar', 18, 'form-field__icon-svg', 1.8);
    }
    if ($fieldType === 'select') {
        return \App\Core\Icon::render('list', 18, 'form-field__icon-svg', 1.8);
    }
    return null;
};
?>
<div class="block-form">
    <?php if ($form === null): ?>
        <p class="block-form__missing"><?= htmlspecialchars(t('Форма не найдена или ещё не выбрана в настройках блока.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <?php if (!empty($form['name'])): ?><h2><?= htmlspecialchars($form['name'], ENT_QUOTES) ?></h2><?php endif; ?>
        <?php $hasFile = false; foreach ($form['fields'] as $f) { if (($f['type'] ?? '') === 'file') { $hasFile = true; break; } } ?>
        <?php 
        $formLayoutClass = ($data['layout'] ?? '1col') === '2col' ? ' block-form__form--2col' : '';
        ?>
        <form method="post" action="/forms/<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?>/submit" class="block-form__form<?= $formLayoutClass ?>"<?= $hasFile ? ' enctype="multipart/form-data"' : '' ?>>
            <?= Csrf::field() ?>
            <?= Csrf::honeypotField() ?>
            <?php foreach ($form['fields'] as $field): ?>
                <?php
                $fieldName = htmlspecialchars($field['name'] ?? '', ENT_QUOTES);
                $fieldLabel = htmlspecialchars($field['label'] ?? '', ENT_QUOTES);
                $fieldType = $field['type'] ?? 'text';
                $required = !empty($field['required']) ? 'required' : '';
                $inputId = 'field-' . $fieldName . '-' . (int) $blockId;
                $iconSvg = $getFieldIcon($fieldType, (string) ($field['name'] ?? ''), (string) ($field['label'] ?? ''));
                // Условная логика (задача 135): поле с условием стартует скрытым,
                // JS показывает его при совпадении значения триггера.
                $cond = $field['condition'] ?? null;
                $condAttrs = '';
                $hiddenAttr = '';
                if (is_array($cond) && !empty($cond['field'])) {
                    $condAttrs = ' data-cond-field="' . htmlspecialchars((string) $cond['field'], ENT_QUOTES)
                        . '" data-cond-value="' . htmlspecialchars((string) ($cond['value'] ?? ''), ENT_QUOTES) . '"';
                    $hiddenAttr = ' hidden';
                }

                $isFullWidth = in_array($fieldType, ['textarea', 'file', 'checkbox_group', 'checkbox'], true);
                $fieldClass = 'block-form__field' . ($isFullWidth ? ' block-form__field--full' : '');
                ?>
                <div class="<?= $fieldClass ?>"<?= $condAttrs ?><?= $hiddenAttr ?>>
                    <?php if ($fieldType !== 'checkbox'): ?>
                        <label for="<?= $inputId ?>"><?= $fieldLabel ?></label>
                    <?php endif; ?>

                    <?php 
                    $lowerInfo = mb_strtolower($fieldName . ' ' . $fieldLabel);
                    $isPhone = ($fieldType === 'tel') || str_contains($lowerInfo, 'телефон') || str_contains($lowerInfo, 'telefon') || str_contains($lowerInfo, 'phone') || str_contains($lowerInfo, 'tel');
                    $isEmail = ($fieldType === 'email') || str_contains($lowerInfo, 'email') || str_contains($lowerInfo, 'mail') || str_contains($lowerInfo, 'почта');
                    ?>

                    <?php if ($fieldType === 'textarea'): ?>
                        <div class="block-form__input-wrapper block-form__input-wrapper--textarea<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <textarea id="<?= $inputId ?>" name="<?= $fieldName ?>" maxlength="2000" data-maxlength="2000" <?= $required ?>></textarea>
                        </div>
                        <div class="block-form__char-counter">
                            <span class="char-count">0</span> / 2000 <?= htmlspecialchars(t('символов'), ENT_QUOTES) ?>
                        </div>
                    <?php elseif ($fieldType === 'file'): ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <input type="file" id="<?= $inputId ?>" name="<?= $fieldName ?>"
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" <?= $required ?>>
                        </div>
                        <span class="form-hint"><?= htmlspecialchars(t('PDF, DOC, DOCX, JPG или PNG; до 20 МБ.'), ENT_QUOTES) ?></span>
                    <?php elseif ($fieldType === 'select'): ?>
                        <?php $opts = array_map('trim', explode(',', (string) ($field['options'] ?? ''))); ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <select id="<?= $inputId ?>" name="<?= $fieldName ?>" <?= $required ?>>
                                <option value=""><?= htmlspecialchars(t('Выберите...'), ENT_QUOTES) ?></option>
                                <?php foreach ($opts as $opt): ?>
                                    <?php if ($opt !== ''): ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>"><?= htmlspecialchars($opt, ENT_QUOTES) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($fieldType === 'radio'): ?>
                        <?php $opts = array_map('trim', explode(',', (string) ($field['options'] ?? ''))); ?>
                        <div class="block-form__radio-group">
                            <?php foreach ($opts as $opt): ?>
                                <?php if ($opt !== ''): ?>
                                    <label>
                                        <input type="radio" name="<?= $fieldName ?>" value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= $required ?>>
                                        <?= htmlspecialchars($opt, ENT_QUOTES) ?>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($fieldType === 'checkbox_group'): ?>
                        <?php $opts = array_map('trim', explode(',', (string) ($field['options'] ?? ''))); ?>
                        <div class="block-form__checkbox-group">
                            <?php foreach ($opts as $opt): ?>
                                <?php if ($opt !== ''): ?>
                                    <label>
                                        <input type="checkbox" name="<?= $fieldName ?>[]" value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($opt, ENT_QUOTES) ?>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($fieldType === 'checkbox'): ?>
                        <div class="block-form__checkbox-single">
                            <input type="checkbox" id="<?= $inputId ?>" name="<?= $fieldName ?>" value="1" <?= $required ?>>
                            <label for="<?= $inputId ?>"><?= $fieldLabel ?></label>
                        </div>
                    <?php elseif ($fieldType === 'date'): ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <input type="date" id="<?= $inputId ?>" name="<?= $fieldName ?>" <?= $required ?>>
                        </div>
                    <?php elseif ($isPhone): ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <input type="tel" id="<?= $inputId ?>" name="<?= $fieldName ?>" data-input-type="phone" inputmode="tel" autocomplete="tel" pattern="^[\+0-9\s\-\(\)]{7,25}$" placeholder="+998 71 123 45 67" <?= $required ?>>
                        </div>
                    <?php elseif ($isEmail): ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <input type="email" id="<?= $inputId ?>" name="<?= $fieldName ?>" data-input-type="email" inputmode="email" autocomplete="email" placeholder="example@domain.uz" <?= $required ?>>
                        </div>
                    <?php else: ?>
                        <div class="block-form__input-wrapper<?= $iconSvg !== null ? ' has-icon' : '' ?>">
                            <?php if ($iconSvg !== null): ?><span class="block-form__input-icon" aria-hidden="true"><?= $iconSvg ?></span><?php endif; ?>
                            <input type="<?= htmlspecialchars($fieldType, ENT_QUOTES) ?>" id="<?= $inputId ?>" name="<?= $fieldName ?>" <?= $required ?>>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (\App\Core\Captcha::isEnabled()): ?>
                <div class="block-form__captcha">
                    <?= \App\Core\Captcha::field('captcha-' . (int) $blockId) ?>
                </div>
            <?php endif; ?>
            <?php
            // Согласие на обработку персональных данных (глобальная настройка).
            $consentOn = \App\Models\Setting::get('form_consent_enabled', '0') === '1';
            if ($consentOn):
                $consentText = (string) \App\Models\Setting::get('form_consent_text', t('Я согласен на обработку персональных данных'));
                // Ссылка на политику конфиденциальности, если задана страница.
                $ppId = (int) \App\Models\Setting::get('privacy_policy_page_id', '');
                $ppUrl = '';
                if ($ppId > 0) {
                    $pp = \App\Models\Page::findById($ppId);
                    if ($pp && ($pp['status'] ?? '') === 'published') {
                        $ppUrl = \App\Core\Locale::url($pp['slug']);
                    }
                }
                $consentId = 'consent-' . (int) $blockId;
                ?>
                <div class="block-form__consent">
                    <input type="checkbox" id="<?= $consentId ?>" name="_consent" value="1" required>
                    <label for="<?= $consentId ?>">
                        <?= htmlspecialchars($consentText, ENT_QUOTES) ?><?php if ($ppUrl !== ''): ?>
                            (<a href="<?= htmlspecialchars($ppUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(t('Политика конфиденциальности'), ENT_QUOTES) ?></a>)
                        <?php endif; ?>
                    </label>
                </div>
            <?php endif; ?>
            <button type="submit" class="block-form__submit"><?= htmlspecialchars(t('Отправить'), ENT_QUOTES) ?></button>
        </form>
    <?php endif; ?>
</div>
