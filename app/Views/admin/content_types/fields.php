<?php

use App\Core\Csrf;

$pageTitle = 'Поля типа: ' . ($type['name'] ?? '');
$activeNav = 'content_types';
require __DIR__ . '/../layout/header.php';

/** @var array $type */
/** @var array $fields */
/** @var array $allTypes */
$ftypes = ['text' => 'Текст', 'textarea' => 'Многострочный', 'number' => 'Число', 'date' => 'Дата', 'image' => 'Изображение', 'file' => 'Файл', 'relation' => 'Связь'];
?>
<a href="/admin/content-types" class="btn btn--small u-inline-79a1c5a5db">&larr; Все типы</a>
<div class="form-card">
    <form method="post" action="/admin/content-types/<?= (int) $type['id'] ?>/fields" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название типа</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars((string) $type['name'], ENT_QUOTES) ?>" required>
        </div>
        <div class="form-field">
            <label for="description">Описание раздела (для страницы списка на сайте)</label>
            <input type="text" id="description" name="description" value="<?= htmlspecialchars((string) ($type['description'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <?= \App\Core\AdminUi::iconField('icon', (string) ($type['icon'] ?? ''), [
            'label' => 'Иконка раздела',
            'hint' => 'Значок пункта в боковом меню. Пусто — общий значок списка.',
        ]) ?>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="has_translations" name="has_translations" value="1" <?= (int) $type['has_translations'] === 1 ? 'checked' : '' ?>>
            <label for="has_translations">Мультиязычный</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_public" name="is_public" value="1" <?= (int) ($type['is_public'] ?? 1) === 1 ? 'checked' : '' ?>>
            <label for="is_public">Публичный раздел (доступен на сайте по адресу <code>/<?= htmlspecialchars(\App\Models\ContentType::path($type), ENT_QUOTES) ?></code>)</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="root_url" name="root_url" value="1" <?= !empty($type['root_url']) ? 'checked' : '' ?>>
            <label for="root_url">Адрес без префикса <code>/catalog</code> — раздел живёт в корне сайта</label>
        </div>
        <span class="form-hint">
            Прежний адрес <code>/catalog/<?= htmlspecialchars((string) $type['slug'], ENT_QUOTES) ?></code> продолжит работать: он отвечает постоянным
            перенаправлением на новый, поэтому старые ссылки и закладки не теряются.
            Адрес должен быть свободен — страница или служебный раздел с таким адресом занимает его первым.
        </span>

        <h3>Поля</h3>
        <div data-repeater="cfields">
            <?php foreach ($fields as $i => $f): ?>
                <div class="repeater-row">
                    <div class="form-field"><label>Имя (латиница)</label><input type="text" name="fields[<?= $i ?>][name]" value="<?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?>"></div>
                    <div class="form-field"><label>Подпись</label><input type="text" name="fields[<?= $i ?>][label]" value="<?= htmlspecialchars((string) $f['label'], ENT_QUOTES) ?>"></div>
                    <div class="form-field"><label>Тип</label>
                        <select name="fields[<?= $i ?>][field_type]">
                            <?php foreach ($ftypes as $v => $l): ?><option value="<?= $v ?>" <?= $f['field_type'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field"><label>Связь с типом (для «Связь»)</label>
                        <input type="text" name="fields[<?= $i ?>][relation_type]" placeholder="slug типа" value="<?= htmlspecialchars((string) ($f['options']['relation_type'] ?? ''), ENT_QUOTES) ?>">
                    </div>
                    <div class="form-field form-field--checkbox"><input type="checkbox" name="fields[<?= $i ?>][required]" value="1" <?= !empty($f['required']) ? 'checked' : '' ?>><label>Обязательное</label></div>
                    <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                </div>
            <?php endforeach; ?>
        </div>
        <template data-repeater-template="cfields">
            <div class="form-field"><label>Имя (латиница)</label><input type="text" name="fields[__INDEX__][name]"></div>
            <div class="form-field"><label>Подпись</label><input type="text" name="fields[__INDEX__][label]"></div>
            <div class="form-field"><label>Тип</label>
                <select name="fields[__INDEX__][field_type]">
                    <?php foreach ($ftypes as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-field"><label>Связь с типом (для «Связь»)</label><input type="text" name="fields[__INDEX__][relation_type]" placeholder="slug типа"></div>
            <div class="form-field form-field--checkbox"><input type="checkbox" name="fields[__INDEX__][required]" value="1"><label>Обязательное</label></div>
            <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
        </template>
        <div class="repeater-actions"><button type="button" class="btn btn--small" data-repeater-add="cfields"><?= \App\Core\AdminUi::icon('plus') ?>Добавить поле</button></div>

        <div class="form-actions form-actions--sticky"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить поля</button></div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>