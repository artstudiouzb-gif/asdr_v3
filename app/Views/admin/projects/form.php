<?php

use App\Core\Csrf;
use App\Models\Language;

$isEdit = !empty($project['id']);
$pageTitle = $isEdit ? 'Редактирование проекта' : 'Новый проект';
$activeNav = 'projects';
require __DIR__ . '/../layout/header.php';

/** @var array|null $project */
/** @var string|null $error */

$defaultCode = Language::defaultCode();
$action = $isEdit ? '/admin/projects/' . (int) $project['id'] . '/edit' : '/admin/projects/create';

?>
<?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($isEdit): ?>
    <div class="u-inline-79a1c5a5db"><a class="btn btn--small" href="/admin/revisions/project/<?= (int) $project['id'] ?>">История версий</a></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>" id="project_edit_form" enctype="multipart/form-data" data-content-draft="project:<?= $isEdit ? (int) $project['id'] : 'new' ?>" data-record-updated="<?= htmlspecialchars((string) ($project['updated_at'] ?? ''), ENT_QUOTES) ?>">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="expected_updated_at" value="<?= htmlspecialchars((string) $project['updated_at'], ENT_QUOTES) ?>">
        <input type="hidden" name="expected_lock_version" value="<?= (int) ($project['lock_version'] ?? 1) ?>">
    <?php endif; ?>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- Блок 1: Основная информация -->
            <div class="form-card u-inline-8a43589152">
                <div class="u-inline-1446175039">
                    <span class="admin-section-icon"><?= \App\Core\AdminUi::icon('projects', 22) ?></span>
                    <h3 class="u-inline-eb7fb8da4e">1. Основная информация о проекте</h3>
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Название проекта <span class="u-inline-9dd1207e58">*</span></label>
                    <input class="u-inline-bc64d2d1e3" type="text" id="title" name="title" value="<?= htmlspecialchars($project['title'] ?? '', ENT_QUOTES) ?>" placeholder="Введите название проекта" required>
                </div>

                <div class="form-field">
                    <label class="u-inline-e925a44577">Анонс для карточки</label>
                    <textarea id="description" name="description" rows="3" placeholder="Одно-два предложения: их видно в списке проектов и в блоке «Проекты» на страницах"><?= htmlspecialchars($project['description'] ?? '', ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Короткий текст без оформления, до 300 знаков. Само содержимое проекта собирается ниже в конструкторе блоков.</span>
                </div>
            </div>

            <!-- Блок 2: Обложка -->
            <div class="form-card u-inline-8a43589152">
                <div class="u-inline-1446175039">
                    <span class="admin-section-icon admin-section-icon--info"><?= \App\Core\AdminUi::icon('media', 22) ?></span>
                    <h3 class="u-inline-eb7fb8da4e">2. Обложка проекта</h3>
                </div>

                <div class="form-grid u-inline-7dde5e56b3">
                    <?= \App\Core\AdminUi::imageField('cover_image_url', $project['cover_image'] ?? '', [
                        'label' => 'Главная обложка проекта',
                        'file' => 'cover_image_file',
                        'hint' => 'Показывается в карточке проекта и в шапке его страницы.',
                    ]) ?>
                </div>

                <p class="form-hint">Фотографии, документы, состав команды, этапы и характеристики проекта
                    собираются ниже в конструкторе блоков — там же, где и содержимое страниц.</p>
            </div>
        </div>

        <!-- Правая колонка настройки публикации -->
        <aside class="entry-side">
            <?= \App\Core\TranslationGroupHelper::renderSidebarMetaBox('projects', $project ?? []) ?>
            <div class="form-card u-inline-c1563b7411">
                <h3 class="u-inline-3e8ce2fc5a">Параметры публикаций</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="status">Статус</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($project['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                            <option value="published" <?= ($project['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="slug">ЧПУ (slug)</label>
                        <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($project['slug'] ?? '', ENT_QUOTES) ?>" placeholder="оставьте пустым для автогенерации">
                        <span class="form-hint">Общий адрес для всех языковых версий.</span>
                    </div>

                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="sort_order">Порядок сортировки</label>
                        <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($project['sort_order'] ?? 0) ?>">
                    </div>

                    <div class="form-field form-field--checkbox u-inline-b1ecc496e0">
                        <input type="checkbox" id="is_featured" name="is_featured" value="1" <?= !empty($project['is_featured']) ? 'checked' : '' ?>>
                        <label class="u-inline-d76ba0dbd2" for="is_featured">Показать на главной</label>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</form>

<?php if ($isEdit): ?>
    <?php
    // Содержимое проекта — те же блоки, что и у страницы: проект это страница
    // с подтипом. Конструктор один и тот же, поэтому подключаем его партиал.
    $page = $project;
    $blockEditorTitle = 'Содержимое проекта';
    require __DIR__ . '/../pages/_block_editor.php';
    ?>
<?php endif; ?>

<div class="form-actions form-actions--sticky">
    <?php if (($project['status'] ?? 'draft') !== 'published'): ?>
        <button type="submit" name="publish_action" value="1" form="project_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('check') ?>Опубликовать</button>
        <button type="submit" form="project_edit_form" class="btn"><?= \App\Core\AdminUi::icon('save') ?>Сохранить черновик</button>
    <?php else: ?>
        <button type="submit" form="project_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить изменения</button>
    <?php endif; ?>
    <a href="/admin/projects" class="btn">Отмена</a>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
