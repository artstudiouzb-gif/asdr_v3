<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$isEdit = !empty($goal['id']);
$pageTitle = $isEdit ? 'Редактирование цели' : 'Новая цель';
$activeNav = 'goals';
require __DIR__ . '/../layout/header.php';

/** @var array|null $goal */
/** @var array $images */
/** @var array $translations */
/** @var string|null $error */

$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));

$action = $isEdit ? '/admin/goals/' . (int) $goal['id'] . '/edit' : '/admin/goals/create';
// Черновик формы: после ошибки снимки приезжают из POST, а не из базы.
$rows = $images;
if ($rows === [] && !empty($_POST['slides'])) {
    $rows = array_map(
        static fn (array $r): array => ['image' => (string) ($r['image'] ?? ''), 'alt' => (string) ($r['alt'] ?? '')],
        array_filter((array) $_POST['slides'], 'is_array')
    );
}
?>
<div class="form-card">
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" action="<?= $action ?>" class="form-grid">
        <?= Csrf::field() ?>

        <div class="form-field">
            <label for="name">Название цели</label>
            <input type="text" id="name" name="name" maxlength="255" required
                   value="<?= htmlspecialchars((string) ($goal['name'] ?? ''), ENT_QUOTES) ?>"
                   placeholder="Например: Транспортная инфраструктура">
            <span class="form-hint">
                Видно посетителю над каруселью и по нему цель ищется в списке из сотен
                записей. Перевод — ниже, в разделе «Переводы цели».
            </span>
        </div>

        <div class="form-field">
            <label for="description">Описание</label>
            <textarea id="description" name="description" rows="3" maxlength="2000"
                      placeholder="Одно-два предложения: что показано на снимках и зачем."><?= htmlspecialchars((string) ($goal['description'] ?? ''), ENT_QUOTES) ?></textarea>
            <span class="form-hint">
                Необязательно. Простой текст без разметки — цель показывается в узкой
                колонке виджета.
            </span>
        </div>

        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (!$isEdit || !empty($goal['is_active'])) ? 'checked' : '' ?>>
            <label for="is_active">Показывать в карусели</label>
            <span class="form-hint">Выключенная цель остаётся в списке, но виджет её не выбирает.</span>
        </div>

        <div class="form-field">
            <label>Снимки цели</label>
            <span class="form-hint">
                Порядок кадров — ваш: случайной бывает сама цель, а не её слайды.
                Цель без снимков виджет пропускает. Текст в поле «Описание для незрячих»
                на экране не виден — его читает диктор.
            </span>
            <div data-repeater="slides">
                <?php foreach ($rows as $i => $row): ?>
                    <div class="repeater-row">
                        <?= AdminUi::imageField('slides[' . $i . '][image]', (string) ($row['image'] ?? ''), ['label' => 'Фотография']) ?>
                        <div class="form-field">
                            <label>Описание для незрячих</label>
                            <input type="text" name="slides[<?= $i ?>][alt]" maxlength="200" value="<?= htmlspecialchars((string) ($row['alt'] ?? ''), ENT_QUOTES) ?>">
                        </div>
                        <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-repeater-template="slides">
                <?= AdminUi::imageField('slides[__INDEX__][image]', '', ['label' => 'Фотография']) ?>
                <div class="form-field">
                    <label>Описание для незрячих</label>
                    <input type="text" name="slides[__INDEX__][alt]" maxlength="200">
                </div>
                <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
            </template>
            <button type="button" class="btn btn--small" data-repeater-add="slides">+ Добавить фотографию</button>
        </div>

        <?php if ($isEdit && $translationLangs !== []): ?>
            <section id="translations" class="form-field">
                <h3><?= AdminUi::icon('globe') ?> Переводы цели</h3>
                <p class="form-hint">
                    Название и описание видны на сайте, поэтому переводятся. Пустое поле
                    берёт текст основного языка — недописанный перевод оставляет текст,
                    а не дыру. Снимки у всех языков общие.
                </p>
                <?php foreach ($translationLangs as $language): ?>
                    <?php
                    $code = (string) $language['code'];
                    $translation = $translations[$code] ?? [];
                    ?>
                    <div class="form-card">
                        <h4><?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?> (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</h4>
                        <div class="form-field">
                            <label for="goal-name-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Название (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <input type="text" id="goal-name-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                   name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][name]" maxlength="255"
                                   value="<?= htmlspecialchars((string) ($translation['name'] ?? ''), ENT_QUOTES) ?>">
                        </div>
                        <div class="form-field">
                            <label for="goal-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Описание (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <textarea id="goal-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                      name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][description]"
                                      rows="3" maxlength="2000"><?= htmlspecialchars((string) ($translation['description'] ?? ''), ENT_QUOTES) ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php elseif ($translationLangs !== []): ?>
            <p class="form-hint">Переводы названия и описания появятся здесь после первого сохранения.</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Сохранить</button>
            <a class="btn" href="/admin/goals">К списку</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
