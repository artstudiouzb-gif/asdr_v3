<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

/** @var array $items */
/** @var array<int,int> $counts */
/** @var array<int,list<string>> $langMap */

$pageTitle = 'Категории новостей';
$activeNav = 'news_categories';
require __DIR__ . '/../layout/header.php';

$siteLangs = array_map(static fn (array $l): string => (string) $l['code'], Language::active());
?>
<p class="form-hint">Рубрики ленты новостей. Категория выбирается в форме новости, по ней работают фильтр в списке и выборка в блоке «Новости» на страницах. Название переводится на языки сайта; адрес (slug) один для всех языков.</p>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Новая категория</h2>
    <form method="post" action="/admin/news-categories/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название</label>
            <input type="text" id="name" name="name" placeholder="Например: Официальные сообщения" required>
        </div>
        <div class="form-field">
            <label for="slug">Адрес (необязательно)</label>
            <input type="text" id="slug" name="slug" placeholder="оставьте пустым — составится из названия">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Создать</button>
        </div>
    </form>
</div>

<?php if (empty($items)): ?>
    <p class="form-hint">Категорий пока нет. Пока их нет, новости выходят без рубрики — это допустимо.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Название</th><th>Языки</th><th>Адрес</th><th>Новостей</th><th>Порядок</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php $categoryId = (int) $item['id']; ?>
                <tr>
                    <td><a href="/admin/news-categories/<?= $categoryId ?>/edit"><?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?></a></td>
                    <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', [
                        'siteLangs' => $siteLangs,
                        'has' => $langMap[$categoryId] ?? [],
                        'translationEditUrl' => '/admin/news-categories/' . $categoryId . '/edit',
                        'translationDefaultCode' => Language::defaultCode(),
                    ]) ?></td>
                    <td class="u-inline-a9efa5449f"><code><?= htmlspecialchars((string) $item['slug'], ENT_QUOTES) ?></code></td>
                    <td>
                        <?php $used = $counts[$categoryId] ?? 0; ?>
                        <?php if ($used > 0): ?>
                            <a href="/admin/news?category=<?= $categoryId ?>"><?= $used ?></a>
                        <?php else: ?>
                            0
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $item['sort_order'] ?></td>
                    <td>
                        <?php if ((int) $item['is_active'] === 1): ?>
                            <span class="badge badge--success">Активна</span>
                        <?php else: ?>
                            <span class="badge">Скрыта</span>
                        <?php endif; ?>
                    </td>
                    <td class="data-table__actions">
                        <a href="/admin/news-categories/<?= $categoryId ?>/edit" class="btn btn--small btn--icon" title="Редактировать" aria-label="Редактировать"><?= AdminUi::icon('edit') ?></a>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/news-categories/<?= $categoryId ?>/delete" data-confirm="Удалить категорию «<?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?>»? Новости останутся, у них снимется категория.">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= AdminUi::icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
