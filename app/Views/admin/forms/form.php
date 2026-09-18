<?php

use App\Core\Csrf;
use App\Core\FormBuilder;
use App\Core\FormLayout;

$isEdit = !empty($form['id']);
$pageTitle = $isEdit ? 'Редактирование формы' : 'Новая форма';
$activeNav = 'forms';
require __DIR__ . '/../layout/header.php';

/** @var array|null $form */
/** @var string|null $error */

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

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
            <a href="/admin/forms" class="btn">&larr; Назад к формам</a>
            <a href="/admin/forms" class="btn">Отмена</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
