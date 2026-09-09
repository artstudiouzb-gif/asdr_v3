<?php

use App\Core\ContentFields;
use App\Core\Csrf;
use App\Models\Language;

/** @var array $type */
/** @var array $fields */
/** @var array|null $entry */
/** @var array $translations */
/** @var string|null $error */

$isEdit = $entry !== null;
$pageTitle = ($isEdit ? 'Редактирование' : 'Новая запись') . ': ' . $type['name'];
$activeNav = 'content:' . $type['slug'];
require __DIR__ . '/../layout/header.php';

$data = $entry['data'] ?? [];
$action = $isEdit
    ? '/admin/content/' . $type['slug'] . '/' . (int) $entry['id'] . '/edit'
    : '/admin/content/' . $type['slug'] . '/create';
$hasFile = false;
foreach ($fields as $f) { if ($f['field_type'] === 'file') { $hasFile = true; break; } }
$hasTr = (int) $type['has_translations'] === 1;
?>
<div class="u-inline-79a1c5a5db">
    <a href="/admin/content/<?= htmlspecialchars((string) $type['slug'], ENT_QUOTES) ?>" class="btn btn--small">&larr; Все записи: <?= htmlspecialchars((string) $type['name'], ENT_QUOTES) ?></a>
</div>

<?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

<form method="post" action="<?= $action ?>"<?= $hasFile ? ' enctype="multipart/form-data"' : '' ?>>
    <?= Csrf::field() ?>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- Блок 1: Основная информация -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader('1. Основная информация (' . htmlspecialchars((string) $type['name'], ENT_QUOTES) . ')', 'document', 'var(--admin-primary,#2563eb)') ?>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Заголовок / Название <span class="u-inline-9dd1207e58">*</span></label>
                    <input class="u-inline-bc64d2d1e3" type="text" id="title" name="title" value="<?= htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES) ?>" required placeholder="Введите заголовок записи">
                </div>

                <?php foreach ($fields as $field): ?>
                    <div class="u-inline-79a1c5a5db">
                        <?= ContentFields::renderInput($field, $data[$field['name']] ?? '', 'f_') ?>
                        <?php if ($field['name'] === 'banner_image' && $type['slug'] === 'meropriyatiya'): ?>
                            <p class="form-hint">Необязательно. Показывается в карточке, календаре, на странице мероприятия и в Open Graph. Без баннера останется компактная карточка с датой.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Блок 2: Переводы на другие языки сайта -->
            <?php if ($hasTr): ?>
                <div class="form-card u-inline-8a43589152">
                    <?= \App\Core\AdminUi::cardHeader('2. Переводы и языковые версии', 'globe', 'var(--admin-violet)') ?>

                    <?php foreach (Language::active() as $lang): ?>
                        <?php
                        $code = (string) $lang['code'];
                        if ($code === Language::defaultCode()) { continue; }
                        $tr = $translations[$code] ?? ['title' => '', 'data' => []];
                        ?>
                        <div class="u-inline-2e25154383">
                            <h4 class="u-inline-c35d85373e">
                                <?= \App\Core\AdminUi::icon('globe') ?> <?= htmlspecialchars((string) $lang['name'], ENT_QUOTES) ?> (<?= strtoupper($code) ?>)
                            </h4>
                            <div class="form-field u-inline-a82d7062cc">
                                <label class="u-inline-0b87e9e0af">Заголовок (<?= htmlspecialchars($code, ENT_QUOTES) ?>)</label>
                                <input type="text" name="title_<?= $code ?>" value="<?= htmlspecialchars((string) ($tr['title'] ?? ''), ENT_QUOTES) ?>" placeholder="Перевод заголовка на <?= htmlspecialchars((string) $lang['name'], ENT_QUOTES) ?>">
                            </div>

                            <?php foreach ($fields as $field): ?>
                                <?php if ($field['field_type'] === 'file') { continue; /* файлы не переводим */ } ?>
                                <div class="u-inline-a82d7062cc">
                                    <?= ContentFields::renderInput($field, $tr['data'][$field['name']] ?? '', 't_' . $code . '_') ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Правая колонка настройки публикации -->
        <aside class="entry-side">
            <div class="form-card u-inline-c1563b7411">
                <h3 class="u-inline-3e8ce2fc5a">Параметры публикации</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="status">Статус</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($entry['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                            <option value="published" <?= ($entry['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="slug">Адрес (slug)</label>
                        <input type="text" id="slug" name="slug" value="<?= htmlspecialchars((string) ($entry['slug'] ?? ''), ENT_QUOTES) ?>" placeholder="оставьте пустым для автогенерации">
                        <span class="form-hint">Уникальный веб-адрес для отображения на сайте.</span>
                    </div>
                </div>

                <div class="form-actions form-actions--sticky u-inline-e7673d9ced">
                    <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
                    <a href="/admin/content/<?= htmlspecialchars((string) $type['slug'], ENT_QUOTES) ?>" class="btn">Отмена</a>
                </div>
            </div>
        </aside>
    </div>
</form>
<?php require __DIR__ . '/../layout/footer.php'; ?>
