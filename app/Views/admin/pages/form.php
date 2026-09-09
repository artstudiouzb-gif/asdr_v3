<?php

use App\Core\Csrf;
use App\Models\Language;

$isEdit = !empty($page['id']);
$pageTitle = $isEdit ? 'Редактирование страницы' : 'Новая страница';
$activeNav = 'pages';
require __DIR__ . '/../layout/header.php';

/** @var array|null $page */
/** @var array $translations */
/** @var string|null $error */
/** @var array $blocks */
$blocks = $blocks ?? [];
$blockLang = $blockLang ?? Language::defaultCode();

$action = $isEdit ? '/admin/pages/' . (int) $page['id'] . '/edit' : '/admin/pages/create';
$defaultCode = Language::defaultCode();
$languages = Language::active();
?>
<?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($isEdit): ?>
    <div class="u-inline-c9fab0a7e2">
        <a class="btn btn--small" href="/admin/revisions/page/<?= (int) $page['id'] ?>">История версий</a>
        <?php if (\App\Models\Page::isHomePage($page)): ?>
            <span class="badge badge--success u-inline-371a921b3b"><?= \App\Core\AdminUi::icon('home', 14) ?> Эта страница назначена Главной страницей сайта</span>
        <?php else: ?>
            <form class="u-inline-0cd28ce9ba" method="post" action="/admin/pages/<?= (int) $page['id'] ?>/make-home">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--small btn--warning"><?= \App\Core\AdminUi::icon('home', 15) ?> Назначить этой странице статус «Главная»</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
<form method="post" action="<?= $action ?>" id="page_edit_form" data-content-draft="page:<?= $isEdit ? (int) $page['id'] : 'new' ?>" data-record-updated="<?= htmlspecialchars((string) ($page['updated_at'] ?? ''), ENT_QUOTES) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="expected_updated_at" value="<?= htmlspecialchars((string) $page['updated_at'], ENT_QUOTES) ?>">
            <input type="hidden" name="expected_lock_version" value="<?= (int) ($page['lock_version'] ?? 1) ?>">
        <?php endif; ?>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- Блок 1: Основная информация -->
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('1. Основная информация', 'document') ?>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Заголовок страницы <span class="u-inline-9dd1207e58">*</span></label>
                    <input class="u-inline-bc64d2d1e3" type="text" name="title" value="<?= htmlspecialchars($page['title'] ?? '', ENT_QUOTES) ?>" placeholder="Введите название страницы" required>
                </div>

                <div class="form-field">
                    <label class="u-inline-e925a44577">Описание / лид (подзаголовок)</label>
                    <textarea name="lead" rows="2" placeholder="Короткое описание страницы"><?= htmlspecialchars($page['lead'] ?? '', ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Показывается в начале страницы под заголовком (если нет Hero-блока).</span>
                </div>
            </div>

            <!-- Блок 2: SEO Оптимизация -->
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('2. SEO Оптимизация', 'seo', 'var(--admin-success)') ?>

                <div class="form-grid-2col u-inline-001013efee">
                    <div class="form-field">
                        <label class="u-inline-e925a44577">SEO: Meta Title</label>
                        <input type="text" name="meta_title" value="<?= htmlspecialchars($page['meta_title'] ?? '', ENT_QUOTES) ?>" placeholder="SEO Заголовок для поисковиков">
                    </div>
                    <div class="form-field">
                        <label class="u-inline-e925a44577">SEO: Meta Description</label>
                        <textarea name="meta_description" rows="2" placeholder="SEO Краткое описание для поисковиков"><?= htmlspecialchars($page['meta_description'] ?? '', ENT_QUOTES) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Блок 3: Пользовательские и внешние CSS / JS (только супер-админ) -->
            <?php if (\App\Core\Auth::isSuperAdmin()): ?>
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('3. Внешние и пользовательские CSS / JS файлы', 'code', 'var(--admin-primary)') ?>

                <div class="form-field">
                    <label class="u-inline-e925a44577">Внешние CSS файлы или кастомный CSS-код страницы</label>
                    <textarea name="custom_css" rows="3" class="admin-code-input" placeholder="https://cdn.example.com/style.css&#10;body { --page-accent: #007bff; }"><?= htmlspecialchars($page['custom_css'] ?? '', ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Указывайте URL внешних .css файлов (по одному на строку) или кастомный CSS. Стили подключаются строго внешними файлами без инлайн-тегов.</span>
                </div>

                <div class="form-field">
                    <label class="u-inline-e925a44577">Внешние JS скрипты или кастомный JS-код страницы</label>
                    <textarea name="custom_js" rows="3" class="admin-code-input" placeholder="https://cdn.example.com/script.js&#10;console.log('Custom script running');"><?= htmlspecialchars($page['custom_js'] ?? '', ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Указывайте URL внешних .js скриптов (по одному на строку) или кастомный JavaScript. Скрипты подключаются строго внешними ресурсами перед &lt;/body&gt;.</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <aside class="entry-side">
            <?= \App\Core\TranslationGroupHelper::renderSidebarMetaBox('pages', $page ?? []) ?>
            <?php require __DIR__ . '/_settings_sidebar.php'; ?>
        </aside>
    </div>
</form>

<?php if ($isEdit): require __DIR__ . '/_block_editor.php'; endif; ?>

<div class="form-actions form-actions--sticky">
    <div class="form-actions-left">
        <?php $pStatus = $page['status'] ?? 'draft'; ?>
        <span class="badge badge--<?= $pStatus === 'published' ? 'success' : 'draft' ?> u-inline-ec08abfabe">
            <span class="publication-status-dot publication-status-dot--<?= $pStatus === 'published' ? 'published' : 'draft' ?>"></span>
            <?= $pStatus === 'published' ? 'Опубликовано' : 'Черновик' ?>
        </span>
        <span class="u-inline-c1de996030">
            <?= \App\Core\AdminUi::icon('globe', 14) ?>
            Язык контента: <strong><?= strtoupper(htmlspecialchars($blockLang, ENT_QUOTES)) ?></strong>
        </span>
    </div>

    <div class="form-actions-right">
        <?php if ($pStatus !== 'published'): ?>
            <button type="submit" name="publish_action" value="1" form="page_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('check') ?>Опубликовать</button>
            <button type="submit" form="page_edit_form" class="btn"><?= \App\Core\AdminUi::icon('save') ?>Сохранить черновик</button>
        <?php else: ?>
            <button type="submit" form="page_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить изменения</button>
        <?php endif; ?>
        <a href="/admin/pages" class="btn">Отмена</a>
        <?php if ($isEdit): ?>
            <a href="/admin/pages/<?= (int) $page['id'] ?>/preview?block_lang=<?= urlencode($blockLang) ?>"
               class="btn" target="_blank" rel="noopener">Предпросмотр ↗</a>
            <?php
            // Предпросмотр показывает и черновик; публичный адрес есть только
            // у опубликованной страницы. Главная живёт на «/», у остальных —
            // slug с языковым префиксом самой страницы.
            $publicPath = '';
            if ($pStatus === 'published') {
                $prefix = \App\Core\Locale::prefix((string) ($page['lang'] ?? ''));
                if (!empty($page['is_home'])) {
                    $publicPath = $prefix === '' ? '/' : $prefix;
                } elseif ((string) ($page['slug'] ?? '') !== '') {
                    $publicPath = $prefix . '/' . $page['slug'];
                }
            }
            ?>
            <?php if ($publicPath !== ''): ?>
                <a href="<?= htmlspecialchars($publicPath, ENT_QUOTES) ?>" class="btn" target="_blank" rel="noopener">
                    <?= \App\Core\AdminUi::icon('external-link', 14) ?>
                    Открыть на сайте ↗
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
