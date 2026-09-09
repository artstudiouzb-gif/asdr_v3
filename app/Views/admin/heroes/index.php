<?php

use App\Core\Csrf;
use App\Core\Hero\HeroPresets;
use App\Models\Hero;

/** @var array $items */
/** @var array<int, array{total:int, active:int}> $counts */

$pageTitle = 'Обложки';
$activeNav = 'heroes';
require __DIR__ . '/../layout/header.php';

$statusLabels = [
    'draft' => 'Черновик',
    'published' => 'Опубликована',
    'scheduled' => 'По расписанию',
];
?>
<p class="form-hint">
    Обложка — отдельный тип контента: набор слайдов со своими настройками показа.
    На странице она ставится блоком «Обложка», где нужно выбрать её из списка,
    поэтому одну и ту же обложку можно вывести на нескольких страницах и править
    в одном месте. Расписание позволяет собрать обложку к празднику или кампании
    заранее — она включится и выключится сама.
</p>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Новая обложка</h2>
    <form method="post" action="/admin/heroes/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название</label>
            <input type="text" id="name" name="name" placeholder="Например: Главная — осень 2026" required>
            <span class="form-hint">Название видно только в админке: на сайте выводятся заголовки слайдов.</span>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Создать</button>
        </div>
    </form>
</div>

<?php if (empty($items)): ?>
    <p class="form-hint">Обложек пока нет. Блоки «Обложка», собранные по-старому прямо на странице, продолжают работать — их можно не трогать.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Название</th><th>Слайдов</th><th>Статус</th><th>Показ</th>
                <th>Приоритет</th><th>Пресет</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $heroId = (int) $item['id'];
                $count = $counts[$heroId] ?? ['total' => 0, 'active' => 0];
                $status = (string) $item['status'];
                $visible = Hero::isVisible($item);
                ?>
                <tr>
                    <td><a href="/admin/heroes/<?= $heroId ?>/edit"><?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?></a></td>
                    <td>
                        <?= (int) $count['active'] ?>
                        <?php if ($count['total'] !== $count['active']): ?>
                            <span class="form-hint">из <?= (int) $count['total'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES) ?></td>
                    <td class="u-inline-a9efa5449f">
                        <?php if ($visible): ?>
                            на сайте
                        <?php elseif ($status === 'scheduled'): ?>
                            <?= htmlspecialchars(trim(
                                ($item['published_from'] ? 'с ' . substr((string) $item['published_from'], 0, 16) : '')
                                . ($item['published_to'] ? ' по ' . substr((string) $item['published_to'], 0, 16) : '')
                            ) ?: 'даты не заданы', ENT_QUOTES) ?>
                        <?php else: ?>
                            скрыта
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $item['priority'] ?></td>
                    <td class="u-inline-a9efa5449f">
                        <?= htmlspecialchars(HeroPresets::labels()[(string) $item['preset']] ?? '—', ENT_QUOTES) ?>
                    </td>
                    <td>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/heroes/<?= $heroId ?>/duplicate">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small">Дублировать</button>
                        </form>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/heroes/<?= $heroId ?>/delete"
                              data-confirm="Удалить обложку «<?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?>»? Блоки, где она стоит, перестанут её показывать.">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
