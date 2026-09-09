<?php

use App\Core\Csrf;

$pageTitle = 'Репозиторий: категории';
$activeNav = 'repository';
require __DIR__ . '/../layout/header.php';

/** @var list<array> $tree */

// Строка таблицы: имя (с отступом у подкатегории), число файлов, действия.
$row = static function (array $cat, bool $isChild): void {
    ?>
    <tr>
        <td>
            <?= $isChild ? '<span class="repo-category-indent">└</span> ' : '' ?>
            <?= $isChild ? '' : '<strong>' ?><?= htmlspecialchars((string) $cat['name'], ENT_QUOTES) ?><?= $isChild ? '' : '</strong>' ?>
        </td>
        <td><?= (int) $cat['files_count'] ?></td>
        <td class="data-table__actions">
            <div class="repo-popover">
                <details>
                    <summary class="btn btn--small">Переименовать</summary>
                    <div class="repo-popover__panel">
                        <form method="post" action="/admin/repository/categories/<?= (int) $cat['id'] ?>/rename">
                            <?= Csrf::field() ?>
                            <div class="form-field mb-2">
                                <label>Новое название</label>
                                <input type="text" name="name" required maxlength="120" value="<?= htmlspecialchars((string) $cat['name'], ENT_QUOTES) ?>">
                            </div>
                            <button type="submit" class="btn btn--small btn--primary">Сохранить</button>
                        </form>
                    </div>
                </details>
            </div>
            <form method="post" action="/admin/repository/categories/<?= (int) $cat['id'] ?>/delete" data-confirm="Удалить категорию «<?= htmlspecialchars((string) $cat['name'], ENT_QUOTES) ?>»?<?= !$isChild ? ' Её подкатегории тоже удалятся.' : '' ?> Файлы останутся без категории.">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
            </form>
        </td>
    </tr>
    <?php
};
?>
<div class="repo-subnav">
    <a href="/admin/repository" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('file', 16) ?> Файлы</a>
    <a href="/admin/repository/categories" class="repo-subnav__item is-active"><?= \App\Core\AdminUi::icon('folder', 16) ?> Категории</a>
    <a href="/admin/repository/users" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('users', 16) ?> Пользователи портала</a>
</div>

<p class="form-hint">Категории файлового хранилища. Один уровень вложенности: категория → подкатегории. На портале фильтр по корневой категории показывает и файлы её подкатегорий.</p>

<div class="form-card mb-4">
    <h2>Добавить категорию</h2>
    <form method="post" action="/admin/repository/categories/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название</label>
            <input type="text" id="name" name="name" required maxlength="120" placeholder="напр. Приказы и распоряжения">
        </div>
        <div class="form-field">
            <label for="parent_id">Родительская категория</label>
            <select id="parent_id" name="parent_id">
                <option value="0">— Нет (корневая категория) —</option>
                <?php foreach ($tree as $root): ?>
                    <option value="<?= (int) $root['id'] ?>"><?= htmlspecialchars((string) $root['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('plus') ?>Добавить категорию</button></div>
    </form>
</div>

<table class="data-table">
    <thead>
        <tr><th>Категория</th><th>Файлов</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (empty($tree)): ?>
            <tr><td class="text-center text-muted" colspan="3">Категорий пока нет.</td></tr>
        <?php else: ?>
            <?php foreach ($tree as $root): ?>
                <?php $row($root, false); ?>
                <?php foreach ($root['children'] as $child): ?>
                    <?php $row($child, true); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../layout/footer.php'; ?>