<?php

use App\Core\Csrf;
use App\Models\Widget;

$pageTitle = 'Виджеты сайдбара';
$activeNav = 'widgets';
$pageActions = '<a href="/admin/widgets/create" class="btn btn--primary">' . \App\Core\AdminUi::icon('plus') . 'Добавить виджет</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $left */
/** @var array $right */

$renderColumn = static function (string $title, array $widgets): void {
    ?>
    <div class="sidebar-column">
        <h2><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
        <?php if (empty($widgets)): ?>
            <p class="form-hint">Виджетов нет.</p>
        <?php endif; ?>
        <?php foreach ($widgets as $index => $widget): ?>
            <div class="block-list-item">
                <div class="block-list-item__meta">
                    <strong><?= htmlspecialchars($widget['title'] ?: Widget::TYPE_LABELS[$widget['type']] ?? $widget['type'], ENT_QUOTES) ?></strong>
                    <span class="block-list-item__type">
                        <?= htmlspecialchars(Widget::TYPE_LABELS[$widget['type']] ?? $widget['type'], ENT_QUOTES) ?>
                        · <?= $widget['lang'] === '' ? 'все языки' : htmlspecialchars($widget['lang'], ENT_QUOTES) ?>
                        <?= $widget['is_active'] ? '' : ' · выкл' ?>
                    </span>
                </div>
                <div class="block-list-item__actions">
                    <form method="post" action="/admin/widgets/<?= (int) $widget['id'] ?>/move">
                        <?= Csrf::field() ?><input type="hidden" name="direction" value="up">
                        <button class="btn btn--small" <?= $index === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    </form>
                    <form method="post" action="/admin/widgets/<?= (int) $widget['id'] ?>/move">
                        <?= Csrf::field() ?><input type="hidden" name="direction" value="down">
                        <button class="btn btn--small" <?= $index === count($widgets) - 1 ? 'disabled' : '' ?>>&darr;</button>
                    </form>
                    <a class="btn btn--small" href="/admin/widgets/<?= (int) $widget['id'] ?>/edit">Изм.</a>
                    <form method="post" action="/admin/widgets/<?= (int) $widget['id'] ?>/delete" data-confirm="Удалить виджет?">
                        <?= Csrf::field() ?>
                        <button class="btn btn--small btn--danger">Удл.</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
};
?>

<p class="form-hint">Виджеты выводятся в сайдбаре тех страниц и новостей, где выбран макет «Левый сайдбар» или «Правый сайдбар». У новости колонка задаётся полем «Макет страницы с виджетами», у страницы — макетом страницы.</p>
<p class="form-hint">Пока в выбранной колонке нет активных виджетов, в новости на её месте показывается форма подписки по умолчанию. Как только в колонке появится хотя бы один активный виджет, вместо неё выводятся виджеты — форму подписки можно вернуть отдельным виджетом «Форма подписки на новости».</p>

<div class="sidebar-columns">
    <?php $renderColumn('Левая колонка', $left); ?>
    <?php $renderColumn('Правая колонка', $right); ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>