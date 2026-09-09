<?php

use App\Core\Csrf;

$pageTitle = 'Языки';
$activeNav = 'languages';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
?>
<p class="form-hint">Язык по умолчанию доступен на сайте без префикса в URL; остальные активные языки — по префиксу <code>/код/…</code>.</p>

<?php foreach ($items as $item): ?>
    <form id="lang-<?= (int) $item['id'] ?>" method="post" action="/admin/languages/<?= (int) $item['id'] ?>/edit">
        <?= Csrf::field() ?>
    </form>
<?php endforeach; ?>

<div class="table-responsive">
    <table class="data-table language-settings-table u-inline-5370cbf1a7">
        <thead>
            <tr><th>Код</th><th>Название</th><th>Короткое</th><th>По умолчанию</th><th>Активен</th><th>Порядок</th><th class="language-settings-table__actions-head">Действия</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php $f = 'lang-' . (int) $item['id']; ?>
                <tr>
                    <td><input class="u-inline-1a91ef9eca" type="text" name="code" value="<?= htmlspecialchars($item['code'], ENT_QUOTES) ?>" form="<?= $f ?>"></td>
                    <td><input type="text" name="name" value="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>" form="<?= $f ?>"></td>
                    <td><input class="u-inline-1a91ef9eca" type="text" name="short_name" value="<?= htmlspecialchars((string) ($item['short_name'] ?? ''), ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(\App\Models\Language::shortLabel($item), ENT_QUOTES) ?>" form="<?= $f ?>"></td>
                    <td class="u-inline-dac4fe6c9b"><input type="checkbox" name="is_default" value="1" <?= $item['is_default'] ? 'checked' : '' ?> form="<?= $f ?>"></td>
                    <td class="u-inline-dac4fe6c9b"><input type="checkbox" name="is_active" value="1" <?= $item['is_active'] ? 'checked' : '' ?> form="<?= $f ?>"></td>
                    <td><input class="u-inline-d26d79f4c2" type="number" name="sort_order" value="<?= (int) $item['sort_order'] ?>" form="<?= $f ?>"></td>
                    <td class="data-table__actions language-settings-table__actions">
                        <button type="submit" class="btn btn--small btn--primary" form="<?= $f ?>"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
                        <?php if (!$item['is_default']): ?>
                        <form method="post" action="/admin/languages/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить язык «<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>»? Переводы на этом языке будут удалены.">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Добавить язык</h2>
    <form method="post" action="/admin/languages/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="code">Код (ISO)</label>
            <input type="text" id="code" name="code" placeholder="en" required>
        </div>
        <div class="form-field">
            <label for="name">Название</label>
            <input type="text" id="name" name="name" placeholder="English" required>
        </div>

        <div class="form-field">
            <label for="short_name">Короткое название</label>
            <input type="text" id="short_name" name="short_name" placeholder="Eng" maxlength="16">
            <span class="form-hint">Показывается в компактном переключателе языков. Пусто — возьмутся первые буквы названия.</span>
        </div>
        <div class="form-field">
            <label for="sort_order">Порядок</label>
            <input type="number" id="sort_order" name="sort_order" value="0">
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
            <label for="is_active">Активен</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_default" name="is_default" value="1">
            <label for="is_default">Сделать языком по умолчанию</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Добавить язык</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
