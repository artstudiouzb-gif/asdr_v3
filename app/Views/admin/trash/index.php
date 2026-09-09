<?php

use App\Core\Csrf;

/** @var array<int, array<string, mixed>> $pages */
/** @var array<int, array<string, mixed>> $news */
/** @var array<int, array<string, mixed>> $projects */
$pages = $pages ?? [];
$news = $news ?? [];
$projects = $projects ?? [];

$hasItems = !empty($pages) || !empty($news) || !empty($projects);
$pageActions = $hasItems ? '
<form method="post" action="/admin/trash/empty" data-confirm="Очистить всю корзину? Все удалённые страницы, новости и проекты будут уничтожены навсегда без возможности восстановления.">
    ' . Csrf::field() . '
    <button type="submit" class="btn btn--danger">' . \App\Core\AdminUi::icon('trash') . 'Очистить корзину</button>
</form>
' : '';

$pageTitle = 'Корзина';
$activeNav = 'trash';
require __DIR__ . '/../layout/header.php';

$sections = [
    ['type' => 'pages', 'label' => 'Страницы', 'items' => $pages, 'title' => 'title'],
    ['type' => 'news', 'label' => 'Новости', 'items' => $news, 'title' => 'title'],
    ['type' => 'projects', 'label' => 'Проекты', 'items' => $projects, 'title' => 'title'],
];
?>
<p class="form-hint">Удалённые элементы хранятся здесь. Их можно восстановить или удалить навсегда.</p>

<?php foreach ($sections as $section): ?>
    <h2 class="u-inline-f0778f9dce"><?= htmlspecialchars($section['label'], ENT_QUOTES) ?></h2>
    <table class="data-table u-inline-af3fee87a1">
        <thead>
            <tr><th>Название</th><th>Удалено</th><th></th></tr>
        </thead>
        <tbody>
            <?php if (empty($section['items'])): ?>
                <tr><td colspan="3" class="data-table__empty">Пусто.</td></tr>
            <?php endif; ?>
            <?php foreach ($section['items'] as $item): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $item[$section['title']], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) $item['deleted_at'], ENT_QUOTES) ?></td>
                    <td class="data-table__actions">
                        <form method="post" action="/admin/trash/<?= $section['type'] ?>/<?= (int) $item['id'] ?>/restore">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small"><?= \App\Core\AdminUi::icon('reset') ?>Восстановить</button>
                        </form>
                        <form method="post" action="/admin/trash/<?= $section['type'] ?>/<?= (int) $item['id'] ?>/force-delete" data-confirm="Удалить навсегда? Это действие необратимо.">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить навсегда</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>