<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Цели';
$activeNav = 'goals';
$pageActions = '<a href="/admin/goals/create" class="btn btn--primary">' . AdminUi::icon('plus') . 'Добавить цель</a>';
require __DIR__ . '/../layout/header.php';

/** @var array $goals */
/** @var int $total */
/** @var array $filters */

$search = (string) $filters['q'];
$perPage = (int) $filters['per_page'];
$page = (int) $filters['page'];
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$pageUrl = static fn (int $n): string => '/admin/goals?' . http_build_query(array_filter([
    'q' => $search,
    'per_page' => $perPage !== 20 ? $perPage : null,
    'page' => $n > 1 ? $n : null,
]));

$siteLangs = array_map(static fn (array $l): string => (string) $l['code'], Language::active());
$langMap = \App\Models\Goal::availableLangsForIds(array_map(static fn ($g): int => (int) $g['id'], $goals));
?>
<div class="form-card">
    <p class="form-hint">
        Цель — это название, описание и набор снимков. Виджет «Фотокарусель» показывает
        одну случайную цель: над каруселью её название и описание, ниже — кадры.
        Название и описание видны посетителю, поэтому переводятся.
    </p>

    <form method="get" action="/admin/goals" class="admin-filters">
        <div class="form-field">
            <label for="q">Поиск по названию</label>
            <input type="search" id="q" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Например: Транспорт">
        </div>
        <div class="form-field">
            <label for="per_page">На странице</label>
            <select id="per_page" name="per_page">
                <?php foreach ([20, 50, 100] as $n): ?>
                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Показать</button>
        <?php if ($search !== ''): ?><a class="btn btn--small" href="/admin/goals">Сбросить</a><?php endif; ?>
    </form>

    <p class="form-hint">Всего целей: <?= $total ?><?= $search !== '' ? ' (по запросу)' : '' ?>.</p>

    <?php if (empty($goals)): ?>
        <p class="form-hint"><?= $search !== '' ? 'По запросу ничего не найдено.' : 'Целей пока нет.' ?></p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>Название</th><th>Языки</th><th>Снимков</th><th>Состояние</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($goals as $goal): ?>
                    <tr>
                        <td><a href="/admin/goals/<?= (int) $goal['id'] ?>/edit"><?= htmlspecialchars((string) $goal['name'], ENT_QUOTES) ?></a></td>
                        <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', [
                            'siteLangs' => $siteLangs,
                            'has' => $langMap[(int) $goal['id']] ?? [],
                            'translationEditUrl' => '/admin/goals/' . (int) $goal['id'] . '/edit',
                            'translationDefaultCode' => Language::defaultCode(),
                        ]) ?></td>
                        <td><?= (int) $goal['image_count'] ?></td>
                        <td><?= $goal['is_active'] ? 'вкл' : 'выкл' ?></td>
                        <td>
                            <a class="btn btn--small" href="/admin/goals/<?= (int) $goal['id'] ?>/edit">Изменить</a>
                            <form class="u-inline-0cd28ce9ba" method="post" action="/admin/goals/<?= (int) $goal['id'] ?>/delete" data-confirm="Удалить цель вместе со снимками?">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--small btn--danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
            <nav class="admin-pager" aria-label="Страницы списка">
                <?php if ($page > 1): ?><a class="btn btn--small" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES) ?>">← Назад</a><?php endif; ?>
                <span class="form-hint">Страница <?= $page ?> из <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn--small" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES) ?>">Вперёд →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
