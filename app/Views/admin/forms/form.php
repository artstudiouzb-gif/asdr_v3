<?php

use App\Core\Csrf;
use App\Core\FormBuilder;
use App\Core\FormLayout;
use App\Models\Language;

$isEdit = !empty($form['id']);
$pageTitle = $isEdit ? 'Редактирование формы' : 'Новая форма';
$activeNav = 'forms';
require __DIR__ . '/../layout/header.php';

/** @var array|null $form */
/** @var string|null $error */
/** @var array<string, array<string, mixed>> $translations */
$translations = $translations ?? [];
$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

$action = $isEdit ? '/admin/forms/' . (int) $form['id'] . '/edit' : '/admin/forms/create';
$fields = $form['fields'] ?? [];

?>
<div class="form-card">
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" action="<?= $action ?>" class="form-grid">
        <?= Csrf::field() ?>

        <div class="form-field">
            <label for="name">Название формы</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($form['name'] ?? '', ENT_QUOTES) ?>" required>
        </div>

        <div class="form-field">
            <label for="slug">Slug (используется в адресе отправки)</label>
            <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($form['slug'] ?? '', ENT_QUOTES) ?>" placeholder="оставьте пустым для автогенерации">
        </div>

        <div class="form-field">
            <label for="notify_email">Email для уведомлений о новых заявках</label>
            <input type="email" id="notify_email" name="notify_email" value="<?= htmlspecialchars($form['notify_email'] ?? '', ENT_QUOTES) ?>">
        </div>

        <div class="form-field">
            <label for="success_message">Сообщение после успешной отправки</label>
            <input type="text" id="success_message" name="success_message" value="<?= htmlspecialchars($form['success_message'] ?? 'Спасибо! Ваша заявка отправлена.', ENT_QUOTES) ?>">
        </div>

        <?php // Конструктор: карточки полей стоят в той же сетке из шести
              // дорожек, что и сама форма на сайте, и занимают выбранную
              // ширину. Порядок карточек — порядок полей: сервер читает
              // fields[] в том виде, в каком их прислал браузер, поэтому
              // перетаскивание и стрелки ничего больше сохранять не должны. ?>
        <div class="formb" data-form-builder>
            <div class="formb__head">
                <label>Поля формы</label>
                <span class="form-hint">Карточку можно перетащить — так же встанут поля на сайте. Ширина «как в сетке формы» зависит от настройки блока, где форма выводится.</span>
            </div>
            <div class="formb__grid" data-repeater="fields">
                <?php foreach ($fields as $i => $field): ?>
                    <?php $field = is_array($field) ? $field : []; ?>
                    <div class="<?= htmlspecialchars(FormBuilder::cellClass(FormLayout::widthOf($field)), ENT_QUOTES) ?>" draggable="true">
                        <?= FormBuilder::fieldCard($field, (string) $i) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-repeater-template="fields">
                <?= FormBuilder::fieldCard([], '__INDEX__') ?>
            </template>
            <div class="repeater-actions">
                <button type="button" class="btn btn--small" data-repeater-add="fields"><?= \App\Core\AdminUi::icon('plus') ?>Добавить поле</button>
            </div>
        </div>

        <?php // Перевод — у той же формы, а не отдельной формой на язык: заявки
              // со всех языков идут в один журнал, поля правятся в одном месте.
              // Подписи привязаны к имени поля, поэтому порядок карточек выше
              // на них не влияет; поля, добавленные сейчас, появятся здесь
              // после сохранения. ?>
        <?php if ($isEdit && $translationLangs !== []): ?>
            <section id="translations" class="form-field">
                <h3><?= \App\Core\AdminUi::icon('globe') ?> Переводы формы</h3>
                <p class="form-hint">
                    Переводятся название, сообщение после отправки, подписи полей и варианты выбора.
                    Пустое поле берёт текст основного языка. Варианты перечисляются через запятую
                    в том же порядке, что и на основном языке: в заявку попадает вариант основного
                    языка, поэтому журнал и условия показа одинаковы для всех языков.
                </p>
                <?php foreach ($translationLangs as $language): ?>
                    <?php
                    $code = (string) $language['code'];
                    $tr = $translations[$code] ?? [];
                    $trFields = is_array($tr['fields'] ?? null) ? $tr['fields'] : [];
                    $prefix = 'translations[' . $code . ']';
                    $upper = strtoupper($code);
                    ?>
                    <div class="form-card">
                        <h4><?= $esc($language['name']) ?> (<?= $esc($upper) ?>)</h4>
                        <div class="form-field">
                            <label for="form-name-<?= $esc($code) ?>">Название формы (<?= $esc($upper) ?>)</label>
                            <input type="text" id="form-name-<?= $esc($code) ?>" name="<?= $esc($prefix) ?>[name]" maxlength="190"
                                   value="<?= $esc($tr['name'] ?? '') ?>" placeholder="<?= $esc($form['name'] ?? '') ?>">
                        </div>
                        <div class="form-field">
                            <label for="form-success-<?= $esc($code) ?>">Сообщение после отправки (<?= $esc($upper) ?>)</label>
                            <input type="text" id="form-success-<?= $esc($code) ?>" name="<?= $esc($prefix) ?>[success_message]" maxlength="500"
                                   value="<?= $esc($tr['success_message'] ?? '') ?>" placeholder="<?= $esc($form['success_message'] ?? '') ?>">
                        </div>
                        <?php foreach ($fields as $field): ?>
                            <?php
                            if (!is_array($field) || (string) ($field['name'] ?? '') === '') {
                                continue;
                            }
                            $fname = (string) $field['name'];
                            $own = is_array($trFields[$fname] ?? null) ? $trFields[$fname] : [];
                            $fieldId = 'form-tr-' . $code . '-' . $fname;
                            $hasOptions = in_array(FormLayout::normalizeType((string) ($field['type'] ?? 'text')), FormLayout::OPTION_TYPES, true);
                            ?>
                            <div class="form-field">
                                <label for="<?= $esc($fieldId) ?>">Поле «<?= $esc($field['label'] ?? $fname) ?>» (<?= $esc($upper) ?>)</label>
                                <input type="text" id="<?= $esc($fieldId) ?>" name="<?= $esc($prefix . '[fields][' . $fname . '][label]') ?>" maxlength="190"
                                       value="<?= $esc($own['label'] ?? '') ?>" placeholder="<?= $esc($field['label'] ?? '') ?>">
                            </div>
                            <?php if ($hasOptions): ?>
                                <div class="form-field">
                                    <label for="<?= $esc($fieldId) ?>-options">Варианты поля «<?= $esc($field['label'] ?? $fname) ?>» (<?= $esc($upper) ?>)</label>
                                    <input type="text" id="<?= $esc($fieldId) ?>-options" name="<?= $esc($prefix . '[fields][' . $fname . '][options]') ?>" maxlength="2000"
                                           value="<?= $esc($own['options'] ?? '') ?>" placeholder="<?= $esc($field['options'] ?? '') ?>">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php elseif ($translationLangs !== []): ?>
            <p class="form-hint">Переводы названия и подписей полей появятся здесь после первого сохранения.</p>
        <?php endif; ?>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
            <a href="/admin/forms" class="btn">&larr; Назад к формам</a>
            <a href="/admin/forms" class="btn">Отмена</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
