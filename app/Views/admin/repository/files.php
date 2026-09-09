<?php

use App\Core\Csrf;
use App\Core\Format;

$pageTitle = 'Репозиторий: файлы';
$activeNav = 'repository';
require __DIR__ . '/../layout/header.php';

/** @var array $files */
/** @var string $query */
/** @var list<array{id:int, parent_id:?int, label:string}> $categories */
/** @var array{total_files:int, pending_files:int, total_categories:int, total_users:int} $stats */

// Селект категории: корни и подкатегории (с отступом), общий для форм страницы.
$categorySelect = static function (string $name, ?int $selected) use ($categories): string {
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    $html .= '<option value="0">— Без категории —</option>';
    foreach ($categories as $cat) {
        $html .= '<option value="' . (int) $cat['id'] . '"' . ((int) $cat['id'] === (int) $selected ? ' selected' : '') . '>'
            . ($cat['parent_id'] !== null ? '&nbsp;&nbsp;&nbsp;' : '')
            . htmlspecialchars($cat['label'], ENT_QUOTES) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="repo-subnav">
    <a href="/admin/repository" class="repo-subnav__item is-active"><?= \App\Core\AdminUi::icon('file', 16) ?> Файлы</a>
    <a href="/admin/repository/categories" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('folder', 16) ?> Категории</a>
    <a href="/admin/repository/users" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('users', 16) ?> Пользователи портала</a>
</div>

<p class="form-hint">Защищённое файловое хранилище с отдельной авторизацией (портал <code>/repo</code>). Файлы видят все активные пользователи портала; они также могут предлагать файлы — такие публикуются после одобрения ниже.</p>

<?php if (!empty($stats)): ?>
<div class="repo-stats">
    <div class="repo-stat-card">
        <div class="repo-stat-card__icon"><?= \App\Core\AdminUi::icon('file', 20) ?></div>
        <div>
            <div class="repo-stat-card__val"><?= (int) ($stats['total_files'] ?? 0) ?></div>
            <div class="repo-stat-card__label">Всего файлов</div>
        </div>
    </div>
    <div class="repo-stat-card<?= !empty($stats['pending_files']) ? ' repo-stat-card--warning' : '' ?>">
        <div class="repo-stat-card__icon"><?= \App\Core\AdminUi::icon('clock', 20) ?></div>
        <div>
            <div class="repo-stat-card__val"><?= (int) ($stats['pending_files'] ?? 0) ?></div>
            <div class="repo-stat-card__label">На модерации</div>
        </div>
    </div>
    <div class="repo-stat-card">
        <div class="repo-stat-card__icon"><?= \App\Core\AdminUi::icon('folder', 20) ?></div>
        <div>
            <div class="repo-stat-card__val"><?= (int) ($stats['total_categories'] ?? 0) ?></div>
            <div class="repo-stat-card__label">Категорий</div>
        </div>
    </div>
    <div class="repo-stat-card">
        <div class="repo-stat-card__icon"><?= \App\Core\AdminUi::icon('users', 20) ?></div>
        <div>
            <div class="repo-stat-card__val"><?= (int) ($stats['total_users'] ?? 0) ?></div>
            <div class="repo-stat-card__label">Пользователей</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /** @var array $pending */ ?>
<?php if (!empty($pending)): ?>
<div class="form-card mb-4">
    <h2>На модерации (<?= count($pending) ?>)</h2>
    <table class="data-table">
        <thead>
            <tr><th>Название</th><th>Категория</th><th>Файл</th><th>Размер</th><th>От кого</th><th>Прислан</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($pending as $f): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></strong>
                        <?php if (!empty($f['description'])): ?><div class="form-hint"><?= htmlspecialchars((string) $f['description'], ENT_QUOTES) ?></div><?php endif; ?>
                    </td>
                    <td><?= !empty($f['category']) ? htmlspecialchars((string) $f['category'], ENT_QUOTES) : '—' ?></td>
                    <td class="form-hint"><?= htmlspecialchars((string) $f['original_name'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(Format::fileSize((int) $f['size']), ENT_QUOTES) ?></td>
                    <td><?= !empty($f['repo_username']) ? htmlspecialchars((string) $f['repo_username'], ENT_QUOTES) : '—' ?></td>
                    <td><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $f['created_at'], 'd.m.Y H:i'), ENT_QUOTES) ?></td>
                    <td class="data-table__actions">
                        <form method="post" action="/admin/repository/<?= (int) $f['id'] ?>/approve">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--primary">Одобрить</button>
                        </form>
                        <form method="post" action="/admin/repository/<?= (int) $f['id'] ?>/delete" data-confirm="Отклонить и удалить файл «<?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger">Отклонить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="form-card mb-4">
    <h2>Загрузить файл</h2>
    <form method="post" action="/admin/repository/upload" enctype="multipart/form-data" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="title">Название</label>
            <input type="text" id="title" name="title" required placeholder="напр. Регламент проведения тендеров">
        </div>
        <div class="form-field">
            <label for="category_id">Категория (необязательно)</label>
            <?= $categorySelect('category_id', null) ?>
            <span class="form-hint">Список настраивается на вкладке <a href="/admin/repository/categories">«Категории»</a>.</span>
        </div>
        <div class="form-field">
            <label for="description">Описание (необязательно)</label>
            <textarea id="description" name="description" rows="2" placeholder="Краткая аннотация или примечания к документу..."></textarea>
        </div>
        <div class="form-field">
            <label for="file">Файл (PDF, Office, изображения, ZIP — до 100 МБ)</label>
            <input type="file" id="file" name="file" required>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('upload') ?>Загрузить файл</button></div>
    </form>
</div>

<div class="form-card mb-4">
    <h2>Оформление портала</h2>
    <form method="post" action="/admin/repository/settings" enctype="multipart/form-data" class="form-grid">
        <?= Csrf::field() ?>
        <?= \App\Core\AdminUi::imageField('repo_logo', (string) ($repoLogo ?? ''), [
            'label' => 'Логотип портала (шапка и форма входа)',
            'file' => 'repo_logo_file',
            'hint' => 'Пусто — стандартная иконка-щит. Лучше светлый/белый логотип: шапка портала тёмная.',
        ]) ?>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить логотип</button></div>
    </form>
</div>

<div class="repo-toolbar">
    <form class="repo-search-form" method="get" action="/admin/repository">
        <input type="text" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Поиск по файлам...">
        <button type="submit" class="btn btn--small btn--primary"><?= \App\Core\AdminUi::icon('search') ?>Найти</button>
        <?php if ($query !== ''): ?><a href="/admin/repository" class="btn btn--small">Сброс</a><?php endif; ?>
    </form>
</div>

<table class="data-table">
    <thead>
        <tr><th>Название</th><th>Категория</th><th>Файл</th><th>Размер</th><th>Скачиваний</th><th>Добавлен</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (empty($files)): ?>
            <tr><td class="text-center text-muted" colspan="7">Файлов пока нет.</td></tr>
        <?php else: ?>
            <?php foreach ($files as $f): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></strong>
                        <?php if (!empty($f['description'])): ?><div class="form-hint"><?= htmlspecialchars((string) $f['description'], ENT_QUOTES) ?></div><?php endif; ?>
                    </td>
                    <td><?= !empty($f['category']) ? htmlspecialchars((string) $f['category'], ENT_QUOTES) : '—' ?></td>
                    <td class="form-hint"><?= htmlspecialchars((string) $f['original_name'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(Format::fileSize((int) $f['size']), ENT_QUOTES) ?></td>
                    <td><?= (int) $f['download_count'] ?></td>
                    <td><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $f['created_at'], 'd.m.Y'), ENT_QUOTES) ?></td>
                    <td class="data-table__actions">
                        <div class="repo-popover">
                            <details>
                                <summary class="btn btn--small">Изменить</summary>
                                <div class="repo-popover__panel">
                                    <form method="post" action="/admin/repository/<?= (int) $f['id'] ?>/update">
                                        <?= Csrf::field() ?>
                                        <div class="form-field mb-2">
                                            <label>Название</label>
                                            <input type="text" name="title" required value="<?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?>">
                                        </div>
                                        <div class="form-field mb-2">
                                            <label>Категория</label>
                                            <?= $categorySelect('category_id', $f['category_id'] !== null ? (int) $f['category_id'] : null) ?>
                                        </div>
                                        <div class="form-field mb-2">
                                            <label>Описание</label>
                                            <textarea name="description" rows="2"><?= htmlspecialchars((string) ($f['description'] ?? ''), ENT_QUOTES) ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn--small btn--primary">Сохранить</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <form method="post" action="/admin/repository/<?= (int) $f['id'] ?>/delete" data-confirm="Удалить файл «<?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../layout/footer.php'; ?>