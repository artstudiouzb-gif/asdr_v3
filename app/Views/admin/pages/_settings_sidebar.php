<?php

/** @var array|null $page */
/** @var array $languages */
/** @var bool $isEdit */
/** @var string $blockLang */
/** @var array $parentOptions */
$parentOptions = $parentOptions ?? [];
$selectedParentId = (int) ($page['parent_id'] ?? 0);
$isHomePage = \App\Models\Page::isHomePage($page ?? []);
?>
<div class="form-card page-settings-sidebar u-inline-c1563b7411">
    <h3 class="u-inline-3e8ce2fc5a">Параметры страницы</h3>

    <div class="form-grid">
        <div class="form-field">
            <label class="u-inline-0b87e9e0af" for="status">Статус</label>
            <select id="status" name="status">
                <option value="draft" <?= ($page['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
            </select>
        </div>

        <div class="form-field">
            <label class="u-inline-0b87e9e0af" for="slug">ЧПУ (slug)</label>
            <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($page['slug'] ?? '', ENT_QUOTES) ?>" placeholder="оставьте пустым для автогенерации">
            <span class="form-hint">Адрес страницы на сайте.</span>
        </div>

        <div class="form-field">
            <label class="u-inline-0b87e9e0af" for="parent_id">Родительская страница</label>
            <select id="parent_id" name="parent_id" <?= $isHomePage ? 'disabled' : '' ?>>
                <option value="">Нет — верхний уровень</option>
                <?php foreach ($parentOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= $selectedParentId === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $option['label'], ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="form-hint"><?= $isHomePage
                ? 'Главная страница всегда находится на верхнем уровне.'
                : 'Используется для структуры и хлебных крошек. URL останется коротким: /' . htmlspecialchars((string) ($page['slug'] ?? 'slug'), ENT_QUOTES) ?></span>
        </div>

        <div class="form-field">
            <label class="u-inline-0b87e9e0af" for="layout_type">Макет страницы</label>
            <select id="layout_type" name="layout_type">
                <option value="no_sidebar" <?= ($page['layout_type'] ?? 'no_sidebar') === 'no_sidebar' ? 'selected' : '' ?>>Без сайдбара</option>
                <option value="left_sidebar" <?= ($page['layout_type'] ?? '') === 'left_sidebar' ? 'selected' : '' ?>>Левый сайдбар</option>
                <option value="right_sidebar" <?= ($page['layout_type'] ?? '') === 'right_sidebar' ? 'selected' : '' ?>>Правый сайдбар</option>
            </select>
            <span class="form-hint">Содержимое сайдбара из раздела «Виджеты».</span>
        </div>

        <div class="form-field">
            <label class="u-inline-0b87e9e0af" for="section">Шапка раздела</label>
            <select id="section" name="section">
                <option value="">— обычная страница —</option>
                <?php foreach (\App\Models\Page::SECTIONS as $sectionKey => $sectionLabel): ?>
                    <option value="<?= htmlspecialchars($sectionKey, ENT_QUOTES) ?>" <?= (string) ($page['section'] ?? '') === $sectionKey ? 'selected' : '' ?>><?= htmlspecialchars($sectionLabel, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="form-hint">Страница отдаёт разделу заголовок, лид и SEO, а её блоки выводятся под списком. Работает как «Сделать главной»: назначить достаточно один раз, языковые версии подхватятся сами, а собственный адрес страницы начнёт вести на адрес раздела.</span>
        </div>

        <div class="page-settings-sidebar__options u-inline-d498835cbd">
            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="is_home" name="is_home" value="1" <?= $isHomePage ? 'checked' : '' ?>>
                <label class="u-inline-d76ba0dbd2" for="is_home">Сделать главной страницей</label>
            </div>

            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="hide_chrome" name="hide_chrome" value="1" <?= !empty($page['hide_chrome']) ? 'checked' : '' ?>>
                <label class="u-inline-d76ba0dbd2" for="hide_chrome">Скрыть шапку и футер</label>
            </div>

            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="transparent_header" name="transparent_header" value="1" <?= !empty($page['transparent_header']) ? 'checked' : '' ?>>
                <label class="u-inline-d76ba0dbd2" for="transparent_header">Прозрачная шапка</label>
            </div>
            <p class="form-hint u-inline-1da9facb4d">Для прозрачной шапки нужен hero первым блоком.</p>
        </div>
    </div>
</div>
