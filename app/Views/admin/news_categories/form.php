<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

/** @var array $category */
/** @var array $translations */
/** @var int $newsCount */

$pageTitle = 'Категория: ' . $category['name'];
$activeNav = 'news_categories';
require __DIR__ . '/../layout/header.php';

$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));
?>
<p><a href="/admin/news-categories" class="btn btn--small">← Все категории</a></p>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Свойства категории</h2>
    <form method="post" action="/admin/news-categories/<?= (int) $category['id'] ?>/update" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Название (<?= htmlspecialchars(strtoupper($defaultCode), ENT_QUOTES) ?> — основной язык)</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?>" required>
        </div>
        <div class="form-field">
            <label for="slug">Адрес (slug)</label>
            <input type="text" id="slug" name="slug" value="<?= htmlspecialchars((string) $category['slug'], ENT_QUOTES) ?>">
            <span class="form-hint">Один для всех языков: это идентификатор в адресах и выборках, а не текст для чтения. Занятый адрес получит числовой суффикс.</span>
        </div>
        <?= AdminUi::iconField('icon', (string) ($category['icon'] ?? ''), [
            'label' => 'Иконка рубрики',
            'hint' => 'Показывается рядом с названием в рубрикаторе на сайте. Без иконки рубрика выводится одним названием.',
        ]) ?>
        <div class="form-field">
            <label for="sort_order">Порядок сортировки</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($category['sort_order'] ?? 0) ?>">
            <span class="form-hint">Меньше — выше в списках и фильтрах.</span>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?>>
            <label for="is_active">Активна</label>
        </div>
        <p class="form-hint">
            Скрытая категория не предлагается в фильтрах на сайте, но новости из неё остаются опубликованными.
            <?php if ($newsCount > 0): ?>
                Сейчас в категории <a href="/admin/news?category=<?= (int) $category['id'] ?>"><?= (int) $newsCount ?></a> новостей.
            <?php else: ?>
                Новостей в категории пока нет.
            <?php endif; ?>
        </p>

        <?php if ($translationLangs !== []): ?>
            <section id="translations" class="u-inline-d31dcf37c0">
                <h3 class="u-inline-c35d85373e">
                    <?= AdminUi::icon('globe') ?> Переводы названия
                </h3>
                <p class="form-hint">Пустой перевод — не ошибка: на этом языке покажется название основного языка.</p>
                <?php foreach ($translationLangs as $language): ?>
                    <?php
                    $code = (string) $language['code'];
                    $translation = $translations[$code] ?? [];
                    ?>
                    <div class="form-field">
                        <label for="category-name-<?= htmlspecialchars($code, ENT_QUOTES) ?>">
                            <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>
                            (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)
                        </label>
                        <input
                            type="text"
                            id="category-name-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                            name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][name]"
                            value="<?= htmlspecialchars((string) ($translation['name'] ?? ''), ENT_QUOTES) ?>"
                            placeholder="Название на <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>"
                        >
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
