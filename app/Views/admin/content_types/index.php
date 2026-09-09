<?php

use App\Core\Csrf;

$pageTitle = 'Типы контента';
$activeNav = 'content_types';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
?>
<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Новый тип контента</h2>
    <p class="form-hint">Например «Вакансии», «Отзывы», «Услуги». После создания добавьте поля — и раздел заработает сам.</p>
    <form method="post" action="/admin/content-types/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название</label>
            <input type="text" id="name" name="name" required>
        </div>
        <div class="form-field">
            <label for="slug">Адрес (латиница, необязательно)</label>
            <input type="text" id="slug" name="slug" placeholder="напр. vacancies">
        </div>
        <div class="form-field">
            <label for="description">Описание раздела (необязательно)</label>
            <input type="text" id="description" name="description" placeholder="Короткое описание для страницы списка">
        </div>
        <?= \App\Core\AdminUi::iconField('icon', '', [
            'label' => 'Иконка раздела',
            'hint' => 'Значок пункта в боковом меню. Пусто — общий значок списка.',
        ]) ?>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="has_translations" name="has_translations" value="1">
            <label for="has_translations">Мультиязычный (переводы записей)</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_public" name="is_public" value="1" checked>
            <label for="is_public">Публичный раздел (показывать на сайте: <code>/catalog/…</code>)</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="root_url" name="root_url" value="1">
            <label for="root_url">Адрес без префикса <code>/catalog</code> — раздел живёт в корне сайта</label>
        </div>
        <span class="form-hint">Например, <code>/documenty</code> вместо <code>/catalog/documenty</code>. Адрес должен быть свободен: страница или служебный раздел с таким адресом занимает его первым, и тогда настройка не сохранится.</span>
        <div class="form-actions"><button type="submit" class="btn btn--primary">Создать тип</button></div>
    </form>
</div>

<table class="data-table u-inline-9eb125f52f">
    <thead><tr><th>Название</th><th>Адрес</th><th>Переводы</th><th>На сайте</th><th></th></tr></thead>
    <tbody>
        <?php if (empty($items)): ?><tr><td colspan="5" class="data-table__empty">Типов пока нет.</td></tr><?php endif; ?>
        <?php foreach ($items as $t): ?>
            <tr>
                <td>
                    <span class="content-type-name">
                        <?= \App\Core\AdminUi::icon((string) ($t['icon'] ?? '') ?: 'list-details', 18) ?>
                        <?= htmlspecialchars((string) $t['name'], ENT_QUOTES) ?>
                    </span>
                </td>
                <td><code><?= htmlspecialchars((string) $t['slug'], ENT_QUOTES) ?></code></td>
                <td><?= (int) $t['has_translations'] === 1 ? 'да' : 'нет' ?></td>
                <td>
                    <?php if ((int) ($t['is_public'] ?? 1) === 1): ?>
                        <?php $publicPath = '/' . \App\Models\ContentType::path($t); ?>
                        <a href="<?= htmlspecialchars($publicPath, ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($publicPath, ENT_QUOTES) ?></a>
                    <?php else: ?>
                        <span class="form-hint">скрыт</span>
                    <?php endif; ?>
                </td>
                <td class="data-table__actions">
                    <a class="btn btn--small" href="/admin/content/<?= htmlspecialchars((string) $t['slug'], ENT_QUOTES) ?>">Записи</a>
                    <a class="btn btn--small" href="/admin/content-types/<?= (int) $t['id'] ?>/fields">Поля</a>
                    <form method="post" action="/admin/content-types/<?= (int) $t['id'] ?>/delete" data-confirm="Удалить тип «<?= htmlspecialchars((string) $t['name'], ENT_QUOTES) ?>» и ВСЕ его записи?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../layout/footer.php'; ?>